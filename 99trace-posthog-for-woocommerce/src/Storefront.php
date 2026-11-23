<?php
/**
 * Storefront: the browser module (posthog-js after consent), page data for browser events, the
 * return-browser link on thank-you pages, and server-side cart, coupon and checkout events (sent
 * under the visitor's one ID: Visitor).
 *
 * Everything printed into pages is identical for every visitor of a URL, so a page cache can never
 * hand one visitor's identity to another. The two per-visitor facts travel elsewhere: a signed-in
 * customer's account ID in a cookie set at login (and on cart, checkout and account pages, which
 * are never cached), the order link only on thank-you pages, which carry the order key in their
 * URL and are marked DONOTCACHEPAGE.
 *
 * @package W99\Trace
 */

namespace W99\Trace;

defined( 'ABSPATH' ) || exit;

final class Storefront {

	public const CUSTOMER_COOKIE  = 'ph_wc_customer';
	private const LOGOUT_COOKIE   = 'ph_wc_logout';
	private const CHECKOUT_DEDUPE = 1800;

	/** @var Queue */
	private $queue;

	/** @var array<string, mixed>|null Order whose thank-you page is being rendered. */
	private $order_link = null;

	/** @var bool Inside WC_Cart::add_to_cart(), which also fires the quantity-update hook for existing lines. */
	private $adding = false;

	/** @var bool Checkout Started decided in this request (sent, deduplicated or not applicable). */
	private $checkout_checked = false;

	/** WooCommerce AJAX action the browser module calls when a page loaded in advance is shown. */
	public const SHOWN_AJAX = 'w99_phwc_shown';

	/** Transient prefix of the events a page loaded in advance holds until it is shown. */
	public const SHOWN_PREFIX = 'w99_phwc_shown_';

	/** How long such events wait for the page to be shown (browsers keep a prefetched page for 5 minutes). */
	public const SHOWN_WAIT = 1800;

	/** @var string This request's token for its held events ('' while none is held). */
	private $shown_token = '';

