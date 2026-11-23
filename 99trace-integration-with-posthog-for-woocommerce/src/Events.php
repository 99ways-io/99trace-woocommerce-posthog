<?php
/**
 * Builders for every server-side event. One envelope for all of them: deterministic UUID, UTC
 * timestamp, the buyer's identity and person mode, the browser snapshot from checkout, server
 * context (buyer IP for GeoIP, user agent), provenance and consent.
 *
 * Property names follow the shops' current PostHog plugin where it has them (order_id, total,
 * revenue = amount charged, products[], products_string, …) so existing insights keep working;
 * additions are documented in docs/properties.md.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Events {

	public const SCHEMA_VERSION = 2;
	private const HOUR          = 3600;

	/**
	 * Common envelope.
	 *
	 * @param array<string, mixed> $props    Event-specific properties.
	 * @param array<string, mixed> $identity Frozen identity (Identity::resolve() + capture context).
	 * @param array<string, mixed> $context  'ip', 'user_agent', 'current_url', 'referrer' known to the server.
	 */
	public static function build( string $event, string $kind, array $ids, string $timestamp, array $props, array $identity, array $context = array() ): array {
		$event     = Settings::event_name( $event ); // the shop's own name for it, if it gave one (the UUID follows the kind, not the name)
		$strip     = Settings::strip_params();
		$linked    = ! empty( $identity['linked'] );
		// Consented but unlinked (no PostHog identity in the browser): the order is described, not linked.
		$consented = $linked || Consent::allows_linking( (string) ( $identity['consent'] ?? '' ) );

		// 1. What the browser knew at checkout (only for linked visitors: it describes them).
		$snapshot = $linked ? (array) ( $identity['props'] ?? array() ) : array();
		foreach ( array( '$current_url', '$referrer', '$session_entry_url', '$session_entry_referrer' ) as $url_key ) {
			if ( isset( $snapshot[ $url_key ] ) ) {
				$snapshot[ $url_key ] = Util::strip_url( (string) $snapshot[ $url_key ], $strip );
			}
		}

		// 2. Server context: URL and user agent of the request that created the event.
		$server = array();
		if ( $consented && ! empty( $context['current_url'] ) && ! isset( $snapshot['$current_url'] ) ) {
			$url                     = Util::strip_url( (string) $context['current_url'], $strip );
			$server['$current_url']  = $url;
			$server['$pathname']     = Util::path_of( $url );
		}
		if ( $consented && ! empty( $context['referrer'] ) && ! isset( $snapshot['$referrer'] ) ) {
			$ref                         = Util::strip_url( (string) $context['referrer'], $strip );
			$server['$referrer']         = $ref;
			$server['$referring_domain'] = Util::host_of( $ref );
		}
		$ua = (string) ( $snapshot['$raw_user_agent'] ?? ( $context['user_agent'] ?? '' ) );
		if ( $consented && '' !== $ua ) {
			$parsed = Util::user_agent( $ua );
			if ( ! isset( $snapshot['$browser'] ) ) {
				$server += array_intersect_key( $parsed, array_flip( array( '$browser', '$browser_version', '$os', '$os_version', '$device_type', '$device' ) ) );
				$server['$raw_user_agent'] = substr( $ua, 0, 500 );
			}
			if ( isset( $parsed['in_app_browser'] ) ) {
				$server['in_app_browser'] = $parsed['in_app_browser'];
			}
		}

		$properties = $props + $snapshot + $server;
		$properties += array(
			'$host'           => Settings::store(),
			'platform'        => 'woocommerce',
			'store'           => Settings::store(),
			'tracking_source' => 'server',
			'identity_source' => $identity['source'] ?? Identity::SOURCE_NONE,
			'identity_reason' => $identity['reason'] ?? null,
			'consent'         => $identity['consent'] ?? 'unknown',
			'schema_version'  => self::SCHEMA_VERSION,
			'$lib'            => '99trace',
			'$lib_version'    => W99_PHWC_TRACE_VERSION,
		);

		if ( $linked && ! empty( $identity['device_id'] ) ) {
			$properties['$device_id'] = $identity['device_id'];
		}
		if ( $linked && ! empty( $identity['session_id'] ) && self::session_still_current( (string) $identity['session_id'], $identity['captured_at'] ?? null, $timestamp ) ) {
			$properties['$session_id'] = $identity['session_id'];
		}
		if ( $linked && ! empty( $identity['flags'] ) ) {
			$properties['checkout_flags'] = $identity['flags'];
		}

		// Buyer location: the buyer's IP, never the shop server's.
		$ip = $consented && Settings::on( 'send_ip' ) ? Util::public_ip( $context['ip'] ?? null ) : '';
		if ( '' !== $ip ) {
			$properties['$ip'] = $ip;
		} else {
			$properties['$geoip_disable'] = true;
		}

		// Same person rule as posthog-js in the browser: 'always' processes every visitor, 'identified_only' only identified ones.
		if ( ! $linked || ( empty( $identity['process_person'] ) && 'always' !== Settings::get( 'person_profiles' ) ) ) {
			// Mirror posthog-js 'identified_only': anonymous visitors stay personless; unlinked orders never create a person.
			$properties['$process_person_profile'] = false;
		}

		return array(
			'type'        => 'capture',
			'event'       => $event,
			'distinct_id' => (string) $identity['distinct_id'],
			'uuid'        => Uuid::event( Settings::store(), $kind, $ids ),
			'timestamp'   => $timestamp,
			'properties'  => array_filter( $properties, static fn( $v ) => null !== $v && '' !== $v && array() !== $v ),
		);
	}

	/**
	 * E-mail, first name, last name, full name and phone from an order's billing details or a customer
	 * account, as PostHog person properties. Sent as typed (trimmed; the e-mail in lower case): PostHog's
	 * destinations normalise and hash them before they leave PostHog.
	 *
	 * @param \WC_Order|\WC_Customer $source
	 * @return array<string, string>
	 */
	public static function person_details( $source ): array {
		$email = strtolower( trim( (string) $source->get_billing_email() ) );
		if ( '' === $email && $source instanceof \WC_Customer ) {
			$email = strtolower( trim( (string) $source->get_email() ) );
		}
		$first = trim( (string) $source->get_billing_first_name() );
		$last  = trim( (string) $source->get_billing_last_name() );
		$out   = array(
			'email'      => is_email( $email ) ? $email : '',
			'first_name' => $first,
			'last_name'  => $last,
			'name'       => trim( $first . ' ' . $last ),
			'phone'      => trim( (string) $source->get_billing_phone() ),
		);
		return array_filter( $out, static fn( $v ) => '' !== $v );
	}

	/** `Order Completed`: once per paid order, timestamped at the payment. */
	public static function order_completed( \WC_Order $order, array $identity, array $context ): array {
		$o     = OrderData::order( $order );
		$props = array(
			'order_id'             => $o['id'],
			'order_number'         => $o['number'],
			'affiliation'          => 'WooCommerce',
			'total'                => Util::money( $o['total'] ),
			'subtotal'             => Util::money( $o['subtotal'] ),
			'revenue'              => Util::money( $o['total'] ),                       // amount charged, as the current plugin reports it
			'net_revenue'          => Util::money( $o['subtotal'] - $o['discount'] ),   // merchandise after discounts, excl. tax and shipping
			'shipping'             => Util::money( $o['shipping'] ),
			'tax'                  => Util::money( $o['tax'] ),
			'discount'             => Util::money( $o['discount'] ),
			'coupon'               => implode( ',', $o['coupons'] ),
			'currency'             => $o['currency'],
			'products'             => $o['lines'],
			'products_string'      => OrderData::products_string( $o['lines'] ),
			'quantity'             => array_sum( array_column( $o['lines'], 'quantity' ) ),
			'payment_method'       => $o['payment_method'],
			'payment_method_title' => $o['payment_title'],
			'created_via'          => $o['created_via'],
			'customer_type'        => $o['customer_type'],
			'order_placed_at'      => $o['created_at'],
			'returning_customer'   => self::returning_customer( $order ),
		) + OrderData::wc_attribution( $order, ! empty( $identity['linked'] ) || Consent::allows_linking( (string) ( $identity['consent'] ?? '' ) ) );
		// The buyer's details on their person (never on an anonymous order): PostHog's destinations hash them for the ad platforms.
		// Only on a person PostHog keeps: never with "only logged-in customers" for an anonymous visitor.
		$person_kept = ! empty( $identity['process_person'] ) || 'always' === Settings::get( 'person_profiles' );
		if ( Settings::on( 'person_details' ) && $person_kept && ! empty( $identity['linked'] ) && Consent::allows_details( (string) ( $identity['consent'] ?? '' ) ) ) {
			$set = self::person_details( $order );
			if ( $set ) {
				$props['$set'] = $set;
			}
		}
		return self::build( 'Order Completed', 'order_completed', array( $o['id'] ), $o['paid_at'] ?? $o['created_at'], $props, $identity, $context );
	}

	/**
	 * `Order Refunded`: once per refund, for the same person as the purchase. A refund made after
	 * the order's cancellation was sent reverses nothing more (the cancellation reversed the whole
	 * remaining amount): revenue 0, the amount kept in refund_amount.
	 */
	public static function order_refunded( \WC_Order $order, \WC_Order_Refund $refund, array $identity, array $context, bool $after_cancellation = false ): array {
		$o      = OrderData::order( $order );
		$r      = OrderData::refund( $order, $refund );
		$amount = $after_cancellation ? 0.0 : $r['amount'];
		$props  = array(
			'order_id'           => $o['id'],
			'order_number'       => $o['number'],
			'refund_id'          => $r['id'],
			'refund_amount'      => Util::money( $r['amount'] ),
			'total'              => Util::money( $amount ),
			'revenue'            => Util::money( $amount ),
			'after_cancellation' => $after_cancellation ? true : null,
			'order_total'        => Util::money( $o['total'] ),
			'full_refund'        => $r['full_refund'],
			'reason'             => $r['reason'],
			'currency'           => $r['currency'],
			'products'           => $r['lines'],
			'products_string'    => OrderData::products_string( $r['lines'] ),
			'payment_method'     => $o['payment_method'],
		);
		return self::build( 'Order Refunded', 'order_refunded', array( $o['id'], $r['id'] ), $r['created_at'], $props, $identity, $context );
	}

	/** `Order Cancelled`: a counted (paid) order cancelled later; reverses only what refunds did not. */
	public static function order_cancelled( \WC_Order $order, float $remaining, string $at, array $identity, array $context ): array {
		$o = OrderData::order( $order );
		$props = array(
			'order_id'        => $o['id'],
			'order_number'    => $o['number'],
			'total'           => Util::money( $remaining ),
			'revenue'         => Util::money( $remaining ),
			'order_total'     => Util::money( $o['total'] ),
			'currency'        => $o['currency'],
			'products_string' => OrderData::products_string( $o['lines'] ),
			'payment_method'  => $o['payment_method'],
		);
		return self::build( 'Order Cancelled', 'order_cancelled', array( $o['id'] ), $at, $props, $identity, $context );
	}

	/** `Payment Failed`: an unpaid web order moved to failed or cancelled (abandoned at the bank, declined, expired). */
	public static function payment_failed( \WC_Order $order, string $status, int $occurrence, string $at, array $identity, array $context ): array {
		$o = OrderData::order( $order );
		$props = array(
			'order_id'             => $o['id'],
			'order_number'         => $o['number'],
			'failure_status'       => $status,
			'attempt'              => $occurrence,
			// A cancellation long after the order is usually WooCommerce's unpaid-order timer, not the shopper.
			'minutes_since_order'  => (int) round( ( strtotime( $at ) - strtotime( $o['created_at'] ) ) / 60 ),
			'total'                => Util::money( $o['total'] ),
			'currency'             => $o['currency'],
			'payment_method'       => $o['payment_method'],
			'payment_method_title' => $o['payment_title'],
			'products'             => $o['lines'],
			'products_string'      => OrderData::products_string( $o['lines'] ),
		);
		return self::build( 'Payment Failed', 'payment_failed', array( $o['id'], $status, $occurrence ), $at, $props, $identity, $context );
	}

	/**
	 * Attach the buyer's browser session only when the event belongs to it: within an hour of the
	 * checkout, not before the session started, and within 24 hours of its start (PostHog drops later
	 * events from session aggregates, and a bank transfer paid two days later must not stretch the
	 * checkout session).
	 */
	public static function session_still_current( string $session_id, ?string $captured_at, string $timestamp ): bool {
		$event_time = strtotime( $timestamp );
		if ( false === $event_time ) {
			return false;
		}
		if ( null !== $captured_at ) {
			$captured = strtotime( $captured_at );
			if ( false === $captured || abs( $event_time - $captured ) > self::HOUR ) {
				return false;
			}
		}
		$hex = str_replace( '-', '', $session_id );
		if ( 32 === strlen( $hex ) && ctype_xdigit( $hex ) && '7' === $hex[12] ) {
			$started = (int) floor( hexdec( substr( $hex, 0, 12 ) ) / 1000 );
			if ( $event_time - $started > 24 * self::HOUR || $started - $event_time > 60 ) {
				return false; // Too late for it, or it started after the event (an order linked after the visitor accepted).
			}
		}
		return true;
	}

	/** Whether the billing e-mail (never sent) or account paid an earlier order: new vs returning buyers without personal data. */
	private static function returning_customer( \WC_Order $order ): bool {
		// Two at most: the order itself may be among them (it is left out below rather than excluded in the query).
		$args = array(
			'limit'        => 2,
			'return'       => 'ids',
			'status'       => wc_get_is_paid_statuses(),
			'date_created' => '<' . ( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ),
			'type'         => 'shop_order',
		);
		if ( $order->get_customer_id() ) {
			$args['customer_id'] = $order->get_customer_id();
		} elseif ( '' !== $order->get_billing_email() ) {
			$args['billing_email'] = $order->get_billing_email();
		} else {
			return false;
		}
		return array() !== array_diff( array_map( 'intval', (array) wc_get_orders( $args ) ), array( (int) $order->get_id() ) );
	}
}
