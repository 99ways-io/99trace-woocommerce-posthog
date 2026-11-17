<?php
/**
 * Settings, stored as `w99_phwc_*` options (the 0.2.x names are kept, new ones added).
 *
 * @package W99\Trace
 */

namespace W99\Trace;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const PREFIX = 'w99_phwc_';

	/** posthog-js `defaults` this version is tested with (posthog-js 1.447). */
	public const POSTHOG_DEFAULTS = '2026-08-30';

	/** Defaults for every setting; the admin screen is generated from Admin::fields(). */
	public const DEFAULTS = array(
		'mode'                 => 'off',            // off | test | on
		'key'                  => '',               // project API key events are sent to
		'host'                 => 'https://eu.i.posthog.com',
		'browser'              => 'no',             // load posthog-js (replace another PostHog plugin first)
		'browser_host'         => '',               // optional reverse proxy for the browser; empty = host
		'cookie_key'           => '',               // project key whose posthog-js cookie holds the visitor (pilot beside another plugin)
		'consent'              => 'none',           // none | auto | cookiebot | wp_consent_api | posthog_opt_in (0.5.0: 'none' on new installs)
		'early_start'          => 'no',             // 0.3.0 consent switch; read only when before_consent was never saved
		'before_consent'       => 'off',            // off | switch | hold: what posthog-js does before the visitor answers the banner
		'hold_across_pages'    => 'no',             // hold: keep the held events in sessionStorage across pages until the answer
		'posthog_js_options'   => '',               // further posthog-js options as JSON, applied over 99Trace's
		'person_profiles'      => 'always',          // always | identified_only
		'autocapture'          => 'posthog',         // posthog | off (PostHog's project setting decides, or never)
		'capture_pageleave'    => 'yes',
		'capture_dead_clicks'  => 'posthog',         // posthog | on | off
		'identify_customers'   => 'yes',            // logged-in customers: identify with an opaque account ID
		'link_return_browser'  => 'yes',            // thank-you page opened with the order key in another browser joins the buyer
		'unconsented_orders'   => 'anonymous',      // anonymous (counted, no identity, no IP) | skip
		'visitor_cookie'       => 'yes',            // one visitor ID for browser and server, kept in the ph_wc_vid cookie (Visitor)
		'person_details'       => 'yes',            // e-mail, name, phone as person properties (for PostHog destinations), with consent
		'record_staff'         => 'no',             // signed-in users who can manage orders (Audience)
		'record_editors'       => 'no',             // signed-in users who can edit pages but not manage orders
		'record_previews'      => 'no',             // page-builder and preview loads (Elementor, FunnelKit, WordPress, Customizer)
		'cart_checkout_fill'   => 'no',             // Product Added for a cart the checkout page fills while it loads
		'ev_checkout'          => 'yes',
		'ev_order'             => 'yes',
		'ev_refund'            => 'yes',
		'ev_cancelled'         => 'yes',
		'ev_payment_failed'    => 'yes',
		'ev_cart'              => 'yes',
		'ev_coupon'            => 'yes',
		'ev_cart_removed'      => 'yes',
		'ev_coupon_removed'    => 'yes',
		'ev_confirmation_viewed' => 'yes',
		'capture_pageview'     => 'yes',
		'ev_cart_viewed'       => 'yes',
		'ev_category_viewed'   => 'yes',
		'ev_product_viewed'    => 'yes',
		'send_ip'              => 'yes',
		'delay_minutes'        => '10',
		'strip_params'         => 'key,order_key,wfocu-key,_wpnonce,token,email,billing_email,phone',
		'debug'                => 'no',
		// Browser features (posthog-js). "posthog": the PostHog project's own setting decides (its switch in
		// PostHog's project settings); "on" / "off": this shop overrides it. See FEATURES.
		'ft_replay'            => 'posthog',
		'ft_heatmaps'          => 'posthog',
		'ft_web_vitals'        => 'posthog',
		'ft_rageclick'         => 'yes',
		'ft_surveys'           => 'posthog',
		'ft_flags'             => 'yes',
		'ft_cross_subdomain'   => 'yes',
		'ft_exceptions'        => 'posthog',
		'ft_mask_text'         => 'no',
	);

	/** @var array<string, string>|null */
	private static $cache = null;

	public static function flush(): void {
		self::$cache = null;
		self::$names = null;
	}

	public static function get( string $name ): string {
		if ( null === self::$cache ) {
			self::$cache = array();
		}
		if ( ! array_key_exists( $name, self::$cache ) ) {
			$default             = self::DEFAULTS[ $name ] ?? '';
			$value               = function_exists( 'get_option' ) ? get_option( self::PREFIX . $name, $default ) : $default;
			self::$cache[ $name ] = is_scalar( $value ) ? trim( (string) $value ) : $default;
		}
		return self::$cache[ $name ];
	}

	public static function on( string $name ): bool {
		return 'yes' === self::get( $name );
	}

	/**
	 * Every event 99Trace sends: key => [standard name, where it comes from, its on/off setting]. What each one
	 * is, as shown in the Events table, is in Admin::event_descriptions().
	 * "server": the shop's server (WooCommerce), "browser": 99Trace's code in the visitor's browser,
	 * "posthog": posthog-js's own automatic events (on/off only: PostHog's reports rely on their names).
	 */
	public const EVENTS = array(
		'order_completed'           => array( 'Order Completed', 'server', 'ev_order' ),
		'order_refunded'            => array( 'Order Refunded', 'server', 'ev_refund' ),
		'order_cancelled'           => array( 'Order Cancelled', 'server', 'ev_cancelled' ),
		'payment_failed'            => array( 'Payment Failed', 'server', 'ev_payment_failed' ),
		'checkout_started'          => array( 'Checkout Started', 'server', 'ev_checkout' ),
		'product_added'             => array( 'Product Added', 'server', 'ev_cart' ),
		'product_removed'           => array( 'Product Removed', 'server', 'ev_cart_removed' ),
		'coupon_applied'            => array( 'Coupon Applied', 'server', 'ev_coupon' ),
		'coupon_removed'            => array( 'Coupon Removed', 'server', 'ev_coupon_removed' ),
		'product_viewed'            => array( 'Product Viewed', 'browser', 'ev_product_viewed' ),
		'category_viewed'           => array( 'View Category', 'browser', 'ev_category_viewed' ),
		'cart_viewed'               => array( 'Cart Viewed', 'browser', 'ev_cart_viewed' ),
		'order_confirmation_viewed' => array( 'Order Confirmation Viewed', 'browser', 'ev_confirmation_viewed' ),
		'pageview'                  => array( '$pageview', 'posthog', 'capture_pageview' ),
		'pageleave'                 => array( '$pageleave', 'posthog', 'capture_pageleave' ),
		'autocapture'               => array( '$autocapture', 'posthog', 'autocapture' ),
		'dead_click'                => array( '$dead_click', 'posthog', 'capture_dead_clicks' ),
	);

	public const EVENT_NAMES_OPTION = 'w99_phwc_event_names';

	/** @var array<string, string>|null custom names by event key */
	private static ?array $names = null;

	/** @return array<string, string> custom names by event key (only events that have one) */
	public static function custom_event_names(): array {
		if ( null === self::$names ) {
			$raw         = function_exists( 'get_option' ) ? get_option( self::EVENT_NAMES_OPTION, array() ) : array();
			self::$names = array();
			foreach ( is_array( $raw ) ? $raw : array() as $key => $name ) {
				if ( isset( self::EVENTS[ $key ] ) && 'posthog' !== self::EVENTS[ $key ][1] && is_string( $name ) && '' !== $name ) {
					self::$names[ $key ] = $name;
				}
			}
		}
		return self::$names;
	}

	/** The name an event is sent under: the shop's own name for it, else the standard one. */
	public static function event_name( string $standard ): string {
		$custom = self::custom_event_names();
		foreach ( self::EVENTS as $key => $event ) {
			if ( $event[0] === $standard ) {
				return $custom[ $key ] ?? $standard;
			}
		}
		return $standard;
	}

	/** Browser events renamed by the shop: standard name => name sent (for the browser module). */
	public static function browser_event_names(): array {
		$out = array();
		foreach ( self::custom_event_names() as $key => $name ) {
			if ( 'browser' === self::EVENTS[ $key ][1] ) {
				$out[ self::EVENTS[ $key ][0] ] = $name;
			}
		}
		return $out;
	}

	/**
	 * Clean the names typed in the Events table: trimmed, plain text, at most 100 characters, never starting
	 * with "$" (PostHog's own events) and never the name another event is sent under. A rejected or empty name
	 * means the standard name.
	 *
	 * @param array<string, mixed> $typed names by event key
	 * @return array{0: array<string, string>, 1: string[]} [kept names, standard names of rejected ones]
	 */
	public static function clean_event_names( array $typed ): array {
		$kept     = array();
		$rejected = array();
		$taken    = array();
		foreach ( self::EVENTS as $key => $event ) {
			$taken[ strtolower( $event[0] ) ] = $key;
		}
		foreach ( self::EVENTS as $key => $event ) {
			if ( 'posthog' === $event[1] || ! isset( $typed[ $key ] ) || ! is_scalar( $typed[ $key ] ) ) {
				continue;
			}
			$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $typed[ $key ] ) ) );
			if ( '' === $name || $name === $event[0] ) {
				continue;
			}
			$lower = strtolower( $name );
			if ( '$' === $name[0] || mb_strlen( $name ) > 100 || ( isset( $taken[ $lower ] ) && $taken[ $lower ] !== $key ) ) {
				$rejected[] = $event[0];
				continue;
			}
			$kept[ $key ]    = $name;
			$taken[ $lower ] = $key;
		}
		return array( $kept, $rejected );
	}

	/** Person properties 99Trace sets from what the buyer typed (Visitors & orders → customer details). */
	public const PERSON_KEYS = array( 'email', 'first_name', 'last_name', 'name', 'phone' );

	/**
	 * Browser features that the PostHog project switches on or off in its own settings (Project settings →
	 * autocapture, web vitals, dead clicks, heatmaps, replay, surveys, exception autocapture). Each one here
	 * follows that switch unless the shop overrides it: setting => whether the shop may force it on. Labels and
	 * descriptions are in Admin::feature_texts().
	 * posthog-js can only switch autocapture, replay and surveys off from the page (on, PostHog's switch still
	 * decides), so they have no "Always on".
	 */
	public const FEATURES = array(
		'autocapture'         => false,
		'capture_dead_clicks' => true,
		'ft_web_vitals'       => true,
		'ft_heatmaps'         => true,
		'ft_replay'           => false,
		'ft_surveys'          => false,
		'ft_exceptions'       => true,
	);

	/**
	 * A browser feature's choice: 'posthog' (the PostHog project's setting decides), 'on' or 'off'. Values saved
	 * by 0.4.0's tick boxes are read as they worked then: ticked = PostHog decides (error tracking: on), unticked =
	 * off (error tracking: PostHog decides).
	 */
	public static function feature( string $name ): string {
		$value = self::get( $name );
		if ( 'yes' === $value ) {
			$value = 'ft_exceptions' === $name ? 'on' : 'posthog';
		} elseif ( 'no' === $value ) {
			$value = 'ft_exceptions' === $name ? 'posthog' : 'off';
		}
		$allowed = ! empty( self::FEATURES[ $name ] ) ? array( 'posthog', 'on', 'off' ) : array( 'posthog', 'off' );
		return in_array( $value, $allowed, true ) ? $value : 'posthog';
	}

	/** Store 0.4.0's yes/no values as the choices they meant, so the settings page shows (and saves) them. */
	public static function normalize_features(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$changed = false;
		foreach ( array_keys( self::FEATURES ) as $name ) {
			$raw = get_option( self::PREFIX . $name, null );
			if ( 'yes' === $raw || 'no' === $raw ) {
				update_option( self::PREFIX . $name, self::feature( $name ) );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::flush();
		}
	}

	/**
	 * posthog-js options for the Browser features. A feature on "PostHog's setting" sets nothing, so the
	 * PostHog project's own switch decides; "Always on" / "Always off" override it on this shop. The plain
	 * on/off features (rage clicks, experiments, subdomains, hidden text) have no PostHog switch.
	 * "Further posthog-js options" go on top. Autocapture and dead clicks are set by the browser module.
	 *
	 * @return array<string, mixed>
	 */
	public static function browser_features(): array {
		$out = array();
		$vitals = self::feature( 'ft_web_vitals' );
		if ( 'posthog' !== $vitals ) {
			// Only web vitals: `capture_performance: false` would also switch off network timing in recordings.
			$out['capture_performance'] = array( 'web_vitals' => 'on' === $vitals );
		}
		$heatmaps = self::feature( 'ft_heatmaps' );
		if ( 'posthog' !== $heatmaps ) {
			$out['capture_heatmaps'] = 'on' === $heatmaps;
		}
		$exceptions = self::feature( 'ft_exceptions' );
		if ( 'posthog' !== $exceptions ) {
			$out['capture_exceptions'] = 'on' === $exceptions;
		}
		if ( 'off' === self::feature( 'ft_replay' ) ) {
			$out['disable_session_recording'] = true;
		}
		if ( 'off' === self::feature( 'ft_surveys' ) ) {
			$out['disable_surveys'] = true;
		}
		$off = array(
			'ft_rageclick'       => array( 'rageclick', false ),
			'ft_flags'           => array( 'advanced_disable_feature_flags', true ),
			'ft_cross_subdomain' => array( 'cross_subdomain_cookie', false ),
		);
		foreach ( $off as $setting => $option ) {
			if ( ! self::on( $setting ) ) {
				$out[ $option[0] ] = $option[1];
			}
		}
		if ( self::on( 'ft_mask_text' ) ) {
			$out['mask_all_text'] = true;
		}
		return $out;
	}

	/**
	 * What posthog-js does before the visitor answers the consent banner (Cookiebot or WP Consent API):
	 * 'off' (not loaded), 'switch' (loaded switched off: experiment variants at once, nothing recorded) or
	 * 'hold' (records the visit in the page's memory and sends it all when the visitor accepts).
	 * A shop that saved 0.3.0's consent switch and never this setting keeps its choice.
	 */
	public static function before_consent(): string {
		$raw = function_exists( 'get_option' ) ? get_option( self::PREFIX . 'before_consent', null ) : null;
		if ( null === $raw || '' === $raw ) {
			return self::on( 'early_start' ) ? 'switch' : 'off';
		}
		return in_array( $raw, array( 'off', 'switch', 'hold' ), true ) ? (string) $raw : 'off';
	}

	public static function mode(): string {
		// A constant in wp-config.php overrides the setting (staging copies): W99_TRACE_MODE, or 99Hog's W99_PHWC_MODE.
		if ( defined( 'W99_TRACE_MODE' ) ) {
			$mode = (string) constant( 'W99_TRACE_MODE' );
		} elseif ( defined( 'W99_PHWC_MODE' ) ) {
			$mode = (string) constant( 'W99_PHWC_MODE' );
		} else {
			$mode = self::get( 'mode' );
		}
		return in_array( $mode, array( 'off', 'test', 'on' ), true ) ? $mode : 'off';
	}

	public static function active(): bool {
		return 'off' !== self::mode();
	}

	public static function token(): string {
		return self::get( 'key' );
	}

	public static function host(): string {
		return Util::normalize_host( self::get( 'host' ) ?: 'https://eu.i.posthog.com' );
	}

	public static function browser_host(): string {
		$h = self::get( 'browser_host' );
		return '' !== $h ? Util::normalize_host( $h ) : self::host();
	}

	public static function ui_host(): string {
		return false !== strpos( self::host(), 'eu.' ) ? 'https://eu.posthog.com' : 'https://us.posthog.com';
	}

	/**
	 * Server sending works in test mode (logs only) or in on mode with a project key, on the site it
	 * was switched on for. Never falls back to another plugin's key.
	 */
	public static function can_send(): bool {
		return 'test' === self::mode() || ( 'on' === self::mode() && '' !== self::token() && ! self::site_moved() );
	}

	public static function browser_enabled(): bool {
		return self::active() && self::on( 'browser' ) && '' !== self::token() && ( 'test' === self::mode() || ! self::site_moved() );
	}

	/**
	 * Whether the browser module actually runs: switched on, a consent mode it can follow, and no
	 * other PostHog plugin on the site (two posthog-js instances split every visitor).
	 */
	public static function browser_module_runs(): bool {
		return self::browser_enabled() && '' !== self::browser_consent_mode()
			&& ( '' === self::other_posthog_plugin() || (bool) apply_filters( 'w99_phwc_allow_other_posthog_plugin', false ) );
	}

	/**
	 * Consent mode the browser module follows. "Automatic" resolves to the consent platform the site
	 * runs; with none found it resolves to '' and the module stays off (admin notice): loading
	 * posthog-js before an undetected banner answered would track visitors who decline.
	 */
	public static function browser_consent_mode(): string {
		$mode = self::consent_mode();
		if ( 'auto' !== $mode ) {
			return $mode;
		}
		return self::consent_platform();
	}

	/** A consent platform the site runs ('cookiebot', 'wp_consent_api'), or ''. */
	public static function consent_platform(): string {
		if ( defined( 'CYBOT_COOKIEBOT_VERSION' ) || function_exists( 'cookiebot_active' ) || ( function_exists( 'get_option' ) && get_option( 'cookiebot-cbid' ) ) ) {
			return 'cookiebot';
		}
		return function_exists( 'wp_has_consent' ) ? 'wp_consent_api' : '';
	}

	/** Another active PostHog plugin (plugin file), or ''. */
	public static function other_posthog_plugin(): string {
		if ( ! function_exists( 'get_option' ) || ! defined( 'W99_TRACE_FILE' ) ) {
			return '';
		}
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$own = dirname( plugin_basename( W99_TRACE_FILE ) );
		foreach ( $plugins as $plugin ) {
			$plugin = (string) $plugin;
			if ( false !== stripos( $plugin, 'posthog' ) && dirname( $plugin ) !== $own ) {
				return $plugin;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ sending since */

	public const SINCE_OPTION = 'w99_phwc_sending_since';

	/** The first characters of the project key: which project a delivery state or a start time belongs to. */
	public static function key_tag(): string {
		return substr( self::token(), 0, 12 );
	}

	/**
	 * When this shop started sending to the current project (Unix time), or null before it has. Purchases
	 * paid earlier belong to whatever tracked the shop until then (usually another PostHog plugin, which
	 * reported them already), so they are never sent to this project.
	 */
	public static function sending_since(): ?int {
		$all = function_exists( 'get_option' ) ? get_option( self::SINCE_OPTION, array() ) : array();
		$tag = self::key_tag();
		return is_array( $all ) && '' !== $tag && isset( $all[ $tag ] ) ? (int) $all[ $tag ] : null;
	}

	/** Record the moment sending to the current project starts (mode on, a key, the site's own address); once per project. */
	public static function mark_sending(): void {
		if ( ! function_exists( 'get_option' ) || 'on' !== self::mode() || '' === self::token() || self::site_moved() || null !== self::sending_since() ) {
			return;
		}
		$all                    = get_option( self::SINCE_OPTION, array() );
		$all                    = is_array( $all ) ? $all : array();
		$all[ self::key_tag() ] = time();
		update_option( self::SINCE_OPTION, $all, true );
	}

	/* ------------------------------------------------------------------ site lock */

	public const LOCK_OPTION = 'w99_phwc_site_lock';

	/** This site's address (host and path), as sending was switched on for it. */
	public static function site_address(): string {
		$url = function_exists( 'home_url' ) ? (string) home_url() : '';
		return strtolower( untrailingslashit( (string) preg_replace( '#^https?://#i', '', $url ) ) );
	}

	/**
	 * The address sending was switched on for. Stored encoded: moving a copy to its own address
	 * (wp search-replace, migration plugins) rewrites every plain copy of the old address in the
	 * database, which would make the copy look like the original. A plain value (0.3.0 previews) is read as is.
	 */
	public static function locked_address(): string {
		$lock = function_exists( 'get_option' ) ? (string) get_option( self::LOCK_OPTION, '' ) : '';
		if ( 0 === strpos( $lock, 'b64:' ) ) {
			$lock = (string) base64_decode( substr( $lock, 4 ), true );
		}
		return $lock;
	}

	/**
	 * A copy of the shop (staging, a developer's machine) that carries the live settings must not
	 * send into the live project: sending stops when the address differs from the one recorded when
	 * sending was first switched on, until an administrator confirms this site (admin notice).
	 */
	public static function site_moved(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$lock = self::locked_address();
		return '' !== $lock && $lock !== self::site_address();
	}

	/** Record this address as the one sending is on for (only when none is recorded, or by an administrator's confirmation). */
	public static function arm( bool $confirm = false ): void {
		$raw = (string) get_option( self::LOCK_OPTION, '' );
		// A plain value for this very address is re-stored encoded, so a later copy is still recognised.
		if ( $confirm || '' === $raw || ( 0 !== strpos( $raw, 'b64:' ) && $raw === self::site_address() ) ) {
			update_option( self::LOCK_OPTION, 'b64:' . base64_encode( self::site_address() ) );
		}
	}

	/**
	 * The project key whose posthog-js cookie names the visitor: ours when our browser module runs; otherwise
	 * the configured one, else the third-party plugin's key (pilot beside it), else ours.
	 */
	public static function cookie_key(): string {
		if ( self::on( 'browser' ) && self::browser_module_runs() ) {
			return self::token();
		}
		foreach ( array( self::get( 'cookie_key' ), function_exists( 'get_option' ) ? (string) get_option( 'phi_api_key', '' ) : '', self::token() ) as $k ) {
			$k = trim( (string) $k );
			if ( '' !== $k ) {
				return $k;
			}
		}
		return '';
	}

	/**
	 * posthog-js options keep 99Trace's identity and consent handling intact: these stay 99Trace's own
	 * (the project key and host, person profiles and consent have their own settings).
	 */
	public const PROTECTED_JS_OPTIONS = array(
		'api_host', 'ui_host', 'token', 'person_profiles', 'before_send', 'loaded', 'bootstrap',
		'persistence', 'persistence_name', 'disable_persistence', 'persistence_save_debounce_ms', 'cookie_persisted_properties',
		'opt_out_capturing_by_default', 'opt_out_persistence_by_default', 'opt_out_capturing_persistence_type', 'cookieless_mode',
	);

	/**
	 * The "further posthog-js options" setting, decoded. Invalid JSON gives an empty set (admin notice);
	 * protected options are dropped.
	 *
	 * @return array<string, mixed>
	 */
	public static function posthog_js_options(): array {
		$raw = self::get( 'posthog_js_options' );
		if ( '' === $raw ) {
			return array();
		}
		$val = json_decode( $raw, true );
		if ( ! is_array( $val ) || array_values( $val ) === $val ) {
			return array();
		}
		return array_diff_key( $val, array_flip( self::PROTECTED_JS_OPTIONS ) );
	}

	/** Whether the "further posthog-js options" setting holds something that is not a JSON object. */
	public static function posthog_js_options_invalid(): bool {
		$raw = self::get( 'posthog_js_options' );
		if ( '' === $raw ) {
			return false;
		}
		$val = json_decode( $raw, true );
		return ! is_array( $val ) || ( array() !== $val && array_values( $val ) === $val );
	}

	public static function consent_mode(): string {
		$mode = self::get( 'consent' );
		return in_array( $mode, array( 'auto', 'cookiebot', 'wp_consent_api', 'posthog_opt_in', 'none' ), true ) ? $mode : 'auto';
	}

	public static function delay_seconds(): int {
		return max( 0, min( 120, (int) self::get( 'delay_minutes' ) ) ) * 60;
	}

	/** @return string[] */
	public static function strip_params(): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( self::get( 'strip_params' ) ) ) ) ) );
	}

	/** Lower-case host that identifies this store in events and deterministic IDs. */
	public static function store(): string {
		$host = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : 'woocommerce';
		$store = function_exists( 'apply_filters' ) ? apply_filters( 'w99_phwc_store_id', $host ? $host : 'woocommerce' ) : $host;
		return strtolower( (string) $store );
	}

	/** Opaque, stable distinct ID for a logged-in customer account. Never an e-mail address. */
	public static function customer_distinct_id( int $user_id ): string {
		$id = 'wc:' . self::store() . ':' . $user_id;
		return (string) ( function_exists( 'apply_filters' ) ? apply_filters( 'w99_phwc_customer_distinct_id', $id, $user_id ) : $id );
	}
}
