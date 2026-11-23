<?php
/**
 * Delivery: order events exactly once through Action Scheduler; behavioural events at the end of
 * the request that made them.
 *
 * - Order events: requests only enqueue; HTTP calls run in the background (Action Scheduler / cron).
 * - Each order event is built once and frozen on the order (identity, payload, UUID, timestamp),
 *   so every retry sends a byte-identical event that PostHog collapses with any earlier copy.
 * - One pending action per event: the action's arguments name the event (kind, order, refund),
 *   the attempt count lives in the event's state. A delivered state is never overwritten.
 * - Failures back off over about two days; an hourly sweep re-queues paid orders that were never
 *   queued or whose action was lost, refunds and cancellations of delivered orders, and orders
 *   PostHog refused for a project key that has since been changed.
 * - Behavioural events (checkout, cart, coupons, payment failures) are sent in one batch once the
 *   request's response has gone to the shopper: nothing is written to the database. Only when
 *   PostHog cannot be reached are they kept (payload in a transient) and retried by Action Scheduler;
 *   for five minutes after such a failure, events are kept at once instead of waiting on PostHog in
 *   every request. Outside a shopper's request (cron, CLI, admin) there is no response to hand over
 *   first, so they are sent at once.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Queue {

	public const GROUP      = 'w99-phwc';
	public const HOOK       = 'w99_phwc_send';
	public const HOOK_EVENT = 'w99_phwc_send_event';
	public const HOOK_SWEEP = 'w99_phwc_sweep';

	public const KIND_COMPLETED = 'order_completed';
	public const KIND_REFUND    = 'order_refunded';
	public const KIND_CANCELLED = 'order_cancelled';
	public const KIND_FAILED    = 'payment_failed';

	/** Seconds before retry n (1-based): about two days in total. */
	private const BACKOFF = array( 60, 300, 1800, 7200, 21600, 43200, 86400 );

	/** HTTP answers that depend on the project key: worth retrying once the key has changed. */
	private const AUTH_FAILURES = array( 401, 403 );

	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle' ), 10, 4 );
		add_action( self::HOOK_EVENT, array( $this, 'handle_event' ), 10, 2 );
		add_action( self::HOOK_SWEEP, array( $this, 'sweep' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule_sweep' ) );

		// Tasks scheduled by 0.2.x before an upgrade: route them into this queue instead of failing.
		add_action( 'w99_phwc_send_order', array( $this, 'legacy_order' ) );
		add_action( 'w99_phwc_send_refund', array( $this, 'legacy_refund' ) );
		add_action( 'w99_phwc_send_identify', '__return_null' ); // 0.3 never identifies by e-mail.
		add_action( 'w99_phwc_send_message', array( $this, 'legacy_message' ), 10, 2 );
	}

	public function schedule_sweep(): void {
		try {
			if ( Settings::active() && function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_SWEEP, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::HOOK_SWEEP, array(), self::GROUP, true );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Scheduling the sweep failed: ' . $e->getMessage() );
		}
	}

	/** Queue one order event unless an action for it is already pending or running. */
	public function enqueue( string $kind, int $order_id, int $sub_id = 0, int $delay = 0 ): void {
		try {
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				$this->handle( $kind, $order_id, $sub_id );
				return;
			}
			$args = array( $kind, $order_id, $sub_id );
			// Exact arguments (Action Scheduler's own `unique` flag ignored arguments before 4.0 and
			// refused a second order paid while one was queued).
			if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				self::schedule( $args, $delay );
			}
			if ( self::KIND_COMPLETED === $kind ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof \WC_Order && array() === Orders::state( $order, Orders::META_COMPLETED ) ) {
					self::save_state( $order, Orders::META_COMPLETED, array( 'status' => 'pending', 'due_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + $delay ) ) );
				}
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Queueing ' . $kind . ' for order ' . $order_id . ' failed: ' . $e->getMessage() );
		}
	}

	private static function schedule( array $args, int $delay ): void {
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
		}
	}

	/** Payment failures: one event per transition into failed/cancelled while unpaid. */
	public function enqueue_payment_failed( \WC_Order $order, string $status ): void {
		$log        = Orders::state( $order, Orders::META_FAILED );
		$events     = isset( $log['events'] ) && is_array( $log['events'] ) ? $log['events'] : array();
		$occurrence = count( $events ) + 1;
		$identity   = Orders::identity( $order );
		if ( empty( $identity['linked'] ) && ! Consent::allows_linking( (string) ( $identity['consent'] ?? '' ) ) && 'anonymous' !== Settings::get( 'unconsented_orders' ) ) {
			return;
		}
		$event         = Events::payment_failed( $order, $status, $occurrence, Util::utc(), $identity, Orders::capture_context( $order ) );
		$events[]      = array( 'status' => $status, 'uuid' => $event['uuid'], 'at' => $event['timestamp'] );
		$log['events'] = $events;
		self::save_state( $order, Orders::META_FAILED, $log );
		$this->enqueue_event( $event );
	}

	/** @var array<int, array<string, mixed>> Behavioural events of this request, sent at its end. */
	private static $pending = array();

	/** @var bool The end-of-request send is hooked. */
	private static $hooked = false;

	/** @var bool The end-of-request send has run: later events go out at once. */
	private static $flushed = false;

	/** Set for a few minutes after PostHog could not be reached: events are kept for Action Scheduler at once. */
	public const DOWN = 'w99_phwc_posthog_down';

	/** Behavioural or one-off event: sent once this request's response has gone out (see flush_events()). */
	public function enqueue_event( array $event ): void {
		self::$pending[] = $event;
		if ( self::$flushed || ! Orders::is_buyer_request() ) {
			self::flush_events(); // Made by a later shutdown callback, or no shopper waits for this request.
			return;
		}
		if ( ! self::$hooked ) {
			self::$hooked = true;
			// After WooCommerce saved the session (shutdown, 20) and after other plugins' shutdown work.
			add_action( 'shutdown', array( self::class, 'flush_at_shutdown' ), PHP_INT_MAX - 100 );
		}
	}

	/** End of the request: hand the response to the shopper first, then send. */
	public static function flush_at_shutdown(): void {
		self::$flushed = true;
		if ( array() === self::$pending ) {
			return;
		}
		if ( apply_filters( 'w99_phwc_finish_request_first', true ) ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
		}
		self::flush_events();
	}

	/**
	 * Send this request's behavioural events in one batch, with one quick retry. Only when PostHog
	 * cannot be reached are they written to the database, for Action Scheduler to retry.
	 */
	public static function flush_events(): void {
		$events        = self::$pending;
		self::$pending = array();
		if ( array() === $events ) {
			return;
		}
		try {
			if ( 'test' !== Settings::mode() && false !== get_transient( self::DOWN ) ) {
				foreach ( $events as $event ) {
					self::persist( $event );
				}
				return;
			}
			$what   = implode( ', ', array_unique( array_column( $events, 'event' ) ) );
			$result = Client::send( $events, $what, 4 );
			if ( ! $result['ok'] && $result['retry'] ) {
				$result = Client::send( $events, $what, 4 );
			}
			if ( ! $result['ok'] && $result['retry'] ) {
				set_transient( self::DOWN, gmdate( 'c' ), 5 * MINUTE_IN_SECONDS );
				foreach ( $events as $event ) {
					self::persist( $event );
				}
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Sending events failed: ' . $e->getMessage() );
		}
	}

	/** PostHog unreachable: keep the event (payload in a transient) for Action Scheduler's retries. */
	private static function persist( array $event ): void {
		$id = $event['uuid'];
		set_transient( 'w99_phwc_evt_' . $id, $event, DAY_IN_SECONDS );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_EVENT, array( $id, 1 ), self::GROUP ) ) {
				as_schedule_single_action( time() + self::BACKOFF[0], self::HOOK_EVENT, array( $id, 1 ), self::GROUP );
			}
		}
	}

	public function handle_event( $id, $attempt = 1 ): void {
		try {
			$event = get_transient( 'w99_phwc_evt_' . $id );
			if ( ! is_array( $event ) || ! Settings::can_send() ) {
				return;
			}
			$result = Client::send( array( $event ), $event['event'] );
			if ( $result['ok'] ) {
				delete_transient( 'w99_phwc_evt_' . $id );
				return;
			}
			if ( $result['retry'] && (int) $attempt < 4 ) {
				as_schedule_single_action( time() + self::BACKOFF[ (int) $attempt ], self::HOOK_EVENT, array( $id, (int) $attempt + 1 ), self::GROUP );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Sending event ' . $id . ' failed: ' . $e->getMessage() );
		}
	}

	/** Action Scheduler callback for order events ($attempt: actions queued by earlier 0.3 builds). */
	public function handle( $kind, $order_id, $sub_id = 0, $attempt = null ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! Settings::can_send() ) {
				return;
			}
			switch ( $kind ) {
				case self::KIND_COMPLETED:
					$this->send_completed( $order );
					break;
				case self::KIND_REFUND:
					$this->send_refund( $order, (int) $sub_id );
					break;
				case self::KIND_CANCELLED:
					$this->send_cancelled( $order );
					break;
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Sending ' . $kind . ' for order ' . (int) $order_id . ' failed: ' . $e->getMessage() );
		}
	}

	private function send_completed( \WC_Order $order ): void {
		if ( Orders::completed_done( $order ) || ! Orders::should_track( $order ) ) {
			// Not paid right now (e.g. held for review): nothing final; the next paid transition re-queues it.
			return;
		}
		$state = Orders::state( $order, Orders::META_COMPLETED );
		$event = $state['event'] ?? null;
		if ( ! is_array( $event ) ) {
			// Frozen first, then the identity read fresh from the database: a link after consent (Visitor) that
			// came before this point counts, and one after it is refused.
			self::save_state( $order, Orders::META_COMPLETED, array_merge( $state, array( 'status' => 'sending' ) ) );
			$identity = Orders::fresh_identity( $order ) ?? Orders::identity( $order );
			// Without consent the order is not sent (unless the store counts such orders anonymously).
			// With consent but no PostHog identity (blocker, script failed) it is still counted, unlinked.
			if ( empty( $identity['linked'] ) && ! Consent::allows_linking( (string) ( $identity['consent'] ?? '' ) ) && 'anonymous' !== Settings::get( 'unconsented_orders' ) ) {
				self::save_state( $order, Orders::META_COMPLETED, array( 'status' => 'skipped', 'reason' => $identity['reason'] ?? 'unlinked', 'consent' => $identity['consent'] ?? null ) );
				return;
			}
			$event = (array) apply_filters( 'w99_phwc_order_completed_event', Events::order_completed( $order, $identity, Orders::capture_context( $order ) ), $order );
			self::save_state( $order, Orders::META_COMPLETED, array_merge( $state, array( 'status' => 'sending', 'event' => $event ) ) );
		}
		$this->deliver( $order, Orders::META_COMPLETED, $event, self::KIND_COMPLETED, 0 );
	}

	private function send_refund( \WC_Order $order, int $refund_id ): void {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund || ! Settings::on( 'ev_refund' ) ) {
			return;
		}
		$state = Orders::state( $refund, Orders::META_REFUND );
		if ( in_array( $state['status'] ?? '', array( 'sent', 'logged' ), true ) || ( array() === $state && '' !== (string) $refund->get_meta( Orders::META_LEGACY_SENT, true ) ) ) {
			return; // Delivered, here or by 0.2.x.
		}
		$purchase = Orders::delivered_purchase( $order );
		if ( null === $purchase ) {
			return; // Queued again once the purchase is delivered.
		}
		$event = $state['event'] ?? null;
		if ( ! is_array( $event ) ) {
			$event = Events::order_refunded( $order, $refund, self::identity_of( $purchase ), Orders::capture_context( $order ), self::reversed_by_cancellation( $order, $refund_id ) );
		}
		$this->deliver( $refund, Orders::META_REFUND, $event, self::KIND_REFUND, $refund_id, array(), $order );
	}

	/**
	 * A refund made after the cancellation was built: the cancellation already reversed the whole
	 * remaining amount, this refund included, so its own revenue must not reverse it again.
	 */
	private static function reversed_by_cancellation( \WC_Order $order, int $refund_id ): bool {
		$cancel = Orders::state( $order, Orders::META_CANCELLED );
		return is_array( $cancel['event'] ?? null ) && ! in_array( $refund_id, array_map( 'intval', (array) ( $cancel['refunds_counted'] ?? array() ) ), true );
	}

	private function send_cancelled( \WC_Order $order ): void {
		if ( ! Settings::on( 'ev_cancelled' ) ) {
			return;
		}
		$state = Orders::state( $order, Orders::META_CANCELLED );
		if ( in_array( $state['status'] ?? '', array( 'sent', 'logged', 'skipped' ), true ) ) {
			return;
		}
		if ( 'cancelled' !== $order->get_status() ) {
			// Reinstated before this ran: nothing to reverse. A later cancellation records itself again.
			$order->delete_meta_data( Orders::META_CANCELLED );
			$order->save_meta_data();
			return;
		}
		$purchase = Orders::delivered_purchase( $order );
		if ( null === $purchase ) {
			return;
		}
		$event = $state['event'] ?? null;
		$extra = array();
		if ( ! is_array( $event ) ) {
			$remaining = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
			if ( $remaining < 0.005 ) {
				self::save_state( $order, Orders::META_CANCELLED, array( 'status' => 'skipped', 'reason' => 'fully_refunded' ) );
				return;
			}
			$at    = isset( $state['at'] ) && is_string( $state['at'] ) ? $state['at'] : Util::utc( $order->get_date_modified() );
			$event = Events::order_cancelled( $order, $remaining, $at, self::identity_of( $purchase ), Orders::capture_context( $order ) );
			$extra = array(
				'at'              => $at,
				'refunds_counted' => array_map( static fn( $r ) => (int) $r->get_id(), $order->get_refunds() ),
			);
		}
		$this->deliver( $order, Orders::META_CANCELLED, $event, self::KIND_CANCELLED, 0, $extra );
	}

	/** After a purchase is delivered, queue what depends on it: refunds and a cancellation that happened meanwhile. */
	private function queue_dependents( \WC_Order $order ): void {
		$key = self::key_tag();
		foreach ( $order->get_refunds() as $refund ) {
			$state = Orders::state( $refund, Orders::META_REFUND );
			if ( in_array( $state['status'] ?? '', array( 'sent', 'logged' ), true ) || ( array() === $state && '' !== (string) $refund->get_meta( Orders::META_LEGACY_SENT, true ) ) ) {
				continue;
			}
			if ( self::retryable( $state, $key, $refund, Orders::META_REFUND ) ) {
				$this->enqueue( self::KIND_REFUND, $order->get_id(), (int) $refund->get_id() );
			}
		}
		if ( 'cancelled' === $order->get_status() ) {
			$state = Orders::state( $order, Orders::META_CANCELLED );
			if ( in_array( $state['status'] ?? '', array( 'sent', 'logged', 'skipped' ), true ) ) {
				return;
			}
			if ( array() === $state ) {
				// Cancelled while nothing recorded it (before an upgrade, or a missed hook).
				self::save_state( $order, Orders::META_CANCELLED, array( 'status' => 'pending', 'at' => Util::utc( $order->get_date_modified() ) ) );
			} elseif ( ! self::retryable( $state, $key, $order, Orders::META_CANCELLED ) ) {
				return;
			}
			$this->enqueue( self::KIND_CANCELLED, $order->get_id() );
		}
	}

	/**
	 * Whether an undelivered event should be queued again: not after a permanent failure, except
	 * one PostHog gave for a project key that has since been changed (its attempts restart).
	 *
	 * @param \WC_Order|\WC_Order_Refund $object Holds the state.
	 */
	private static function retryable( array $state, string $key, $object, string $meta ): bool {
		if ( 'failed' !== ( $state['status'] ?? '' ) ) {
			return true;
		}
		if ( in_array( (int) ( $state['http'] ?? 0 ), self::AUTH_FAILURES, true ) && ( $state['key'] ?? '' ) !== $key ) {
			$state['status']   = 'retrying';
			$state['attempts'] = 0;
			self::save_state( $object, $meta, $state );
			return true;
		}
		return false;
	}

	/**
	 * Send and record the outcome; schedule a retry when useful.
	 *
	 * @param \WC_Order|\WC_Order_Refund $object Holds the delivery state.
	 * @param array<string, mixed>        $extra  Kept in the state with the event.
	 */
	private function deliver( $object, string $meta, array $event, string $kind, int $sub_id, array $extra = array(), ?\WC_Order $parent = null ): void {
		$order    = $parent ?? $object;
		$previous = Orders::state( $object, $meta );
		$attempt  = (int) ( $previous['attempts'] ?? 0 ) + 1;
		$result   = Client::send( array( $event ), $event['event'] . ' for order ' . $order->get_id() );
		$state    = array_merge(
			$previous,
			$extra,
			array(
				'event'       => $event,
				'uuid'        => $event['uuid'],
				'distinct_id' => $event['distinct_id'],
				'source'      => $event['properties']['identity_source'] ?? null,
				'attempts'    => $attempt,
			)
		);
		unset( $state['last_error'], $state['http'], $state['key'] );
		if ( $result['ok'] ) {
			$state['status']  = 'test' === Settings::mode() ? 'logged' : 'sent';
			$state['sent_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			if ( 'sent' === $state['status'] ) {
				$object->update_meta_data( Orders::META_LEGACY_SENT, gmdate( 'c' ) ); // A rollback to 0.2.x will not send it again.
			}
			self::save_state( $object, $meta, $state );
			do_action( 'w99_phwc_event_sent', $event, $order );
			if ( self::KIND_COMPLETED === $kind ) {
				$this->queue_dependents( $order );
			}
			return;
		}
		// Another run may have delivered it meanwhile (an action queued by an older build, a manual run).
		$fresh = self::fresh_state( $object, $meta );
		if ( in_array( $fresh['status'] ?? '', array( 'sent', 'logged', 'skipped' ), true ) ) {
			return;
		}
		$state['last_error'] = $result['error'];
		$state['http']       = $result['status'];
		$state['key']        = self::key_tag();
		if ( $result['retry'] && $attempt <= count( self::BACKOFF ) ) {
			$state['status'] = 'retrying';
			self::save_state( $object, $meta, $state );
			// Scheduled directly: the running action still matches the arguments.
			self::schedule( array( $kind, $order->get_id(), $sub_id ), (int) apply_filters( 'w99_phwc_retry_delay', self::BACKOFF[ $attempt - 1 ], $attempt, $kind ) );
		} else {
			$state['status'] = 'failed';
			self::save_state( $object, $meta, $state );
		}
	}

	/** @param \WC_Order|\WC_Order_Refund $object */
	private static function fresh_state( $object, string $meta ): array {
		$fresh = wc_get_order( $object->get_id() );
		if ( ! $fresh ) {
			return array();
		}
		$fresh->read_meta_data( true );
		return Orders::state( $fresh, $meta );
	}

	/** First characters of the project key, to tell whether a refusal was for the key in use now. */
	private static function key_tag(): string {
		return Settings::key_tag();
	}

	/** Recover what hooks missed in the last three days. */
	public function sweep(): void {
		try {
			if ( ! Settings::can_send() ) {
				return;
			}
			$since = time() - 3 * DAY_IN_SECONDS;
			$key   = self::key_tag();
			for ( $page = 1; $page <= 20; $page++ ) {
				// Any status: paid statuses other plugins add are found by their payment date.
				$ids = wc_get_orders(
					array(
						'date_paid' => '>' . $since,
						'limit'     => 100,
						'page'      => $page,
						'return'    => 'ids',
						'type'      => 'shop_order',
					)
				);
				foreach ( $ids as $id ) {
					$order = wc_get_order( $id );
					if ( $order instanceof \WC_Order ) {
						$this->sweep_order( $order, $key );
					}
				}
				if ( count( $ids ) < 100 ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Sweep failed: ' . $e->getMessage() );
		}
	}

	private function sweep_order( \WC_Order $order, string $key ): void {
		if ( null !== Orders::delivered_purchase( $order ) ) {
			$this->queue_dependents( $order );
			return;
		}
		$state  = Orders::state( $order, Orders::META_COMPLETED );
		$status = $state['status'] ?? null;
		if ( Orders::completed_done( $order ) || ! Orders::should_track( $order ) || ! Settings::on( 'ev_order' ) ) {
			return;
		}
		if ( 'failed' === $status ) {
			if ( self::retryable( $state, $key, $order, Orders::META_COMPLETED ) ) {
				$this->enqueue( self::KIND_COMPLETED, $order->get_id() );
			}
			return;
		}
		$paid = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
		$wait = Settings::delay_seconds();
		if ( null === $status ) {
			$this->enqueue( self::KIND_COMPLETED, $order->get_id(), 0, max( 0, $wait - ( time() - $paid ) ) );
		} elseif ( time() - $paid > $wait + 600 ) {
			$this->enqueue( self::KIND_COMPLETED, $order->get_id() ); // Lost action: no-op while one is pending or running.
		}
	}

	public function legacy_order( $order_id ): void {
		$this->enqueue( self::KIND_COMPLETED, (int) $order_id );
	}

	public function legacy_refund( $refund_id ): void {
		try {
			$refund = wc_get_order( (int) $refund_id );
			if ( $refund instanceof \WC_Order_Refund ) {
				$this->enqueue( self::KIND_REFUND, (int) $refund->get_parent_id(), (int) $refund_id );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', '0.2.x refund task failed: ' . $e->getMessage() );
		}
	}

	public function legacy_message( $message, $attempt = 0 ): void {
		try {
			if ( is_array( $message ) && ! empty( $message['event'] ) && Settings::can_send() ) {
				Client::send( array( $message ), (string) $message['event'] );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', '0.2.x message task failed: ' . $e->getMessage() );
		}
	}

	/** Same person and person mode as the delivered purchase; no session, flags or snapshot (a refund happens later, elsewhere). */
	private static function identity_of( array $completed ): array {
		$e = $completed['event'];
		$p = $e['properties'] ?? array();
		return array(
			'distinct_id'    => $e['distinct_id'],
			'source'         => $p['identity_source'] ?? 'none',
			'reason'         => $p['identity_reason'] ?? null,
			'consent'        => $p['consent'] ?? 'unknown',
			'linked'         => 'none' !== ( $p['identity_source'] ?? 'none' ),
			'process_person' => ! ( array_key_exists( '$process_person_profile', $p ) && false === $p['$process_person_profile'] ),
			'device_id'      => $p['$device_id'] ?? null,
			'session_id'     => null,
			'flags'          => array(),
			'props'          => array(),
			'captured_at'    => null,
		);
	}

	public static function save_state( $object, string $meta, array $state ): void {
		$state['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$object->update_meta_data( $meta, wp_json_encode( $state ) );
		$object->save_meta_data();
	}
}
