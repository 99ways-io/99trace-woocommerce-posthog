<?php
/**
 * One visitor ID for the browser and the shop's server.
 *
 * Whoever needs an ID first creates it and the other side uses the same one, so PostHog never has
 * to merge two IDs of one visitor:
 *  - posthog-js has an ID: the server reads posthog-js's cookie and sends its cart, coupon and
 *    checkout events under that ID (as in 0.4.0).
 *  - the browser module runs before posthog-js has an ID: it creates the ID, keeps it in this shop
 *    cookie and starts posthog-js with it (posthog-js's bootstrap).
 *  - the server acts first (an "add to cart" link as the landing page, a click before any script
 *    ran): it creates the ID, keeps it in the cookie marked as the server's, and sends under it.
 *    posthog-js starts with it on the next page. Where posthog-js already had another ID, the
 *    browser module asks PostHog once to merge the server's ID into it (alias).
 * If posthog-js never loads (an ad blocker), the server keeps using the cookie's ID for the cart,
 * the checkout and the order: one person, without browser events.
 *
 * The cookie is written only where the visitor may be recorded (the Consent setting), only on
 * responses no page cache keeps (cart actions, checkout), and holds no personal data.
 *
 * Also here: an order placed before the visitor could be recorded (no consent yet) is linked to
 * the browser that accepts on its thank-you page, while its purchase has not been sent. The order
 * key in the page's address proves that the browser may see the order.
 *
 * Nothing is stored on the server: the ID lives in cookies, the link on the order.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Visitor {

	public const COOKIE = 'ph_wc_vid';
	public const AJAX   = 'w99_phwc_link';

	/** Marks an ID the server created: the browser has not started posthog-js with it yet. */
	private const MINTED = '.s';

	public function register(): void {
		add_action( 'wc_ajax_' . self::AJAX, array( $this, 'ajax_link' ) );
	}

	/** On by setting, and only where 99Trace's browser module runs (beside another plugin's posthog-js it would split visitors). */
	public static function enabled(): bool {
		return Settings::on( 'visitor_cookie' ) && Settings::browser_module_runs();
	}

	/**
	 * The cookie's value: a UUID, with the server's mark while the browser has not adopted it.
	 *
	 * @return array{id: string, minted: bool}|null
	 */
	public static function parse( ?string $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$minted = self::MINTED === substr( $raw, -2 );
		$id     = strtolower( $minted ? substr( $raw, 0, -2 ) : $raw );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ) {
			return null;
		}
		return array( 'id' => $id, 'minted' => $minted );
	}

	/** The shop cookie's visitor on this request, in Identity::from_cookie()'s shape; null when there is none. */
	public static function from_cookie(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		$vid = self::parse( Util::cookie( Util::request_cookies(), self::COOKIE ) );
		return null === $vid ? null : self::identity_for( $vid['id'] );
	}

	/**
	 * Create the visitor's ID on the server and keep it in the cookie, for a visitor with no ID
	 * anywhere yet. Only while the response can still set a cookie, and never for a crawler, link
	 * preview or script following an "add to cart" link (as in 0.4.0, nothing is sent for those).
	 */
	public static function mint(): ?array {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( ! self::enabled() || headers_sent() || Util::is_bot( $ua ) ) {
			return null;
		}
		$id = Uuid::v7();
		self::set_cookie( $id . self::MINTED, time() + YEAR_IN_SECONDS );
		Plugin::log( 'debug', 'Visitor ID created by the server: ' . $id );
		return self::identity_for( $id );
	}

	/** At logout: the ID may have been joined to the account, so the next visitor in this browser starts afresh. */
	public static function clear(): void {
		if ( isset( $_COOKIE[ self::COOKIE ] ) && ! headers_sent() ) {
			self::set_cookie( '', time() - YEAR_IN_SECONDS );
			unset( $_COOKIE[ self::COOKIE ] );
		}
	}

	private static function set_cookie( string $value, int $expires ): void {
		setcookie( self::COOKIE, $value, array( 'expires' => $expires, 'path' => self::path(), 'domain' => self::domain(), 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		if ( '' !== $value ) {
			$_COOKIE[ self::COOKIE ] = $value;
		}
	}

	/** Cookie path and domain WordPress uses; the browser module writes the cookie with the same ones. */
	public static function path(): string {
		return defined( 'COOKIEPATH' ) && COOKIEPATH ? (string) COOKIEPATH : '/';
	}

	public static function domain(): string {
		return defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';
	}

	private static function identity_for( string $id ): array {
		return array(
			'distinct_id'       => $id,
			'device_id'         => $id, // posthog-js started with this ID uses it as its device ID too
			'session_id'        => null,
			'session_last_seen' => null,
			'user_state'        => 'anonymous',
			'person_processing' => 'always' === Settings::get( 'person_profiles' ),
			'capturing'         => null,
			'consent_status'    => null,
			'flags'             => array(),
			'props'             => array(),
			'captured_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * The visitor making this request, for a server event (cart, coupon, checkout): posthog-js's ID,
	 * else the signed-in customer's account (where no consent is required), else the shop cookie's
	 * ID, else a new one ($create). Null when the visitor may not be recorded, and for staff.
	 */
	public static function current( bool $create = true ): ?array {
		if ( ! Orders::is_buyer_request() ) {
			return null;
		}
		$ckey = Settings::cookie_key();
		if ( '' === $ckey ) {
			return null;
		}
		$cookies = Util::request_cookies();
		$ph      = Identity::from_cookie( Util::cookie( $cookies, Identity::cookie_name( $ckey ) ), time() );
		$shop    = self::from_cookie();
		// The shop's own cookie is no evidence of consent (it outlives the consent platform's record).
		$consent = Consent::evaluate( Settings::consent_mode(), $cookies, $ckey, null !== $ph, function_exists( 'wp_has_consent' ) ? 'wp_has_consent' : null );
		if ( ! Consent::allows_linking( $consent ) ) {
			return null;
		}
		$user     = get_current_user_id();
		$customer = ( $user && self::enabled() && Settings::on( 'identify_customers' ) ) ? Settings::customer_distinct_id( $user ) : null;
		$identity = Identity::resolve( null, $ph, $customer, $consent, '', $shop );
		if ( empty( $identity['linked'] ) && null === $shop && $create && Consent::allows_new_id( $consent ) ) {
			$shop = self::mint();
			if ( null !== $shop ) {
				$identity = Identity::resolve( null, $ph, $customer, $consent, '', $shop );
			}
		}
		return empty( $identity['linked'] ) ? null : $identity;
	}

	/* ---------------------------------------------- order linked after consent */

	/**
	 * The thank-you page's browser, after the visitor accepted there: link the order it shows, if the
	 * order is still unlinked and its purchase not built (Visitor::link_request()).
	 */
	public function ajax_link(): void {
		$linked = false;
		try {
			nocache_headers();
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- called by logged-out visitors from cached thank-you pages, where a nonce would have expired: the order key is the proof.
			$request = array(
				'order_id' => isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0,
				'key'      => isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON from posthog-js, validated field by field in Identity::from_browser().
				'identity' => isset( $_POST['identity'] ) && is_string( $_POST['identity'] ) ? wp_unslash( $_POST['identity'] ) : null,
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$linked = self::link_request( $request, Util::request_cookies() );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Linking an order after consent failed: ' . $e->getMessage() );
		}
		wp_send_json( array( 'linked' => $linked ) );
	}

	/**
	 * Link an order placed before the visitor could be recorded to the browser that asks with the order key
	 * (WooCommerce shows the order to whoever holds it) and an envelope from a capturing posthog-js. Only a
	 * guest's order, only to an anonymous visitor (never an account or an identified person), only within
	 * the thank-you page link's age, and only with consent on this request.
	 *
	 * @param array $post    Request fields: order_id, key, identity (the envelope as JSON).
	 * @param array $cookies Request cookies.
	 */
	public static function link_request( array $post, array $cookies ): bool {
		if ( ! Settings::active() || ! Settings::browser_module_runs() || ! Settings::on( 'link_return_browser' ) ) {
			return false;
		}
		$order_id = isset( $post['order_id'] ) ? absint( $post['order_id'] ) : 0;
		$key      = isset( $post['key'] ) && is_string( $post['key'] ) ? sanitize_text_field( $post['key'] ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return false;
		}
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
		if ( time() - $created > (int) apply_filters( 'w99_phwc_return_link_max_age', DAY_IN_SECONDS, $order ) ) {
			return false;
		}
		$browser = isset( $post['identity'] ) && is_string( $post['identity'] ) ? Identity::from_browser( $post['identity'], Settings::token() ) : null;
		if ( null === $browser || false === ( $browser['capturing'] ?? null ) ) {
			return false;
		}
		// A guest's order goes to an anonymous visitor only: never an account or an identified person.
		if ( 'identified' === ( $browser['user_state'] ?? null ) || 0 === strpos( (string) $browser['distinct_id'], 'wc:' ) ) {
			return false;
		}
		$ckey     = Settings::cookie_key();
		$evidence = null !== Identity::from_cookie( Util::cookie( $cookies, Identity::cookie_name( $ckey ) ), time() ) || true === ( $browser['capturing'] ?? null );
		$consent  = Consent::evaluate( Settings::consent_mode(), $cookies, $ckey, $evidence, function_exists( 'wp_has_consent' ) ? 'wp_has_consent' : null );
		$identity = Identity::resolve( $browser, null, null, $consent, '' );
		return ! empty( $identity['linked'] ) && Orders::link_later( $order, $identity );
	}
}