	/** @var array<int, array{event: array, checkout: string|null}> Events this request holds. */
	private $shown_held = array();

	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'detect_order_page' ), 5 );
		add_action( 'template_redirect', array( $this, 'refresh_customer_cookie' ), 6 );
		// After template_include: funnel checkouts (FunnelKit on theme-builder pages) fill the cart at
		// template_include 99, so template_redirect would see an empty cart. wp_footer is the fallback.
		add_filter( 'template_include', array( $this, 'checkout_started_on_template' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( $this, 'checkout_started' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_shown' ), 100 );
		add_action( 'wc_ajax_' . self::SHOWN_AJAX, array( $this, 'ajax_shown' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 1 );
		add_action( 'set_logged_in_cookie', array( $this, 'on_logged_in_cookie' ), 10, 4 );
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
		add_action( 'woocommerce_created_customer', array( $this, 'on_new_customer' ), 20, 1 );
		add_action( 'clear_auth_cookie', array( $this, 'clear_customer_cookie' ) );
		add_action( 'wp_logout', array( $this, 'mark_logout' ) );
		add_filter( 'script_loader_tag', array( $this, 'script_tag' ), 10, 2 );
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'rocket_exclusions' ) );
		add_filter( 'rocket_exclude_js', array( $this, 'rocket_exclusions' ) );

		add_filter( 'woocommerce_add_to_cart_quantity', array( $this, 'adding_to_cart' ), 1 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'on_remove_cart_item' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'on_quantity_update' ), 10, 4 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ) );
		add_action( 'woocommerce_removed_coupon', array( $this, 'on_coupon_removed' ) );
	}

	/* ------------------------------------------------------------ browser module */

	public function enqueue(): void {
		try {
			if ( ! self::module_on_page() ) {
				return;
			}
			$mode   = Settings::browser_consent_mode();
			$before = in_array( $mode, array( 'cookiebot', 'wp_consent_api' ), true ) ? Settings::before_consent() : 'off';
			if ( 'switch' === $before && ! Settings::on( 'ft_flags' ) ) {
				$before = 'off'; // "Switched off" is for experiments only: without feature flags it would load PostHog for nothing.
			}
			$url    = plugins_url( 'assets/js/99trace.js', W99_TRACE_FILE );
			// The file's own date in the version: a CDN or browser cache never serves an older module.
			$mtime = @filemtime( plugin_dir_path( W99_TRACE_FILE ) . 'assets/js/99trace.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_register_script( 'w99-phwc', $url, array(), W99_TRACE_VERSION . ( $mtime ? '.' . $mtime : '' ), array( 'in_footer' => false ) );
			$config = array(
				'token'             => Settings::token(),
				'apiHost'           => Settings::browser_host(),
				'uiHost'            => Settings::ui_host(),
				'defaults'          => Settings::POSTHOG_DEFAULTS,
				'consent'           => $mode,
				// Before the consent platform answers: posthog-js not loaded ('off'), loaded switched off ('switch'),
				// or recording into the page's memory and sending it all on consent ('hold').
				'beforeConsent'     => $before,
				'earlyStart'        => 'off' !== $before,
				'holdAcrossPages'   => 'hold' === $before && Settings::on( 'hold_across_pages' ),
				'personProfiles'    => Settings::get( 'person_profiles' ),
				// Mode Test: posthog-js runs (identity, consent, experiments can be checked) but no event is sent.
				'testMode'          => 'test' === Settings::mode(),
				'autocapture'       => 'off' !== Settings::feature( 'autocapture' ), // on: PostHog's project setting still decides
				'pageleave'         => Settings::on( 'capture_pageleave' ),
				'deadClicks'        => array( 'posthog' => null, 'on' => true, 'off' => false )[ Settings::feature( 'capture_dead_clicks' ) ], // null: PostHog decides
				'stripParams'       => Settings::strip_params(),
				'linkReturn'        => Settings::on( 'link_return_browser' ),
				'identifyCustomers' => Settings::on( 'identify_customers' ),
				'customerCookie'    => self::CUSTOMER_COOKIE,
				'customerPrefix'    => Settings::customer_distinct_id( 0 ) === 'wc:' . Settings::store() . ':0' ? 'wc:' . Settings::store() . ':' : '',
				// One visitor ID for browser and server (Visitor): the cookie's name, path and domain, the same for every visitor.
				'visitorCookie'     => Visitor::enabled() ? Visitor::COOKIE : '',
				'cookiePath'        => Visitor::path(),
				'cookieDomain'      => Visitor::domain(),
				'linkUrl'           => class_exists( 'WC_AJAX' ) ? \WC_AJAX::get_endpoint( Visitor::AJAX ) : '',
				// A page loaded in advance reports itself shown there, which sends the events it holds (print_shown()).
				'shownUrl'          => class_exists( 'WC_AJAX' ) ? \WC_AJAX::get_endpoint( self::SHOWN_AJAX ) : '',
				'shown'             => '' !== $this->shown_token ? $this->shown_token : null,
				'personDetails'     => Settings::on( 'person_details' ),
				'debug'             => Settings::on( 'debug' ),
				'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'events'            => array(
					'productViewed'  => Settings::on( 'ev_product_viewed' ),
					'categoryViewed' => Settings::on( 'ev_category_viewed' ),
					'cartViewed'     => Settings::on( 'ev_cart_viewed' ),
					'confirmationViewed' => Settings::on( 'ev_confirmation_viewed' ),
				),
				'eventNames'        => (object) Settings::browser_event_names(),
				'pageview'          => Settings::on( 'capture_pageview' ),
				// Browser features, then "Further posthog-js options" on top. The options 99Trace's identity, consent and
				// scrubbing depend on are dropped after the filter too, so no setting or code can switch them off.
				'posthogConfig'     => (object) array_diff_key(
					(array) apply_filters( 'w99_phwc_posthog_js_config', array_merge( Settings::browser_features(), Settings::posthog_js_options() ) ),
					array_flip( Settings::PROTECTED_JS_OPTIONS )
				),
				'page'              => $this->page_data(),
				// Order and account pages show the buyer's name, address and e-mail.
				'sensitive'         => null !== $this->order_link || self::is_sensitive_page(),
			);
			if ( $this->order_link ) {
				$config['orderLink'] = $this->order_link;
			}
			wp_add_inline_script( 'w99-phwc', 'window.w99phwc = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
			wp_enqueue_script( 'w99-phwc' );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Browser module setup failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Cookiebot's auto-blocking must never hold our loader back: with a consent mode the module waits for
	 * the answer itself, and with "Track every visitor" the shop owner has decided that no banner gates PostHog.
	 */
	public function script_tag( $tag, $handle ) {
		if ( 'w99-phwc' === $handle && false === strpos( $tag, 'data-cookieconsent' ) ) {
			$tag = str_replace( '<script ', '<script data-cookieconsent="ignore" data-no-optimize="1" data-no-defer="1" ', $tag );
		}
		return $tag;
	}

	public function rocket_exclusions( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = '/99trace-posthog-for-woocommerce/assets/js/99trace.js';
		$list[] = 'w99phwc';
		return $list;
	}

	private static function is_sensitive_page(): bool {
		return function_exists( 'is_order_received_page' ) && ( is_order_received_page() || is_checkout_pay_page() || is_account_page() );
	}

	/* -------------------------------------------------------- customer identity */

	/** At login (and registration at checkout): the account ID for the browser module, for the auth cookie's lifetime. */
	public function on_logged_in_cookie( $cookie = '', $expire = 0, $expiration = 0, $user_id = 0 ): void {
		try {
			$this->set_customer_cookie( (int) $user_id, (int) $expire );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Customer cookie failed: ' . $e->getMessage() );
		}
	}

	/** A customer signs in: their account's details on their person (Visitors & orders → customer details). */
	public function on_login( $user_login = '', $user = null ): void {
		if ( $user instanceof \WP_User ) {
			$this->send_person_details( (int) $user->ID );
		}
	}

	/** A customer account was created (registration, or at checkout). */
	public function on_new_customer( $customer_id = 0 ): void {
		$this->send_person_details( (int) $customer_id );
	}

	/**
	 * E-mail, name and phone of a customer account as person properties of the account's person, when the
	 * visitor's consent allows linking. A `$set` event, so nothing else is recorded.
	 */
	public function send_person_details( int $user_id ): void {
		try {
			if ( ! $user_id || ! Settings::active() || ! Settings::on( 'person_details' ) || ! Settings::on( 'identify_customers' ) || ! class_exists( 'WC_Customer' ) ) {
				return;
			}
			if ( ! Audience::records_user( $user_id ) ) {
				return;
			}
			if ( ! Settings::browser_module_runs() ) {
				return; // Without 99Trace's posthog-js, the browser's visitor is another plugin's: nothing to set it on.
			}
			$ckey    = Settings::cookie_key();
			$cookie  = '' !== $ckey ? Identity::from_cookie( Util::cookie( $_COOKIE, Identity::cookie_name( $ckey ) ), time() ) : null; // phpcs:ignore
			$consent = Consent::evaluate( Settings::consent_mode(), $_COOKIE, $ckey, null !== $cookie, function_exists( 'wp_has_consent' ) ? 'wp_has_consent' : null ); // phpcs:ignore
			if ( null === $cookie || ! Consent::allows_details( $consent ) ) {
				return;
			}
			$set = Events::person_details( new \WC_Customer( $user_id ) );
			if ( ! $set ) {
				return;
			}
			// On the browser's visitor (posthog-js's cookie), not the account ID: the browser module then identifies
			// that visitor as the account, and PostHog moves the details with it. A $set under the account ID would
			// create the account's person first, which PostHog refuses to merge with an already identified visitor.
			$identity = Identity::resolve( null, $cookie, null, $consent, '' );
			if ( empty( $identity['linked'] ) ) {
				return;
			}
			$this->queue->enqueue_event( Events::build( '$set', 'person_details', array( (string) $user_id, (string) microtime( true ) ), Util::utc(), array( '$set' => $set ), $identity, self::request_context() ) );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Customer details failed: ' . $e->getMessage() );
		}
	}

	/** Customers signed in before this version, or who consented after signing in: set on uncached pages only. */
	public function refresh_customer_cookie(): void {
		try {
			$user_id = get_current_user_id();
			if ( ! $user_id || ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() || is_account_page() ) ) {
				return;
			}
			$current = isset( $_COOKIE[ self::CUSTOMER_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CUSTOMER_COOKIE ] ) ) : ''; // phpcs:ignore
			if ( Settings::customer_distinct_id( $user_id ) !== $current ) {
				$this->set_customer_cookie( $user_id, 0 );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Customer cookie refresh failed: ' . $e->getMessage() );
		}
	}

	private function set_customer_cookie( int $user_id, int $expire ): void {
		if ( ! $user_id || headers_sent() || ! Settings::browser_module_runs() || ! Settings::on( 'identify_customers' ) ) {
			return;
		}
		if ( ! Audience::records_user( $user_id ) ) {
			return;
		}
		// Only with analytics consent: the cookie exists to link the visitor to the account in PostHog.
		if ( ! self::analytics_consent() ) {
			return;
		}
		$value = Settings::customer_distinct_id( $user_id );
		setcookie( self::CUSTOMER_COOKIE, $value, array( 'expires' => $expire, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::CUSTOMER_COOKIE ] = $value;
	}

	public function clear_customer_cookie(): void {
		if ( isset( $_COOKIE[ self::CUSTOMER_COOKIE ] ) && ! headers_sent() ) {
			setcookie( self::CUSTOMER_COOKIE, '', array( 'expires' => time() - YEAR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
			unset( $_COOKIE[ self::CUSTOMER_COOKIE ] );
		}
	}

	/** Whether this request's visitor allowed analytics (the browser module's cookies exist only then). */
	private static function analytics_consent(): bool {
		$ckey    = Settings::token();
		$consent = Consent::evaluate( Settings::consent_mode(), $_COOKIE, $ckey, null !== Util::cookie( $_COOKIE, Identity::cookie_name( $ckey ) ), function_exists( 'wp_has_consent' ) ? 'wp_has_consent' : null ); // phpcs:ignore
		return Consent::allows_linking( $consent );
	}

	public function mark_logout(): void {
		if ( headers_sent() ) {
			return;
		}
		$this->clear_customer_cookie();
		Visitor::clear();
		if ( ! Settings::browser_module_runs() || ! self::analytics_consent() ) {
			return; // Without consent posthog-js holds no identity to reset.
		}
		// Read by the browser module on the next page (never printed into cacheable HTML).
		setcookie( self::LOGOUT_COOKIE, '1', array( 'expires' => time() + 600, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'samesite' => 'Lax', 'secure' => is_ssl() ) );
	}

	/** Data for browser events on this page; the same for every visitor of the page. */
	private function page_data(): array {
		if ( ! function_exists( 'is_product' ) ) {
			return array();
		}
		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof \WC_Product ) {
				$row = OrderData::product_fields( $product, $product->get_id(), 0, OrderData::FOR_PAGE );
				return array(
					'type'    => 'product',
					'product' => array_filter( $row + array( 'name' => $product->get_name(), 'price' => (float) $product->get_price(), 'product_type' => $product->get_type() ), static fn( $v ) => '' !== $v && null !== $v ),
				);
			}
		}
		if ( is_product_category() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				return array( 'type' => 'category', 'category' => array( 'page_type' => 'category', 'category_id' => $term->term_id, 'category_name' => $term->name, 'category_slug' => $term->slug ) );
			}
		}
		if ( is_cart() && WC()->cart ) {
			$lines = array();
			$pos   = 1;
			foreach ( WC()->cart->get_cart() as $item ) {
				$lines[] = OrderData::cart_line( $item, $pos++, OrderData::FOR_PAGE );
			}
			return array(
				'type' => 'cart',
				'cart' => array(
					'page_type'       => 'cart',
					'total'           => (float) WC()->cart->get_total( 'edit' ),
					'item_count'      => (int) WC()->cart->get_cart_contents_count(),
					'products'        => $lines,
					'products_string' => OrderData::products_string( $lines ),
				),
			);
		}
		return array();
	}

	/* ------------------------------------------------------ thank-you page link */

	/**
	 * On a thank-you page opened with the order's secret key (WooCommerce's order-received page, or a
	 * funnel plugin's own thank-you page carrying `key`), the browser that opens it belongs to the
	 * buyer: WooCommerce shows the order to it on that basis. The browser module then joins this
	 * browser to the identity the order was placed with (bootstrap if it has no PostHog history yet,
	 * alias otherwise). Registered customers' orders are linked by logging in instead. Order-pay
	 * pages carry the same key but come before payment (retries, invoices): they are not joined.
	 */
	public function detect_order_page(): void {
		try {
			if ( ! Settings::browser_module_runs() || ! Settings::on( 'link_return_browser' ) || is_admin() ) {
				return;
			}
			if ( isset( $_GET['pay_for_order'] ) || ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return;
			}
			$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' === $key || 0 !== strpos( $key, 'wc_order_' ) ) {
				return;
			}
			$order_id = wc_get_order_id_by_order_key( $key );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $key ) ) {
				return;
			}
			if ( isset( $_GET['order_id'] ) && (int) $_GET['order_id'] !== (int) $order_id ) { // phpcs:ignore
				return;
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			$identity = Orders::identity( $order );
			$created  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
			$max_age  = (int) apply_filters( 'w99_phwc_return_link_max_age', DAY_IN_SECONDS, $order );
			$link     = array( 'order_id' => (string) $order->get_id(), 'linkable' => false );
			$linked   = ! empty( $identity['linked'] );
			if ( $linked && 'anonymous' === ( $identity['user_state'] ?? 'anonymous' ) && ! $order->get_customer_id() && ( time() - $created ) <= $max_age ) {
				$link['linkable'] = true;
				$capture          = strtotime( (string) ( $identity['captured_at'] ?? '' ) );
				// Continue the checkout session only while it is still running (30 minutes of inactivity end it).
				if ( ! empty( $identity['session_id'] ) && $capture && ( time() - $capture ) < 1800 ) {
					$link['session_id'] = $identity['session_id'];
				}
			} else {
				$link['reason'] = ! $linked ? 'order_not_linked' : ( $order->get_customer_id() ? 'registered_customer' : ( 'anonymous' !== ( $identity['user_state'] ?? 'anonymous' ) ? 'identified_buyer' : 'too_old' ) );
			}
			// Placed before the visitor could be recorded: a browser that accepts here links it (Visitor::ajax_link()).
			if ( ! $linked && ( time() - $created ) <= $max_age && Orders::awaits_link( $order ) ) {
				$link['claimable'] = true;
			}
			if ( $linked ) {
				// Also lets the browser tell "same browser" from "another browser" (device ID survives a sign-in).
				$link['distinct_id'] = $identity['distinct_id'];
				if ( ! empty( $identity['device_id'] ) ) {
					$link['device_id'] = $identity['device_id'];
				}
			}
			$this->order_link = $link;
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Thank-you page link failed: ' . $e->getMessage() );
		}
	}

	/* -------------------------------------------------- server behavioural events */

	public function checkout_started_on_template( $template ) {
		$this->checkout_started();
		return $template;
	}

	/** `Checkout Started`: checkout page requested with a cart; once per cart within 30 minutes. */
	public function checkout_started(): void {
		if ( $this->checkout_checked ) {
			return;
		}
		try {
			if ( ! Settings::active() || ! Settings::on( 'ev_checkout' ) || is_admin() || wp_doing_ajax() ) {
				$this->checkout_checked = true;
				return;
			}
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
				$this->checkout_checked = true;
				return;
			}
			$cart = WC()->cart;
			if ( ! $cart || $cart->is_empty() ) {
				return; // Not decided yet: a funnel checkout may still fill the cart while the page renders.
			}
			$this->checkout_checked = true;
			$identity               = self::visitor_identity();
			if ( null === $identity ) {
				return;
			}
			$hash = $cart->get_cart_hash();
			// A page loaded in advance is marked counted only once it is shown (release_shown()).
			if ( ! self::checkout_new( $hash, ! self::held_until_shown() ) ) {
				return;
			}
			$lines = array();
			$pos   = 1;
			foreach ( $cart->get_cart() as $item ) {
				$lines[] = OrderData::cart_line( $item, $pos++ );
			}
			$total = (float) $cart->get_total( 'edit' );
			$props = array(
				'affiliation'     => 'WooCommerce',
				'value'           => Util::money( $total ),
				'revenue'         => Util::money( $total ),
				'subtotal'        => Util::money( $cart->get_subtotal() ),
				'shipping'        => Util::money( $cart->get_shipping_total() ),
				'tax'             => Util::money( $cart->get_total_tax() ),
				'discount'        => Util::money( $cart->get_discount_total() ),
				'coupon'          => implode( ',', $cart->get_applied_coupons() ),
				'currency'        => get_woocommerce_currency(),
				'products'        => $lines,
				'products_string' => OrderData::products_string( $lines ),
				'quantity'        => array_sum( array_column( $lines, 'quantity' ) ),
				'checkout_id'     => $hash,
				'checkout_type'   => self::checkout_type(),
				'speculative_load' => self::speculative() ? true : null,
			);
			$bucket = (string) floor( time() / self::CHECKOUT_DEDUPE );
			$event  = Events::build( 'Checkout Started', 'checkout_started', array( $identity['distinct_id'], $hash, $bucket ), Util::utc(), $props, $identity, self::request_context() );
			$this->send( $event, $hash );
		} catch ( \Throwable $e ) {
			$this->checkout_checked = true;
			Plugin::log( 'error', 'Checkout Started failed: ' . $e->getMessage() );
		}
	}

	/** Marks the start of WC_Cart::add_to_cart() (the filter runs inside it, before quantities change). */
	public function adding_to_cart( $quantity ) {
		$this->adding = true;
		return $quantity;
	}

	public function on_add_to_cart( $key = '', $product_id = 0, $quantity = 0, $variation_id = 0, $variation = array(), $item_data = array() ): void {
		$this->adding = false;
		try {
			$product = wc_get_product( $variation_id ? $variation_id : $product_id );
			$this->cart_event( 'Product Added', 'product_added', $product, (int) $product_id, (int) $variation_id, (int) $quantity );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Product Added failed: ' . $e->getMessage() );
		}
	}

	public function on_remove_cart_item( $key = '', $cart = null ): void {
		try {
			$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
			$item = $cart ? $cart->get_cart_item( $key ) : array();
			if ( ! empty( $item ) ) {
				$this->cart_event( 'Product Removed', 'product_removed', $item['data'] ?? null, (int) $item['product_id'], (int) ( $item['variation_id'] ?? 0 ), (int) $item['quantity'] );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Product Removed failed: ' . $e->getMessage() );
		}
	}

	public function on_quantity_update( $key = '', $quantity = 0, $old_quantity = 0, $cart = null ): void {
		if ( $this->adding ) {
			return; // Adding more of a line already in the cart: woocommerce_add_to_cart reports it once.
		}
		try {
			$cart  = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
			$delta = (int) $quantity - (int) $old_quantity;
			$item  = $cart ? $cart->get_cart_item( $key ) : array();
			if ( 0 !== $delta && ! empty( $item ) ) {
				$this->cart_event( $delta > 0 ? 'Product Added' : 'Product Removed', $delta > 0 ? 'product_added' : 'product_removed', $item['data'] ?? null, (int) $item['product_id'], (int) ( $item['variation_id'] ?? 0 ), abs( $delta ) );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Cart quantity event failed: ' . $e->getMessage() );
		}
	}

	private function cart_event( string $event, string $kind, $product, int $product_id, int $variation_id, int $quantity ): void {
		try {
			if ( ! Settings::active() || ! Settings::on( 'product_removed' === $kind ? 'ev_cart_removed' : 'ev_cart' ) || ! $product instanceof \WC_Product || $quantity <= 0 ) {
				return;
			}
			$source = self::cart_source();
			if ( 'product_added' === $kind && 'checkout_page' === $source && ! Settings::on( 'cart_checkout_fill' ) ) {
				return; // The checkout page filled the cart while loading: not the shopper adding (Events → Carts the checkout page fills).
			}
			$identity = self::visitor_identity();
			if ( null === $identity ) {
				return;
			}
			$row   = OrderData::product_fields( $product, $product_id, $variation_id );
			$price = (float) $product->get_price();
			$props = $row + array(
				'name'     => $product->get_name(),
				'price'    => Util::money( $price ),
				'quantity' => $quantity,
				'value'    => Util::money( $price * $quantity ),
				'currency' => get_woocommerce_currency(),
				'cart_id'  => WC()->cart ? WC()->cart->get_cart_hash() : '',
				'source'   => $source,
				'speculative_load' => self::speculative() ? true : null,
			);
			$this->send( Events::build( $event, $kind, array( Uuid::v4() ), Util::utc(), $props, $identity, self::request_context() ) );
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', $event . ' failed: ' . $e->getMessage() );
		}
	}

	public function on_coupon_applied( $code = '' ): void {
		$this->coupon_event( 'Coupon Applied', 'coupon_applied', (string) $code );
	}

	public function on_coupon_removed( $code = '' ): void {
		$this->coupon_event( 'Coupon Removed', 'coupon_removed', (string) $code );
	}

	private function coupon_event( string $event, string $kind, string $code ): void {
		try {
			if ( ! Settings::active() || ! Settings::on( 'coupon_removed' === $kind ? 'ev_coupon_removed' : 'ev_coupon' ) || '' === $code ) {
				return;
			}
			$identity = self::visitor_identity();
			if ( null === $identity ) {
				return;
			}
			$coupon  = new \WC_Coupon( $code );
			$context = self::request_context();
			$at      = Util::utc();
			$build   = function () use ( $event, $kind, $code, $coupon, $identity, $context, $at ) {
				try {
					$cart  = WC()->cart;
					$props = array(
						'coupon_id'   => $code,
						'coupon_name' => $coupon->get_description() ?: $code,
						// Applied: the discount once totals include it. Removed: the discount the cart had (and lost).
						'discount'    => Util::money( $cart ? $cart->get_coupon_discount_amount( $code, true ) : 0 ),
						'cart_id'     => $cart ? $cart->get_cart_hash() : '',
						'currency'    => get_woocommerce_currency(),
					);
					$this->queue->enqueue_event( Events::build( $event, $kind, array( Uuid::v4() ), $at, $props, $identity, $context ) );
				} catch ( \Throwable $e ) {
					Plugin::log( 'error', $event . ' failed: ' . $e->getMessage() );
				}
			};
			if ( 'Coupon Applied' === $event ) {
				// WooCommerce fires woocommerce_applied_coupon before it recalculates the cart.
				$done = false;
				$once = function () use ( &$done, $build ) {
					if ( ! $done ) {
						$done = true;
						$build();
					}
				};
				add_action( 'woocommerce_after_calculate_totals', $once, 999 );
				add_action( 'shutdown', $once, 1 );
			} else {
				$build();
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', $event . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * The visitor making this request (Visitor::current()): posthog-js's ID, else the shop's visitor
	 * ID, created now if there is none. Null when the visitor may not be recorded: nothing is sent then.
	 */
	public static function visitor_identity(): ?array {
		return Visitor::current( true );
	}

	/** What the server knows about this request: URL (the referring page for AJAX), referrer, IP, user agent. */
	public static function request_context(): array {
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore
		$ajax    = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['wc-ajax'] ); // phpcs:ignore
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore
		return array(
			'current_url' => $ajax ? $referer : home_url( $uri ),
			'referrer'    => $ajax ? '' : $referer,
			'ip'          => class_exists( 'WC_Geolocation' ) ? \WC_Geolocation::get_ip_address() : '',
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '', // phpcs:ignore
		);
	}

	/** 99Trace's browser module runs on this page: on in settings, and the request may be recorded (Audience). */
	private static function module_on_page(): bool {
		return Settings::browser_module_runs() && ! self::is_staff();
	}

	/**
	 * This request's server events wait until the page is shown: the browser loaded it in advance and
	 * 99Trace's browser module, which reports it shown, runs on it. Otherwise they are sent at once.
	 */
	private static function held_until_shown(): bool {
		return self::speculative() && self::module_on_page() && (bool) apply_filters( 'w99_phwc_hold_speculative', true );
	}

	/** Whether this cart's checkout was not counted in the last 30 minutes (WooCommerce session); $record counts it now. */
	private static function checkout_new( string $hash, bool $record ): bool {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( ! $session ) {
			return true;
		}
		$last = $session->get( 'w99_phwc_checkout' );
		if ( is_array( $last ) && ( $last['hash'] ?? '' ) === $hash && time() - (int) ( $last['time'] ?? 0 ) < self::CHECKOUT_DEDUPE ) {
			return false;
		}
		if ( $record ) {
			$session->set( 'w99_phwc_checkout', array( 'hash' => $hash, 'time' => time() ) );
		}
		return true;
	}

	/**
	 * A server event of this request: sent at its end, or, on a page the browser loaded in advance, held
	 * until the page is shown (release_shown()). A page that is never opened sends nothing.
	 *
	 * @param string|null $checkout_hash The cart of a Checkout Started, counted once it is sent.
	 */
	private function send( array $event, ?string $checkout_hash = null ): void {
		if ( ! self::held_until_shown() ) {
			$this->queue->enqueue_event( $event );
			return;
		}
		if ( '' === $this->shown_token ) {
			$this->shown_token = bin2hex( random_bytes( 16 ) );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // The page carries this visitor's token: no page cache may serve it to others.
			}
		}
		$this->shown_held[] = array( 'event' => $event, 'checkout' => $checkout_hash );
		set_transient( self::SHOWN_PREFIX . $this->shown_token, $this->shown_held, self::SHOWN_WAIT );
	}

	/** End of a page that holds events: its token for the browser module (also in the module's settings when known in time). */
	public function print_shown(): void {
		if ( '' === $this->shown_token || ! function_exists( 'wp_print_inline_script_tag' ) ) {
			return;
		}
		wp_print_inline_script_tag(
			'window.w99phwc&&(window.w99phwc.shown=' . wp_json_encode( $this->shown_token ) . ');',
			array( 'data-cookieconsent' => 'ignore', 'data-no-optimize' => '1', 'data-no-defer' => '1' )
		);
	}

	/** The browser module reports a page loaded in advance as shown: send what it holds. */
	public function ajax_shown(): void {
		$sent = 0;
		try {
			nocache_headers();
			$sent = $this->release_shown( isset( $_POST['t'] ) && is_string( $_POST['t'] ) ? sanitize_key( wp_unslash( $_POST['t'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the single-use random token is the proof; it releases only events the server built.
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Releasing the events of a page loaded in advance failed: ' . $e->getMessage() );
		}
		wp_send_json( array( 'sent' => $sent ) );
	}

	/**
	 * Send the events a page loaded in advance holds, once: the token is deleted first. A Checkout Started
	 * whose cart was counted in the last 30 minutes meanwhile (the visitor opened the checkout another way)
	 * is dropped. Returns the number of events sent.
	 */
	public function release_shown( string $token ): int {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return 0;
		}
		$held = get_transient( self::SHOWN_PREFIX . $token );
		if ( ! is_array( $held ) ) {
			return 0;
		}
		delete_transient( self::SHOWN_PREFIX . $token );
		if ( ! Settings::active() ) {
			return 0;
		}
		$sent = 0;
		foreach ( $held as $entry ) {
			$event = is_array( $entry ) ? ( $entry['event'] ?? null ) : null;
			if ( ! is_array( $event ) ) {
				continue;
			}
			$hash = $entry['checkout'] ?? null;
			if ( is_string( $hash ) && ! self::checkout_new( $hash, true ) ) {
				continue;
			}
			$this->queue->enqueue_event( $event );
			$sent++;
		}
		return $sent;
	}

	/**
	 * A page the browser loaded speculatively: WordPress (6.8+) asks browsers to prefetch same-site links,
	 * by default when the shopper presses on a link, so the request that renders a funnel checkout (and
	 * fills its cart) can arrive just before the click completes, and the click then shows the prefetched
	 * page without asking the shop again. A press or a touch while scrolling on a phone also prefetches
	 * pages the visitor never opens (3 of 4 on a live shop in November 2026). Server events from such a
	 * request are marked `speculative_load` and wait until the page is shown (send()).
	 */
	private static function speculative(): bool {
		$purpose = strtolower( (string) ( $_SERVER['HTTP_SEC_PURPOSE'] ?? ( $_SERVER['HTTP_PURPOSE'] ?? '' ) ) ); // phpcs:ignore
		return false !== strpos( $purpose, 'prefetch' ) || false !== strpos( $purpose, 'prerender' );
	}

	private static function cart_source(): string {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'store_api';
		}
		if ( isset( $_GET['wc-ajax'] ) || wp_doing_ajax() ) { // phpcs:ignore
			return 'ajax';
		}
		if ( function_exists( 'is_checkout' ) && did_action( 'wp' ) && is_checkout() ) {
			return 'checkout_page'; // e.g. a funnel checkout that fills the cart when it loads
		}
		if ( isset( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore
			return 'url_or_form';
		}
		return 'other';
	}

	private static function checkout_type(): string {
		if ( is_singular( 'wfacp_checkout' ) || ( class_exists( 'WFACP_Common' ) && method_exists( 'WFACP_Common', 'get_id' ) && (int) \WFACP_Common::get_id() > 0 ) ) {
			return 'funnelkit';
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', (int) wc_get_page_id( 'checkout' ) ) ) {
			return 'block';
		}
		return 'classic';
	}

	/** Not recorded here: a signed-in user who is not recorded, or a preview load (Audience). */
	private static function is_staff(): bool {
		return ! Audience::records_request();
	}
}
