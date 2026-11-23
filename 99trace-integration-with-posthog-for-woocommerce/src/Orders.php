<?php
/**
 * Order lifecycle → PostHog.
 *
 * 1. Checkout: while the buyer is still in the browser that placed the order, read who they are
 *    in PostHog (envelope from our browser module, else posthog-js's cookie, else the shop's
 *    visitor-ID cookie), their consent, the request's IP and user agent, and freeze it on the order.
 *    An order placed before the visitor could be recorded is linked when they accept on its
 *    thank-you page, as long as its purchase has not been built (link_later()).
 * 2. Paid: WooCommerce marks the order paid (gateway webhook, return request, manual status) →
 *    `Order Completed` is queued, sent after the upsell delay, exactly once.
 * 3. Later: refunds, cancellation of a paid order, failed or abandoned payments of unpaid orders.
 *
 * The buyer's browser is never needed after step 1, so bank-app returns in another browser,
 * thank-you pages never opened, consent banners on the thank-you page and reloads change nothing.
 * Every hook here runs inside WooCommerce's checkout, payment and status code: a failure is
 * logged and swallowed, never passed on to the shop (the hourly sweep recovers missed work).
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Orders {

	/**
	 * Distinct ID prefix of an order counted without a visitor (no consent, no PostHog ID). Kept from 99Hog:
	 * a refund sent after the rename must land on the same ID as the purchase sent before it.
	 */
	public const ANONYMOUS_PREFIX = '99hog:order:';

	public const META_CAPTURE   = '_99w_ph_capture';
	public const META_IDENTITY  = '_99w_ph_identity';
	public const META_COMPLETED = '_99w_ph_order_completed';
	public const META_CANCELLED = '_99w_ph_order_cancelled';
	public const META_REFUND    = '_99w_ph_refund';
	public const META_FAILED    = '_99w_ph_payment_failed';
	// 0.2.x fields: read for continuity; `_sent_at` is also written so a rollback to 0.2.x never sends twice.
	public const META_LEGACY_VISITOR = '_99w_ph_distinct_id';
	public const META_LEGACY_DEVICE  = '_99w_ph_device_id';
	public const META_LEGACY_SESSION = '_99w_ph_session_id';
	public const META_LEGACY_COOKIE  = '_99w_ph_cookie_found';
	public const META_LEGACY_SENT    = '_99w_ph_sent_at';

	public const FIELD     = '_w99_ph_identity';
	public const STORE_API = 'w99-phwc';

	/** Statuses in which an order with a payment date is still not a purchase to count now. */
	private const NOT_PAID_STATUSES = array( 'pending', 'failed', 'cancelled', 'on-hold', 'checkout-draft', 'refunded', 'trash' );

	/** @var Queue */
	private $queue;

	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'capture_store_api' ), 10, 2 );
		add_action( 'woocommerce_new_order', array( $this, 'capture_other' ), 20, 2 );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}

		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );
	}

	/* ----------------------------------------------------------------- capture */

	public function capture_classic( $order, $data = array() ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verified its checkout nonce before creating the order; JSON from posthog-js, validated field by field in Identity::from_browser().
		$raw = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null;
		if ( $order instanceof \WC_Order ) {
			$this->capture( $order, is_string( $raw ) ? $raw : null );
		}
	}

	public function capture_store_api( $order, $request = null ): void {
		try {
			$ext = $request instanceof \WP_REST_Request ? (array) $request->get_param( 'extensions' ) : array();
			$raw = $ext[ self::STORE_API ]['identity'] ?? null;
		} catch ( \Throwable $e ) {
			$raw = null;
		}
		if ( $order instanceof \WC_Order ) {
			$this->capture( $order, is_string( $raw ) ? $raw : null );
		}
	}

	/** Orders created on the storefront by code that bypasses both checkouts (funnel and upsell plugins). */
	public function capture_other( $order_id, $order = null ): void {
		try {
			if ( ! Settings::active() ) {
				return;
			}
			$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || $order->meta_exists( self::META_IDENTITY ) ) {
				return;
			}
			// An order created from another order (a separate upsell order) belongs to the same buyer.
			$parent = $order->get_parent_id() ? wc_get_order( $order->get_parent_id() ) : null;
			if ( $parent instanceof \WC_Order && $parent->meta_exists( self::META_IDENTITY ) ) {
				$identity           = self::identity( $parent );
				$identity['source'] = Identity::SOURCE_PARENT;
				$order->update_meta_data( self::META_IDENTITY, wp_json_encode( $identity ) );
				$order->update_meta_data( self::META_CAPTURE, $parent->get_meta( self::META_CAPTURE, true ) );
				$order->save_meta_data();
				return;
			}
			if ( ! self::is_buyer_request() || in_array( $order->get_created_via(), array( 'admin', 'rest-api', 'subscription' ), true ) ) {
				return;
			}
			$this->capture( $order, null );
			$order->save_meta_data();
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Identity capture for new order ' . (int) $order_id . ' failed: ' . $e->getMessage() );
		}
	}

	public function register_store_api(): void {
		try {
			if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
					'namespace'       => self::STORE_API,
					'schema_callback' => static fn() => array(
						'identity' => array(
							'description' => __( 'PostHog identity of the visitor placing the order (99Trace).', '99trace-integration-with-posthog-for-woocommerce' ),
							'type'        => array( 'string', 'null' ),
							'context'     => array(),
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Store API registration failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Read and freeze the buyer's identity on the order (saved with the order by the caller).
	 * A later capture (the checkout submission after a draft order, a payment retry) may refresh it
	 * but never weakens it: see may_replace().
	 */
	private function capture( \WC_Order $order, ?string $browser_raw ): void {
		if ( ! Settings::active() || ! self::is_buyer_request() ) {
			return;
		}
		try {
			$now      = time();
			$cookies  = Util::request_cookies();
			$ckey     = Settings::cookie_key();
			$cookie   = '' !== $ckey ? Identity::from_cookie( Util::cookie( $cookies, Identity::cookie_name( $ckey ) ), $now ) : null;
			$browser  = ( null !== $browser_raw && '' !== $browser_raw ) ? Identity::from_browser( $browser_raw, Settings::token() ) : null;
			$shop     = Visitor::from_cookie();
			// Evidence of an earlier consent: posthog-js's cookie, or the envelope of a posthog-js that is capturing.
			// A switched-off posthog-js (before the answer) and the shop's own visitor cookie are no such evidence.
			$evidence = null !== $cookie || ( null !== $browser && false !== ( $browser['capturing'] ?? null ) );
			$consent  = Consent::evaluate( Settings::consent_mode(), $cookies, $ckey, $evidence, function_exists( 'wp_has_consent' ) ? 'wp_has_consent' : null );
			$user_id  = (int) $order->get_customer_id();
			$customer = ( $user_id && Settings::on( 'identify_customers' ) && get_current_user_id() === $user_id ) ? Settings::customer_distinct_id( $user_id ) : null;
			$fallback = self::ANONYMOUS_PREFIX . Uuid::event( Settings::store(), 'order', array( (string) $order->get_id() ?: wp_generate_uuid4() ) );

			$identity = Identity::resolve( $browser, $cookie, $customer, $consent, $fallback, $shop );
			if ( empty( $identity['linked'] ) && null === $shop && null === $browser && Consent::allows_new_id( $consent ) ) {
				// No ID anywhere yet and no browser module ran (no script, or it was blocked): one for this buyer from now on.
				$shop = Visitor::mint();
				if ( null !== $shop ) {
					$identity = Identity::resolve( $browser, $cookie, $customer, $consent, $fallback, $shop );
				}
			}
			// The server's clock dates the capture: it decides whether the checkout session is still
			// running later, and a phone's clock can be hours off.
			$identity['captured_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $now );

			$previous = self::stored_identity( $order );
			if ( null !== $previous && ! self::may_replace( $previous, $identity ) ) {
				Plugin::log( 'debug', 'Order ' . $order->get_id() . ' keeps the identity frozen at checkout (' . $previous['source'] . '); later capture was ' . ( $identity['linked'] ? $identity['source'] : 'unlinked: ' . $identity['reason'] ) );
				return;
			}

			$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore
			$capture = array(
				'captured_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
				'consent'      => $consent,
				'consent_mode' => Settings::consent_mode(),
				'browser'      => null !== $browser,
				'cookie'       => null !== $cookie,
				'shop_cookie'  => null !== $shop,
				'customer'     => $customer,
				'ip'           => Util::public_ip( (string) ( $order->get_customer_ip_address() ?: ( class_exists( 'WC_Geolocation' ) ? \WC_Geolocation::get_ip_address() : '' ) ) ),
				'user_agent'   => substr( (string) ( $order->get_customer_user_agent() ?: ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ) ), 0, 500 ),
				'current_url'  => Util::strip_url( $referer, Settings::strip_params() ),
				'cookie_key'   => '' !== $ckey ? substr( $ckey, 0, 12 ) : '',
			);
			$order->update_meta_data( self::META_IDENTITY, wp_json_encode( $identity ) );
			$order->update_meta_data( self::META_CAPTURE, wp_json_encode( $capture ) );
			// 0.2.x fields.
			$order->update_meta_data( self::META_LEGACY_COOKIE, $identity['linked'] ? 'yes' : 'no' );
			if ( $identity['linked'] ) {
				$order->update_meta_data( self::META_LEGACY_VISITOR, $identity['distinct_id'] );
				if ( ! empty( $identity['device_id'] ) ) {
					$order->update_meta_data( self::META_LEGACY_DEVICE, $identity['device_id'] );
				}
				if ( ! empty( $identity['session_id'] ) ) {
					$order->update_meta_data( self::META_LEGACY_SESSION, $identity['session_id'] );
				}
			}
			Plugin::log( 'debug', 'Checkout identity for order ' . $order->get_id() . ': ' . $identity['source'] . ( $identity['linked'] ? '' : ' (' . $identity['reason'] . ')' ) . ', consent ' . $consent );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Identity capture failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether a later capture may replace the identity already on the order. Refreshing the same
	 * visitor (block checkout's draft order, then the submission) and a sign-in during checkout are
	 * allowed; a payment retry from another browser or without consent never replaces the buyer.
	 */
	public static function may_replace( array $previous, array $next ): bool {
		if ( empty( $previous['linked'] ) ) {
			return true;
		}
		if ( empty( $next['linked'] ) ) {
			return false;
		}
		if ( $previous['distinct_id'] === $next['distinct_id'] ) {
			return true;
		}
		// Signed in at checkout: posthog-js merged the anonymous visitor into the account.
		return 'identified' === ( $next['user_state'] ?? null ) && 'identified' !== ( $previous['user_state'] ?? null );
	}

	/**
	 * An order placed before the visitor could be recorded (no consent on record, PostHog switched
	 * off): linked to the browser that accepts on its thank-you page (Visitor::ajax_link()). Only
	 * while the order is unlinked for that reason and its purchase is not built; never replaces a
	 * linked identity, never one the buyer refused.
	 */
	public static function link_later( \WC_Order $order, array $identity ): bool {
		$previous = self::fresh_identity( $order ); // the purchase may have been built by another request since the order was loaded
		if ( null === $previous || ! empty( $previous['linked'] ) || empty( $identity['linked'] ) || ! self::awaits_link( $order, true ) ) {
			return false;
		}
		$now                     = gmdate( 'Y-m-d\TH:i:s\Z' );
		$identity['captured_at'] = $now; // The server's clock, as at checkout.
		$capture                 = self::state( $order, self::META_CAPTURE );
		$capture['linked_at']    = $now;
		$capture['linked_from']  = (string) ( $previous['reason'] ?? 'unlinked' );
		$capture['consent']      = $identity['consent'];
		$order->update_meta_data( self::META_IDENTITY, wp_json_encode( $identity ) );
		$order->update_meta_data( self::META_CAPTURE, wp_json_encode( $capture ) );
		$order->update_meta_data( self::META_LEGACY_COOKIE, 'yes' );
		$order->update_meta_data( self::META_LEGACY_VISITOR, $identity['distinct_id'] );
		$order->save_meta_data();
		Plugin::log( 'debug', 'Order ' . $order->get_id() . ' linked after consent (was ' . $capture['linked_from'] . ')' );
		return true;
	}

	/**
	 * Unlinked only for want of consent or a PostHog ID, a guest's, and its purchase not built yet.
	 * $fresh reads the order's state from the database, not from the order as it was loaded.
	 */
	public static function awaits_link( \WC_Order $order, bool $fresh = false ): bool {
		$identity = $fresh ? self::fresh_identity( $order ) : self::stored_identity( $order );
		if ( null === $identity || ! empty( $identity['linked'] ) || $order->get_customer_id() ) {
			return false;
		}
		if ( ! in_array( $identity['reason'] ?? '', array( 'no_identity_no_consent_record', 'consent_unknown', 'capture_disabled', 'no_identity' ), true ) ) {
			return false; // A refusal stays a refusal.
		}
		$state = $fresh ? self::decode( self::fresh_meta( $order, self::META_COMPLETED ) ) : self::state( $order, self::META_COMPLETED );
		return ! isset( $state['event'] ) && ! in_array( $state['status'] ?? '', array( 'sent', 'logged', 'skipped', 'sending' ), true ) && '' === (string) $order->get_meta( self::META_LEGACY_SENT, true );
	}

	/** The identity frozen on the order, read from the database now (another request may have changed it). */
	public static function fresh_identity( \WC_Order $order ): ?array {
		$val = self::decode( self::fresh_meta( $order, self::META_IDENTITY ) );
		return ! empty( $val['distinct_id'] ) ? $val : self::stored_identity( $order );
	}

	/** One meta value of the order, read from the database rather than from the loaded order object. */
	private static function fresh_meta( \WC_Order $order, string $key ): ?string {
		try {
			$store = $order->get_data_store();
			if ( is_object( $store ) && method_exists( $store, 'read_meta' ) ) {
				$value = null;
				foreach ( (array) $store->read_meta( $order ) as $row ) {
					if ( isset( $row->meta_key ) && $key === $row->meta_key ) {
						$value = (string) $row->meta_value;
					}
				}
				return $value;
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'debug', 'Fresh read of ' . $key . ' failed: ' . $e->getMessage() );
		}
		$raw = $order->get_meta( $key, true );
		return is_string( $raw ) ? $raw : null;
	}

	private static function decode( ?string $raw ): array {
		$val = null === $raw ? null : json_decode( $raw, true );
		return is_array( $val ) ? $val : array();
	}

	private static function stored_identity( \WC_Order $order ): ?array {
		$raw = $order->get_meta( self::META_IDENTITY, true );
		$val = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $val ) && ! empty( $val['distinct_id'] ) ? $val : null;
	}

	/** Frozen identity of an order; an unlinked identity when nothing was captured (admin orders, old orders). */
	public static function identity( \WC_Order $order ): array {
		$val = self::stored_identity( $order );
		if ( null !== $val ) {
			return $val;
		}
		// Orders captured by 0.2.x: visitor ID only.
		$legacy = (string) $order->get_meta( self::META_LEGACY_VISITOR, true );
		if ( '' !== $legacy && 'yes' === $order->get_meta( self::META_LEGACY_COOKIE, true ) ) {
			return array(
				'distinct_id' => $legacy, 'source' => Identity::SOURCE_COOKIE, 'linked' => true, 'reason' => null, 'consent' => Consent::IMPLIED,
				'device_id' => (string) $order->get_meta( self::META_LEGACY_DEVICE, true ) ?: null, 'session_id' => (string) $order->get_meta( self::META_LEGACY_SESSION, true ) ?: null,
				'user_state' => null, 'process_person' => true, 'flags' => array(), 'props' => array(), 'captured_at' => Util::utc( $order->get_date_created() ),
			);
		}
		return Identity::resolve( null, null, null, Consent::UNKNOWN, self::ANONYMOUS_PREFIX . Uuid::event( Settings::store(), 'order', array( (string) $order->get_id() ) ) );
	}

	/** Server context captured at checkout (buyer IP, user agent, checkout URL). */
	public static function capture_context( \WC_Order $order ): array {
		$raw = $order->get_meta( self::META_CAPTURE, true );
		$val = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$ctx = is_array( $val ) ? $val : array();
		if ( empty( $ctx['ip'] ) && empty( $ctx['erased'] ) ) {
			$ctx['ip'] = Util::public_ip( (string) $order->get_customer_ip_address() );
		}
		if ( empty( $ctx['user_agent'] ) && empty( $ctx['erased'] ) ) {
			$ctx['user_agent'] = (string) ( $order->get_customer_user_agent() ?: $order->get_meta( '_wc_order_attribution_user_agent', true ) );
		}
		return $ctx;
	}

	/* -------------------------------------------------------------------- paid */

	public function on_payment_complete( $order_id ): void {
		try {
			$this->maybe_queue_completed( (int) $order_id );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Queueing the purchase of order ' . (int) $order_id . ' failed: ' . $e->getMessage() );
		}
	}

	public function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		try {
			if ( ! Settings::active() ) {
				return;
			}
			$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
				return;
			}
			// should_track() decides: paid statuses, and statuses other plugins add for paid orders.
			$this->maybe_queue_completed( (int) $order_id );
			if ( 'cancelled' === $to ) {
				$this->on_cancelled( $order );
			}
			if ( in_array( $to, array( 'failed', 'cancelled' ), true ) && ! $order->get_date_paid() && Settings::on( 'ev_payment_failed' ) && self::is_web_order( $order ) ) {
				$this->queue->enqueue_payment_failed( $order, (string) $to );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Status change of order ' . (int) $order_id . ' failed: ' . $e->getMessage() );
		}
	}

	/** Remember when a paid order was cancelled; the cancellation is sent once its purchase was delivered. */
	private function on_cancelled( \WC_Order $order ): void {
		$state = self::state( $order, self::META_CANCELLED );
		if ( in_array( $state['status'] ?? '', array( 'sent', 'logged', 'skipped' ), true ) || ( null === $order->get_date_paid() && ! self::completed_done( $order ) ) ) {
			return; // Already handled, or never paid (Payment Failed covers that).
		}
		Queue::save_state( $order, self::META_CANCELLED, array( 'status' => 'pending', 'at' => Util::utc() ) );
		if ( null !== self::delivered_purchase( $order ) ) {
			$this->queue->enqueue( Queue::KIND_CANCELLED, $order->get_id() );
		}
	}

	public function on_refunded( $order_id, $refund_id = 0 ): void {
		try {
			if ( ! Settings::active() ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && null !== self::delivered_purchase( $order ) ) {
				$this->queue->enqueue( Queue::KIND_REFUND, (int) $order_id, (int) $refund_id );
			}
			// Otherwise the purchase queues its refunds once it is delivered.
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Refund of order ' . (int) $order_id . ' failed: ' . $e->getMessage() );
		}
	}

	public function maybe_queue_completed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! Settings::active() || ! Settings::on( 'ev_order' ) ) {
			return;
		}
		if ( ! self::should_track( $order ) || self::completed_done( $order ) ) {
			return;
		}
		$this->queue->enqueue( Queue::KIND_COMPLETED, $order_id, 0, Settings::delay_seconds() );
	}

	/**
	 * Paid now, or paid and since refunded (the purchase happened; refunds are separate events).
	 * A status another plugin sets on paid orders without registering it as paid counts as paid.
	 * A purchase paid before this shop started sending to the current project is not ours: the plugin
	 * that tracked the shop until then reported it (a later status change, such as shipping, or the
	 * sweep must not report it again).
	 */
	public static function should_track( \WC_Order $order ): bool {
		if ( 'shop_order' !== $order->get_type() || ! self::is_web_order( $order ) ) {
			return false;
		}
		$status = $order->get_status();
		$paid   = null !== $order->get_date_paid();
		$track  = in_array( $status, self::paid_statuses(), true )
			|| ( 'refunded' === $status && $paid )
			|| ( $paid && ! in_array( $status, self::NOT_PAID_STATUSES, true ) );
		if ( $track && self::paid_before_sending( $order ) ) {
			$track = false;
		}
		return (bool) apply_filters( 'w99_phwc_should_track_order', $track, $order );
	}

	/** Paid (or, without a payment date, placed) before this shop started sending to the current project. */
	public static function paid_before_sending( \WC_Order $order ): bool {
		$since = Settings::sending_since();
		$at    = $order->get_date_paid() ?: $order->get_date_created();
		return null !== $since && null !== $at && $at->getTimestamp() < $since;
	}

	/** Orders typed in by staff are not web purchases (as in 0.2.x). */
	public static function is_web_order( \WC_Order $order ): bool {
		return ! in_array( $order->get_created_via(), array( 'admin' ), true );
	}

	/** The purchase is settled: sent (now or by 0.2.x), logged in test mode, or skipped for consent. */
	public static function completed_done( \WC_Order $order ): bool {
		if ( in_array( self::state( $order, self::META_COMPLETED )['status'] ?? '', array( 'sent', 'logged', 'skipped' ), true ) ) {
			return true;
		}
		return '' !== (string) $order->get_meta( self::META_LEGACY_SENT, true ); // Sent by 0.2.x.
	}

	/** Whether PostHog received the event in this state: sent, or logged while still in test mode. */
	public static function is_delivered( array $state ): bool {
		$status = $state['status'] ?? '';
		return 'sent' === $status || ( 'logged' === $status && 'test' === Settings::mode() );
	}

	/**
	 * The purchase PostHog received for this order, which its refunds and cancellation follow; null
	 * when there is none. A purchase logged in test mode never reached PostHog, so after switching
	 * to On its refunds are not sent either.
	 */
	public static function delivered_purchase( \WC_Order $order ): ?array {
		$state = self::state( $order, self::META_COMPLETED );
		if ( isset( $state['event'] ) && self::is_delivered( $state ) ) {
			return $state;
		}
		if ( array() === $state && '' !== (string) $order->get_meta( self::META_LEGACY_SENT, true ) ) {
			return self::legacy_purchase( $order );
		}
		return null;
	}

	/**
	 * A purchase 0.2.x sent: its person (0.2.x's rule: the hashed billing e-mail while its identify
	 * setting was on, else the visitor), for refunds and cancellations sent after the upgrade. Only
	 * while the project key is still the one 0.2.x sent to: a pilot's purchases are not in the
	 * project a later rollout switches to.
	 */
	private static function legacy_purchase( \WC_Order $order ): ?array {
		$legacy_key = (string) get_option( Settings::PREFIX . 'legacy_key', '' );
		if ( '' === $legacy_key || Settings::token() !== $legacy_key ) {
			return null;
		}
		$email    = (string) $order->get_billing_email();
		$visitor  = (string) $order->get_meta( self::META_LEGACY_VISITOR, true );
		$distinct = ( '' !== $email && 'no' !== get_option( Settings::PREFIX . 'ev_identify', 'yes' ) ) ? wp_hash( $email ) : $visitor;
		if ( '' === $distinct ) {
			return null;
		}
		$device = (string) $order->get_meta( self::META_LEGACY_DEVICE, true );
		return array(
			'status' => 'sent',
			'legacy' => true,
			'event'  => array(
				'distinct_id' => $distinct,
				'properties'  => array_filter( array( 'identity_source' => 'legacy_0_2', 'consent' => Consent::IMPLIED, '$device_id' => '' !== $device ? $device : null ) ),
			),
		);
	}

	/** @return string[] */
	public static function paid_statuses(): array {
		return array_values( array_map( 'strval', (array) apply_filters( 'w99_phwc_paid_statuses', wc_get_is_paid_statuses() ) ) );
	}

	/** Delivery state stored as JSON on an order or refund. */
	public static function state( $object, string $key ): array {
		$raw = $object->get_meta( $key, true );
		$val = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		return is_array( $val ) ? $val : array();
	}

	/** A request made by a shopper's browser on the storefront (not admin, cron or CLI), by someone recorded (Audience: not staff, page editors or previews unless the shop records them). */
	public static function is_buyer_request(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		return Audience::records_request();
	}
}
