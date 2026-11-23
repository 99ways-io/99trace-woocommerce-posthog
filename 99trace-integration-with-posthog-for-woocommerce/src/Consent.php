<?php
/**
 * The visitor's analytics consent as the server can see it on a request.
 *
 * Sources, in order: the consent platform's own record (Cookiebot's CookieConsent cookie, or the
 * WP Consent API), which is the authority where a site runs one, so consent given again after a
 * withdrawal counts; then posthog-js's own opt-in/out cookie. When nobody has answered but
 * posthog-js stored an identity, consent is "implied": on a site where a banner gates posthog-js,
 * its cookie can only exist after consent.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Consent {

	public const GRANTED      = 'granted';
	public const DENIED       = 'denied';
	public const UNKNOWN      = 'unknown';
	public const NOT_REQUIRED = 'not_required';
	public const IMPLIED      = 'implied';

	/**
	 * @param string        $mode        auto | cookiebot | wp_consent_api | posthog_opt_in | none.
	 * @param array         $cookies     Request cookies.
	 * @param string        $token       Project key (names posthog-js's opt-in cookie).
	 * @param bool          $has_identity Whether posthog-js's identity cookie (or a checkout envelope from a
	 *                                    capturing posthog-js) is present. The shop's own visitor cookie is no
	 *                                    evidence of consent and is never counted.
	 * @param callable|null $wp_has_consent wp_has_consent() when the WP Consent API is loaded.
	 */
	public static function evaluate( string $mode, array $cookies, string $token, bool $has_identity, ?callable $wp_has_consent = null ): string {
		if ( 'none' === $mode ) {
			return self::NOT_REQUIRED;
		}

		if ( 'cookiebot' === $mode || 'auto' === $mode ) {
			$cookiebot = self::cookiebot( Util::cookie( $cookies, 'CookieConsent' ) );
			if ( null !== $cookiebot ) {
				return $cookiebot;
			}
			if ( 'cookiebot' === $mode || ( 'auto' === $mode && class_exists( __NAMESPACE__ . '\\Settings' ) && 'cookiebot' === Settings::consent_platform() ) ) {
				// Cookiebot is the authority: without its answer on record (never given, or expired after its 12
				// months) the visitor has not answered. A posthog-js cookie from an earlier consent implies
				// nothing: "Switched off" keeps it while the banner asks again. posthog-js's own opt-out (a
				// withdrawal) still counts as a refusal.
				$ph = Util::cookie( $cookies, '__ph_opt_in_out_' . $token );
				return ( '0' === $ph || 'false' === $ph ) ? self::DENIED : self::UNKNOWN;
			}
		}
		if ( null !== $wp_has_consent && ( 'wp_consent_api' === $mode || 'auto' === $mode ) ) {
			if ( call_user_func( $wp_has_consent, 'statistics' ) ) {
				return self::GRANTED;
			}
			// In opt-in regions false also means "not answered yet" (or an answer that expired: the API keeps it
			// 30 days): a refusal only once it is stored. The API is the authority here, so cookies of an earlier
			// consent imply nothing.
			if ( 'deny' === Util::cookie( $cookies, self::wp_consent_prefix() . '_statistics' ) ) {
				return self::DENIED;
			}
			return self::UNKNOWN;
		}

		$ph = Util::cookie( $cookies, '__ph_opt_in_out_' . $token );
		if ( '0' === $ph || 'false' === $ph ) {
			return self::DENIED;
		}
		if ( '1' === $ph || 'true' === $ph ) {
			return self::GRANTED;
		}
		return $has_identity ? self::IMPLIED : self::UNKNOWN;
	}

	/**
	 * Cookiebot's CookieConsent cookie: "-1" (no consent needed in the visitor's region) or a JS-like
	 * object such as {stamp:'…',necessary:true,preferences:false,statistics:true,marketing:false,method:'explicit',…}.
	 */
	public static function cookiebot( ?string $raw ): ?string {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$raw = rawurldecode( $raw );
		if ( '-1' === trim( $raw ) ) {
			return self::NOT_REQUIRED;
		}
		if ( preg_match( '/statistics\s*:\s*(true|false)/i', $raw, $m ) ) {
			return 'true' === strtolower( $m[1] ) ? self::GRANTED : self::DENIED;
		}
		return null;
	}

	/** The WP Consent API's cookie prefix (filter `wp_consent_cookie_prefix`, default `wp_consent`). */
	public static function wp_consent_prefix(): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the WP Consent API's own filter.
		$prefix = function_exists( 'apply_filters' ) ? apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' ) : 'wp_consent';
		return is_string( $prefix ) && '' !== $prefix ? $prefix : 'wp_consent';
	}

	/**
	 * Whether a visitor ID may be created now: consent given, or none needed. Never on "implied": that
	 * state is inferred from identity cookies, and a new ID must not rest on an inference.
	 */
	public static function allows_new_id( string $state ): bool {
		return in_array( $state, array( self::GRANTED, self::NOT_REQUIRED ), true );
	}

	/**
	 * Whether customer details (e-mail, name, phone) may be set on the visitor's person: only on an answer on
	 * record or where none is needed, never on consent inferred from older cookies.
	 */
	public static function allows_details( string $state ): bool {
		return in_array( $state, array( self::GRANTED, self::NOT_REQUIRED ), true );
	}

	/** Whether this consent state lets an order or event be linked to the visitor. */
	public static function allows_linking( string $state ): bool {
		return in_array( $state, array( self::GRANTED, self::NOT_REQUIRED, self::IMPLIED ), true );
	}
}
