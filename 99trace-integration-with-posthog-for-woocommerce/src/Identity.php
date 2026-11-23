<?php
/**
 * Reading the visitor's PostHog identity. posthog-js is the only writer; this class only reads:
 *
 *  1. the envelope our browser module puts into the checkout (hidden field or Store API data):
 *     distinct ID, device, session, person mode, consent state, active flags, and a snapshot of
 *     the properties posthog-js would attach to an event at that moment (browser, device,
 *     screen, URL, session entry attribution, click IDs);
 *  2. posthog-js's own `ph_<key>_posthog` cookie, on any storefront request (fallback, and the
 *     source for server-side cart and checkout events): distinct ID, device, session, person mode,
 *     Meta click ID;
 *  3. the shop's visitor-ID cookie (Visitor), when neither exists yet: the ID the browser module or
 *     the server created before posthog-js had stored one, which posthog-js starts with.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Identity {

	public const SOURCE_BROWSER  = 'browser';
	public const SOURCE_COOKIE   = 'cookie';
	public const SOURCE_CUSTOMER = 'customer_account';
	public const SOURCE_PARENT   = 'parent_order';
	public const SOURCE_SHOP     = 'shop_cookie';
	public const SOURCE_NONE     = 'none';

	private const SESSION_IDLE_SECONDS = 1800; // posthog-js default.

	/**
	 * Browser properties copied from the checkout snapshot onto server events. Everything here is
	 * something posthog-js itself attaches to browser events; no personal data.
	 */
	public const SNAPSHOT_KEYS = array(
		'$os', '$os_version', '$browser', '$browser_version', '$device', '$device_type', '$device_model',
		'$raw_user_agent', '$screen_height', '$screen_width', '$viewport_height', '$viewport_width',
		'$browser_language', '$browser_language_prefix', '$timezone', '$timezone_offset',
		'$current_url', '$host', '$pathname', '$referrer', '$referring_domain', '$search_engine',
		'$webview_app', '$webview_app_version', '$window_id', '$pageview_id',
		'$session_entry_url', '$session_entry_host', '$session_entry_pathname', '$session_entry_referrer', '$session_entry_referring_domain',
		'$session_entry_utm_source', '$session_entry_utm_medium', '$session_entry_utm_campaign', '$session_entry_utm_content', '$session_entry_utm_term',
		'$session_entry_gclid', '$session_entry_gad_source', '$session_entry_gbraid', '$session_entry_wbraid', '$session_entry_fbclid', '$session_entry_ttclid', '$session_entry_msclkid', '$session_entry__kx', '$session_entry_search_engine',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gad_source', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid', 'igshid',
		'$active_feature_flags', '$fbc', '$fbp', '$config_defaults',
	);

	/** Name of posthog-js's persistence cookie for a project key. */
	public static function cookie_name( string $token ): string {
		return 'ph_' . str_replace( array( '+', '/' ), '_', $token ) . '_posthog';
	}

	/**
	 * Parse posthog-js's persistence cookie.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function from_cookie( ?string $raw, int $now ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( rawurldecode( $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		$distinct_id = Util::clean_id( $data['distinct_id'] ?? null );
		if ( null === $distinct_id ) {
			return null;
		}
		$session_id   = null;
		$session_last = null;
		if ( isset( $data['$sesid'] ) && is_array( $data['$sesid'] ) && count( $data['$sesid'] ) >= 2 ) {
			$last_ms   = is_numeric( $data['$sesid'][0] ) ? (float) $data['$sesid'][0] : 0.0;
			$candidate = Util::clean_id( $data['$sesid'][1] );
			// A session idle longer than posthog-js's timeout has ended; the browser starts a new one next time.
			if ( null !== $candidate && $last_ms > 0 && ( $now - (int) floor( $last_ms / 1000 ) ) <= self::SESSION_IDLE_SECONDS ) {
				$session_id   = $candidate;
				$session_last = (int) floor( $last_ms / 1000 );
			}
		}
		// Only posthog-js's built-in cookie properties are read. Session entry attribution stays in
		// localStorage: copying its two URLs into the cookie can push it past the browser's 4 KB limit,
		// and a refused write leaves a stale identity that wins on the next page. Server events from
		// the cookie carry the session ID, so PostHog's session attribution applies to them.
		$props = array();
		// Meta click ID: posthog-js 1.447 stores {value, delivered}; older versions a plain string.
		$fbc = $data['$fbc_persistence'] ?? null;
		$fbc = is_array( $fbc ) ? ( $fbc['value'] ?? null ) : $fbc;
		$fbc = is_string( $fbc ) && function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $fbc ) : $fbc;
		if ( is_string( $fbc ) && '' !== $fbc ) {
			$props['$fbc'] = substr( $fbc, 0, 300 );
		}
		return array(
			'distinct_id'       => $distinct_id,
			'device_id'         => Util::clean_id( $data['$device_id'] ?? null ),
			'session_id'        => $session_id,
			'session_last_seen' => $session_last,
			'user_state'        => in_array( $data['$user_state'] ?? null, array( 'anonymous', 'identified' ), true ) ? $data['$user_state'] : null,
			'person_processing' => isset( $data['$epp'] ) ? (bool) $data['$epp'] : null,
			'capturing'         => null,
			'consent_status'    => null,
			'flags'             => array(),
			'props'             => $props,
			'captured_at'       => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
		);
	}

	/**
	 * Validate the envelope the browser module sends with the checkout.
	 *
	 * @param mixed $payload JSON string or array.
	 * @return array<string, mixed>|null
	 */
	public static function from_browser( $payload, string $token ): ?array {
		if ( is_string( $payload ) ) {
			if ( strlen( $payload ) > 16000 ) {
				return null;
			}
			$payload = json_decode( $payload, true );
		}
		if ( ! is_array( $payload ) || 1 !== (int) ( $payload['v'] ?? 0 ) ) {
			return null;
		}
		if ( isset( $payload['token'] ) && '' !== $token && $payload['token'] !== $token ) {
			return null; // An identity from another PostHog project.
		}
		$distinct_id = Util::clean_id( $payload['distinct_id'] ?? null );
		if ( null === $distinct_id ) {
			return null;
		}
		$flags = array();
		if ( isset( $payload['flags'] ) && is_array( $payload['flags'] ) ) {
			foreach ( $payload['flags'] as $k => $v ) {
				if ( count( $flags ) >= 100 ) {
					break;
				}
				$k = (string) $k;
				if ( '' !== $k && strlen( $k ) <= 200 && ( is_bool( $v ) || ( is_string( $v ) && '' !== $v && strlen( $v ) <= 200 ) ) ) {
					$flags[ $k ] = $v;
				}
			}
		}
		$props = array();
		if ( isset( $payload['props'] ) && is_array( $payload['props'] ) ) {
			foreach ( self::SNAPSHOT_KEYS as $k ) {
				if ( ! array_key_exists( $k, $payload['props'] ) ) {
					continue;
				}
				$v = $payload['props'][ $k ];
				if ( is_string( $v ) && '' !== $v ) {
					$props[ $k ] = substr( $v, 0, 1000 );
				} elseif ( is_int( $v ) || is_float( $v ) || is_bool( $v ) ) {
					$props[ $k ] = $v;
				} elseif ( '$active_feature_flags' === $k && is_array( $v ) ) {
					$props[ $k ] = array_slice( array_values( array_filter( $v, 'is_string' ) ), 0, 100 );
				}
			}
		}
		$captured_at = null;
		if ( isset( $payload['captured_at'] ) && is_string( $payload['captured_at'] ) && false !== strtotime( $payload['captured_at'] ) ) {
			$captured_at = gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $payload['captured_at'] ) );
		}
		$bool = static function ( $v ): ?bool {
			if ( true === $v || 1 === $v || '1' === $v || 'true' === $v ) {
				return true;
			}
			if ( false === $v || 0 === $v || '0' === $v || 'false' === $v ) {
				return false;
			}
			return null;
		};
		return array(
			'distinct_id'       => $distinct_id,
			'device_id'         => Util::clean_id( $payload['device_id'] ?? null ),
			'session_id'        => Util::clean_id( $payload['session_id'] ?? null ),
			'session_last_seen' => null,
			'user_state'        => in_array( $payload['user_state'] ?? null, array( 'anonymous', 'identified' ), true ) ? $payload['user_state'] : null,
			'person_processing' => $bool( $payload['person_processing'] ?? null ),
			'capturing'         => $bool( $payload['capturing'] ?? null ),
			'consent_status'    => in_array( $payload['consent_status'] ?? null, array( 'granted', 'denied', 'pending' ), true ) ? $payload['consent_status'] : null,
			'flags'             => $flags,
			'props'             => $props,
			'captured_at'       => $captured_at,
		);
	}

	/**
	 * Whom an order belongs to in PostHog. Decided once per order and frozen.
	 *
	 * @param array|null  $browser  Envelope from the checkout.
	 * @param array|null  $cookie   posthog-js cookie on the checkout request.
	 * @param string|null $customer Account distinct ID of a logged-in buyer (only used when no browser identity exists and consent is not required).
	 * @param string      $consent  Consent state at checkout.
	 * @param string      $fallback Order-scoped distinct ID for unlinked orders.
	 * @param array|null  $shop     The shop's visitor-ID cookie (Visitor::from_cookie()), used when there is nothing else.
	 * @return array<string, mixed>
	 */
	public static function resolve( ?array $browser, ?array $cookie, ?string $customer, string $consent, string $fallback, ?array $shop = null ): array {
		$unlinked = static function ( string $reason ) use ( $fallback, $consent ): array {
			return array(
				'distinct_id'    => $fallback,
				'source'         => self::SOURCE_NONE,
				'linked'         => false,
				'reason'         => $reason,
				'consent'        => $consent,
				'device_id'      => null,
				'session_id'     => null,
				'user_state'     => null,
				'process_person' => false,
				'flags'          => array(),
				'props'          => array(),
				'captured_at'    => null,
			);
		};
		if ( Consent::DENIED === $consent ) {
			return $unlinked( 'consent_denied' );
		}
		if ( null !== $browser && 'denied' === ( $browser['consent_status'] ?? null ) ) {
			return $unlinked( 'consent_denied' );
		}
		if ( null !== $browser && false === ( $browser['capturing'] ?? null ) ) {
			return $unlinked( 'capture_disabled' );
		}
		$pick = null;
		$source = null;
		if ( null !== $browser ) {
			$pick   = $browser;
			$source = self::SOURCE_BROWSER;
			// The cookie may add what the envelope lacks (e.g. an older browser module).
			if ( null !== $cookie && $cookie['distinct_id'] === $browser['distinct_id'] ) {
				$pick['props']     = $browser['props'] + $cookie['props'];
				$pick['device_id'] = $pick['device_id'] ?? $cookie['device_id'];
			}
		} elseif ( null !== $cookie ) {
			$pick   = $cookie;
			$source = self::SOURCE_COOKIE;
		}
		if ( null !== $pick ) {
			if ( ! Consent::allows_linking( $consent ) ) {
				return $unlinked( 'consent_unknown' );
			}
			return array(
				'distinct_id'    => $pick['distinct_id'],
				'source'         => $source,
				'linked'         => true,
				'reason'         => null,
				'consent'        => $consent,
				'device_id'      => $pick['device_id'] ?? null,
				'session_id'     => $pick['session_id'] ?? null,
				'user_state'     => $pick['user_state'] ?? null,
				'process_person' => self::processes_person( $pick ),
				'flags'          => $pick['flags'] ?? array(),
				'props'          => $pick['props'] ?? array(),
				'captured_at'    => $pick['captured_at'] ?? null,
			);
		}
		if ( null !== $customer && Consent::NOT_REQUIRED === $consent ) {
			$out                   = $unlinked( '' );
			$out['distinct_id']    = $customer;
			$out['source']         = self::SOURCE_CUSTOMER;
			$out['linked']         = true;
			$out['reason']         = null;
			$out['user_state']     = 'identified';
			$out['process_person'] = true;
			return $out;
		}
		if ( null !== $shop && Consent::allows_linking( $consent ) ) {
			return array(
				'distinct_id'    => $shop['distinct_id'],
				'source'         => self::SOURCE_SHOP,
				'linked'         => true,
				'reason'         => null,
				'consent'        => $consent,
				'device_id'      => $shop['device_id'] ?? null,
				'session_id'     => null,
				'user_state'     => 'anonymous',
				'process_person' => self::processes_person( $shop ),
				'flags'          => array(),
				'props'          => array(),
				'captured_at'    => $shop['captured_at'] ?? null,
			);
		}
		return $unlinked( Consent::UNKNOWN === $consent ? 'no_identity_no_consent_record' : 'no_identity' );
	}

	/**
	 * posthog-js's person mode for this visitor: anonymous visitors under 'identified_only' are
	 * personless, so their server events must be personless too (same person_id, no billed person).
	 */
	public static function processes_person( array $identity ): bool {
		return 'identified' === ( $identity['user_state'] ?? null ) || true === ( $identity['person_processing'] ?? null );
	}
}
