<?php
/**
 * Admin: settings (WooCommerce → Settings → 99Trace, also under WooCommerce → 99Trace), the PostHog box on each
 * order, and the coverage report (WooCommerce → Status → 99Trace).
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'admin.php?page=wc-settings&tab=99trace';

	public function register(): void {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'settings_page' ) );
		add_action( 'woocommerce_admin_field_w99_phwc_events', array( self::class, 'render_events' ) );
		add_action( 'woocommerce_update_options_99trace', array( $this, 'saved' ) );
		add_action( 'admin_init', array( $this, 'old_address' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_filter( 'plugin_action_links_' . plugin_basename( W99_PHWC_TRACE_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_post_w99_phwc_confirm_site', array( $this, 'confirm_site' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Settings::PREFIX . 'posthog_js_options', array( self::class, 'sanitize_json' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $this, 'order_box' ) );
		add_filter( 'woocommerce_admin_status_tabs', array( $this, 'status_tab' ) );
		add_action( 'woocommerce_admin_status_content_99trace', array( $this, 'coverage' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/** The 99Trace tab under WooCommerce → Settings (WooCommerce's settings classes exist only from here on). */
	public function settings_page( $pages ) {
		if ( class_exists( '\WC_Settings_Page' ) ) {
			$pages[] = new SettingsPage();
		}
		return $pages;
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', __( '99Trace (PostHog)', '99trace-integration-with-posthog-for-woocommerce' ), '99Trace', 'manage_woocommerce', self::PAGE );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( self::PAGE ) ) . '">' . esc_html__( 'Settings', '99trace-integration-with-posthog-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Old bookmarks: 0.3.0's settings under WooCommerce → Settings → Advanced, and 99Hog's own tabs (before 1.0.0),
	 * lead to the 99Trace tab.
	 */
	public function old_address(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- redirects only, nothing is changed.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'wc-settings' === $page && ( ( 'advanced' === $tab && 'w99_phwc' === $section ) || '99hog' === $tab ) ) {
			wp_safe_redirect( admin_url( self::PAGE . ( '99hog' === $tab && '' !== $section ? '&section=' . $section : '' ) ) );
			exit;
		}
		if ( 'wc-status' === $page && '99hog' === $tab ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-status&tab=99trace' ) );
			exit;
		}
	}

	/**
	 * Tabs of the settings page: section ID => title.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			''         => __( 'Connect', '99trace-integration-with-posthog-for-woocommerce' ),
			'consent'  => __( 'Consent', '99trace-integration-with-posthog-for-woocommerce' ),
			'events'   => __( 'Events', '99trace-integration-with-posthog-for-woocommerce' ),
			'browser'  => __( 'Browser features', '99trace-integration-with-posthog-for-woocommerce' ),
			'visitors' => __( 'Visitors & orders', '99trace-integration-with-posthog-for-woocommerce' ),
			'advanced' => __( 'Advanced', '99trace-integration-with-posthog-for-woocommerce' ),
		);
	}

	/**
	 * What each event is, for the Events table (keys of Settings::EVENTS).
	 *
	 * @return array<string, string>
	 */
	public static function event_descriptions(): array {
		return array(
			'order_completed'           => __( 'A paid order, sent once at the moment of payment, whichever browser the buyer finishes in.', '99trace-integration-with-posthog-for-woocommerce' ),
			'order_refunded'            => __( 'A full or partial refund of a paid order, with the refunded amount. Only for purchases PostHog received (needs Order Completed on).', '99trace-integration-with-posthog-for-woocommerce' ),
			'order_cancelled'           => __( 'A paid order that was cancelled later (its revenue is taken back). Only for purchases PostHog received (needs Order Completed on).', '99trace-integration-with-posthog-for-woocommerce' ),
			'payment_failed'            => __( 'An order whose payment failed or was cancelled at the bank before it was paid.', '99trace-integration-with-posthog-for-woocommerce' ),
			'checkout_started'          => __( 'The visitor opened the checkout with items in the cart (at most once per cart every 30 minutes).', '99trace-integration-with-posthog-for-woocommerce' ),
			'product_added'             => __( 'A product was added to the cart.', '99trace-integration-with-posthog-for-woocommerce' ),
			'product_removed'           => __( 'A product was removed from the cart, or its quantity lowered.', '99trace-integration-with-posthog-for-woocommerce' ),
			'coupon_applied'            => __( 'A coupon was added to the cart, with its discount.', '99trace-integration-with-posthog-for-woocommerce' ),
			'coupon_removed'            => __( 'A coupon was taken off the cart.', '99trace-integration-with-posthog-for-woocommerce' ),
			'product_viewed'            => __( 'A product page was viewed, with the product, its price and category.', '99trace-integration-with-posthog-for-woocommerce' ),
			'category_viewed'           => __( 'A product category page was viewed.', '99trace-integration-with-posthog-for-woocommerce' ),
			'cart_viewed'               => __( 'The cart page was viewed, with its contents.', '99trace-integration-with-posthog-for-woocommerce' ),
			'order_confirmation_viewed' => __( 'The thank-you page was seen (once per order and browser), with how the browser was joined to the buyer.', '99trace-integration-with-posthog-for-woocommerce' ),
			'pageview'                  => __( 'Every page view. PostHog\'s web analytics, funnels and paths are built on it.', '99trace-integration-with-posthog-for-woocommerce' ),
			'pageleave'                 => __( 'Leaving a page, with time on page and how far the visitor scrolled.', '99trace-integration-with-posthog-for-woocommerce' ),
			'autocapture'               => __( 'Clicks, form submissions and changes, recorded automatically.', '99trace-integration-with-posthog-for-woocommerce' ),
			'dead_click'                => __( 'Clicks on something that does nothing (no page change, no reaction): a sign of confusion.', '99trace-integration-with-posthog-for-woocommerce' ),
		);
	}

	/**
	 * Label and description of each browser feature that has a switch in the PostHog project (keys of Settings::FEATURES).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function feature_texts(): array {
		return array(
			'autocapture'         => array( __( 'Autocapture (clicks and form changes)', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Clicks, form submissions and changes, recorded automatically ($autocapture).', '99trace-integration-with-posthog-for-woocommerce' ) ),
			'capture_dead_clicks' => array( __( 'Dead clicks', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Clicks on something that does nothing, a sign of confusion ($dead_click). PostHog counts a click as dead only if nothing on the page changes for 2.5 seconds after it.', '99trace-integration-with-posthog-for-woocommerce' ) ),
			'ft_web_vitals'       => array( __( 'Page speed (web vitals)', '99trace-integration-with-posthog-for-woocommerce' ), __( 'How fast pages load and react, measured in visitors\' browsers ($web_vitals). Overriding it never changes network timing in recordings, which follows PostHog\'s replay settings.', '99trace-integration-with-posthog-for-woocommerce' ) ),
			'ft_heatmaps'         => array( __( 'Heatmaps', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Where visitors click, move and scroll on each page.', '99trace-integration-with-posthog-for-woocommerce' ) ),
			'ft_replay'           => array( __( 'Session replay', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Recordings of visits that you can watch in PostHog. Always off: never recorded on this shop.', '99trace-integration-with-posthog-for-woocommerce' ) ),
			'ft_surveys'          => array( __( 'Surveys', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Lets surveys made in PostHog appear on the shop.', '99trace-integration-with-posthog-for-woocommerce' ) ),
			'ft_exceptions'       => array( __( 'JavaScript errors (error tracking)', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Sends errors in the shop\'s pages to PostHog\'s error tracking ($exception).', '99trace-integration-with-posthog-for-woocommerce' ) ),
		);
	}

	/** The settings of one tab, in WooCommerce's settings format. */
	public static function settings_for( string $section ): array {
		$p = Settings::PREFIX;
		if ( null === get_option( $p . 'before_consent', null ) ) {
			// 0.3.0 kept this choice as the consent-switch checkbox: carry it over so the page shows (and saves) it.
			update_option( $p . 'before_consent', Settings::before_consent() );
			Settings::flush();
		}
		Settings::normalize_features(); // 0.4.0's tick boxes, shown and saved as the choices they meant
		$sections = self::sections();
		$chk      = static fn( $id, $title, $desc = '' ) => array( 'title' => $title, 'id' => $p . $id, 'type' => 'checkbox', 'default' => Settings::DEFAULTS[ $id ] ?? 'yes', 'desc' => $desc );
		$title    = static fn( $id, $text ) => array( 'title' => $sections[ $id ], 'type' => 'title', 'id' => 'w99_phwc_' . ( '' === $id ? 'connect' : $id ), 'desc' => $text );
		$end      = static fn( $id ) => array( 'type' => 'sectionend', 'id' => 'w99_phwc_' . ( '' === $id ? 'connect' : $id ) );

		switch ( $section ) {
			case 'consent':
				return array(
					$title( 'consent', __( 'When PostHog may record a visitor. Consent rules differ by country and by what you record, so this is your decision as the shop owner: 99Trace supports each choice and does not decide for you. The default records every visitor, which gives PostHog complete data; the other choices follow the answer visitors give your cookie banner (Cookiebot, or a plugin using the WP Consent API) for "statistics".', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array(
						'title'   => __( 'Consent', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'consent',
						'type'    => 'select',
						'default' => 'none',
						'options' => array(
							'none'           => __( 'Track every visitor: I handle consent myself', '99trace-integration-with-posthog-for-woocommerce' ),
							'auto'           => __( 'Automatic: follow the cookie banner (Cookiebot plugin, else WP Consent API; with neither, PostHog stays off in the browser)', '99trace-integration-with-posthog-for-woocommerce' ),
							'cookiebot'      => __( 'Cookiebot: wait for "statistics" consent (also when Cookiebot is loaded by the theme or Google Tag Manager)', '99trace-integration-with-posthog-for-woocommerce' ),
							'wp_consent_api' => __( 'WP Consent API: wait for "statistics" consent', '99trace-integration-with-posthog-for-woocommerce' ),
							'posthog_opt_in' => __( 'My site calls posthog.opt_in_capturing() itself', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( '<strong>Track every visitor</strong> (default) records everyone from the first page, never waits for a banner, and is never blocked by Cookiebot: PostHog and the shop store the visitor\'s ID in cookies and send events for every visitor. Choose it if you have decided that analytics needs no consent on this shop, or that you collect it another way. The other choices wait for "statistics" consent; what happens before the answer is set below. Cart and checkout events made before the answer are not sent.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'Before the visitor answers the banner', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'before_consent',
						'type'    => 'select',
						'default' => 'off',
						'options' => array(
							'off'    => __( 'Wait: load PostHog only after consent (nothing before)', '99trace-integration-with-posthog-for-woocommerce' ),
							'hold'   => __( 'Record the visit, send it when the visitor accepts', '99trace-integration-with-posthog-for-woocommerce' ),
							'switch' => __( 'Load PostHog switched off: experiments only (nothing recorded)', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( 'Only with Cookiebot or the WP Consent API. <strong>Wait</strong>: PostHog starts after consent; what the visitor did before is not recorded. <strong>Record the visit</strong>: PostHog records pages and clicks in the page\'s memory, stores nothing in the browser and sends nothing; when the visitor accepts, all of it is sent under one visitor and PostHog runs normally from then on; on a refusal the recording is thrown away. <strong>Switched off</strong>: experiments get their variant at once and nothing is recorded; on consent the page view and the experiment exposure are sent (needs Browser features → Feature flags and experiments; without it, this works as Wait). With Record the visit and Switched off, PostHog is asked for experiment variants before consent.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$chk( 'hold_across_pages', __( 'Record the visit: keep it across pages until the answer', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Off: only the page where the visitor answers is sent. On: the recording of earlier pages is kept in the browser\'s session storage (deleted when the tab closes) and sent too. This stores data in the browser before consent: switch it on only where that is allowed.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array(
						'title'   => __( 'Orders without consent', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'unconsented_orders',
						'type'    => 'select',
						'default' => 'anonymous',
						'options' => array(
							'anonymous' => __( 'Counted anonymously (no visitor, IP or person)', '99trace-integration-with-posthog-for-woocommerce' ),
							'skip'      => __( 'Not sent', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( 'An order whose buyer refused (or never answered) the banner. <strong>Counted anonymously</strong> (default): the purchase and its revenue are sent without anything that identifies the buyer, so revenue in PostHog stays complete. <strong>Not sent</strong>: PostHog does not see it. A buyer who accepts on the thank-you page before the order is sent (Wait before sending an order) gets the order linked to their visit.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$end( 'consent' ),
				);

			case 'events':
				return array(
					$title( 'events', __( 'Every event 99Trace sends to PostHog. Switch an event off, or give it your own name in PostHog (leave the name empty for the standard one). A renamed event is still sent exactly once; reports, funnels and destinations in PostHog that use the old name must be changed to the new one. PostHog\'s own automatic events can be switched off but keep their names, because PostHog\'s built-in reports rely on them. Browser and PostHog events need Connect → Run PostHog in the browser.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array( 'type' => 'w99_phwc_events', 'id' => 'w99_phwc_events_table', 'is_option' => false ),
					array(
						'title'   => __( 'Carts the checkout page fills', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'cart_checkout_fill',
						'type'    => 'select',
						'default' => 'no',
						'options' => array(
							'no'  => __( 'Not sent (recommended)', '99trace-integration-with-posthog-for-woocommerce' ),
							'yes' => __( 'Sent as Product Added', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( 'Some checkout pages, such as FunnelKit funnels, put the product in the cart when they open. The shopper did not add it, and Checkout Started already records the visit with its products. <strong>Not sent</strong> (default): Product Added only when the shopper adds a product (a button, a link, a change on the cart or the checkout). <strong>Sent</strong>: every such page load also sends Product Added, marked <code>source: checkout_page</code>, and ad platforms fed from Product Added count it as an add to cart.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'Product custom fields', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'product_meta',
						'type'    => 'select',
						'default' => 'none',
						'options' => array(
							'none'    => __( 'Not sent (recommended)', '99trace-integration-with-posthog-for-woocommerce' ),
							'visible' => __( 'Visible custom fields', '99trace-integration-with-posthog-for-woocommerce' ),
							'all'     => __( 'All custom fields, including hidden ones', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( 'Every product in an event carries everything WooCommerce shows about it: its attributes (such as the bundle or the colour chosen), categories, tags, brand, prices, stock status, dimensions and shipping class. Custom fields are extra data other plugins and themes save on a product; some of it is internal, such as a purchase cost or a supplier code. <strong>Not sent</strong> (default): none of it. <strong>Visible</strong>: the fields WordPress shows in the product editor\'s Custom Fields box (names not starting with an underscore). <strong>All</strong>: hidden fields too, and WooCommerce\'s own cost of goods. Values longer than 500 characters are cut. Custom fields go only into events the shop\'s server sends, never into the page.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$end( 'events' ),
				);

			case 'browser':
				$features = array();
				$texts    = self::feature_texts();
				foreach ( Settings::FEATURES as $id => $may_force_on ) {
					$choices = array( 'posthog' => __( 'PostHog\'s setting (recommended)', '99trace-integration-with-posthog-for-woocommerce' ) );
					if ( $may_force_on ) {
						$choices['on'] = __( 'Always on', '99trace-integration-with-posthog-for-woocommerce' );
					}
					$choices['off'] = __( 'Always off', '99trace-integration-with-posthog-for-woocommerce' );
					$features[]     = array(
						'title'   => $texts[ $id ][0],
						'id'      => $p . $id,
						'type'    => 'select',
						'default' => 'posthog',
						'options' => $choices,
						'desc'    => $texts[ $id ][1] . ( $may_force_on ? '' : ' ' . __( 'PostHog\'s setting decides whether it is on; this shop can only switch it off.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					);
				}
				return array_merge(
					array( $title( 'browser', __( 'PostHog features in the visitor\'s browser. Most of them have a switch in the PostHog project\'s own settings (Settings → Project in PostHog). <strong>PostHog\'s setting</strong> (recommended) leaves the choice to that switch, so you change it in PostHog; <strong>Always on</strong> or <strong>Always off</strong> override it on this shop. A change in PostHog reaches pages opened after it, not a page that is already open. On order and account pages replay, heatmaps and element text are always off, because they show the buyer\'s name and address.', '99trace-integration-with-posthog-for-woocommerce' ) ) ),
					$features,
					array(
						$chk( 'ft_rageclick', __( 'Rage clicks', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Several quick clicks on the same spot: a sign of frustration. PostHog has no switch for this one.', '99trace-integration-with-posthog-for-woocommerce' ) ),
						$chk( 'ft_flags', __( 'Feature flags and experiments', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Lets PostHog experiments and feature flags decide what a visitor sees. Off: no experiment can run on this shop.', '99trace-integration-with-posthog-for-woocommerce' ) ),
						$chk( 'ft_cross_subdomain', __( 'One visitor across subdomains', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Recognise the same visitor on shop.example.com and www.example.com.', '99trace-integration-with-posthog-for-woocommerce' ) ),
						$chk( 'ft_mask_text', __( 'Hide all text', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Clicks and recordings never contain the text on the page, on every page (not only order and account pages).', '99trace-integration-with-posthog-for-woocommerce' ) ),
						$end( 'browser' ),
					)
				);

			case 'visitors':
				$recorded = array(
					'no'  => __( 'Not recorded (recommended)', '99trace-integration-with-posthog-for-woocommerce' ),
					'yes' => __( 'Recorded', '99trace-integration-with-posthog-for-woocommerce' ),
				);
				return array(
					$title( 'visitors', __( 'How visitors and orders appear in PostHog.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array(
						'title'   => __( 'Shop staff', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'record_staff',
						'type'    => 'select',
						'default' => 'no',
						'options' => $recorded,
						'desc'    => __( 'Signed-in users who can manage orders: administrators and shop managers. <strong>Not recorded</strong> (default): nothing from them reaches PostHog, so no page views, recordings, experiments, carts or checkouts. A purchase they make is still counted, without their visits.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'Page editors', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'record_editors',
						'type'    => 'select',
						'default' => 'no',
						'options' => $recorded,
						'desc'    => __( 'Signed-in users who can edit pages but not manage orders, such as editors and custom roles for building pages. Their test visits to product and checkout pages would otherwise count as shopping, also in ad platforms fed from PostHog. <strong>Not recorded</strong> (default) works as for shop staff.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'Preview loads', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'record_previews',
						'type'    => 'select',
						'default' => 'no',
						'options' => $recorded,
						'desc'    => __( 'Pages opened in a page builder or as a preview: Elementor\'s editor and preview, FunnelKit\'s previews, WordPress page previews and the Customizer, with the calls they make while open. Only signed-in users can open them. <strong>Not recorded</strong> (default): never sent, whoever opens them.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'Person profiles', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'person_profiles',
						'type'    => 'select',
						'default' => 'always',
						'options' => array(
							'always'          => __( 'Every visitor (recommended)', '99trace-integration-with-posthog-for-woocommerce' ),
							'identified_only' => __( 'Only logged-in customers', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( 'A person profile is the card in PostHog (People) that collects everything one visitor did: pages and clicks from the browser, and purchases and refunds from the shop\'s server. <strong>Every visitor</strong> gives each visitor a card, so you can open a buyer and see their whole journey. <strong>Only logged-in customers</strong> creates cards only for customers who sign in; other visitors\' events are still counted but belong to no card (PostHog bills these "anonymous" events at a lower rate). Browser and server events always follow the same choice.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$chk( 'identify_customers', __( 'Recognise logged-in customers on every device', '99trace-integration-with-posthog-for-woocommerce' ), __( 'A customer who signs in is joined to one person in PostHog, on every device they use. The account is named by an anonymous number, never by e-mail address or name.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					$chk(
						'visitor_cookie',
						__( 'One visitor ID for the browser and the shop', '99trace-integration-with-posthog-for-woocommerce' ),
						__( 'Cart, coupon, checkout and order events are sent by the shop\'s server; page views and clicks by PostHog in the browser. With this on, both use one visitor ID, kept in a first-party cookie (<code>ph_wc_vid</code>) that the server can read: whichever side needs an ID first creates it, and the other uses the same one. So an "add to cart" clicked before PostHog has loaded, or by a visitor whose ad blocker stops PostHog, lands on the same person as the rest of the visit and the order, without PostHog having to merge two visitors. The cookie is written only where the visitor may be recorded (Consent). Off: cart and checkout events are sent only when PostHog\'s own cookie is there.', '99trace-integration-with-posthog-for-woocommerce' )
					),
					$chk( 'person_details', __( 'Customer details on the person', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Sets the buyer\'s e-mail, first and last name and phone as properties of their person in PostHog: as soon as they are typed on the checkout, when an order is paid, and when a customer signs in or creates an account. Only for visitors who may be recorded (Consent), never on an anonymous order, and never inside events or web addresses. PostHog receives them as typed; PostHog\'s destinations (Meta, Google Ads) hash them just before sending. Off: none of these details reach PostHog.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					$chk( 'link_return_browser', __( 'Join the thank-you page\'s browser to the buyer', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Buyers who pay in a bank app often come back in another browser (for example from Instagram to Safari). With this on, that browser continues as the same visitor, so the visit and the purchase stay on one person. Also: an order placed before the visitor answered the cookie banner is linked to them when they accept on its thank-you page, as long as it has not been sent yet.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					$chk( 'send_ip', __( 'Locate buyers by their own IP address', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Purchases are sent by the shop\'s server; this passes the buyer\'s IP address so PostHog shows the buyer\'s country, not the server\'s.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array(
						'title'             => __( 'Wait before sending an order (minutes)', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'                => $p . 'delay_minutes',
						'type'              => 'number',
						'default'           => '10',
						'custom_attributes' => array(
							'min' => 0,
							'max' => 120,
						),
						'desc'              => __( 'A one-click upsell after payment is added to the order: waiting lets it be part of the purchase PostHog receives. The purchase keeps the time of payment. 0–120; 10 recommended.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$end( 'visitors' ),
				);

			case 'advanced':
				return array(
					$title( 'advanced', __( 'For developers and special cases.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array(
						'title'   => __( 'URL parameters never sent', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'strip_params',
						'type'    => 'text',
						'default' => Settings::DEFAULTS['strip_params'],
						'desc'    => __( 'Comma-separated. These parameters are removed from every web address PostHog receives, at any depth. Order keys (wc_order_…) and e-mail addresses are always removed.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'Further posthog-js options (JSON)', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'posthog_js_options',
						'type'    => 'textarea',
						'default' => '',
						'css'     => 'width: 100%; max-width: 640px; height: 120px; font-family: monospace;',
						'desc'    => sprintf(
							/* translators: %s: comma-separated list of posthog-js option names. */
							__( 'Only for options that have no setting above. Any posthog-js option as a JSON object, applied on top of the Browser features. Example: {"session_recording": {"maskAllInputs": true}}. Ignored because 99Trace depends on them: %s.', '99trace-integration-with-posthog-for-woocommerce' ),
							esc_html( implode( ', ', Settings::PROTECTED_JS_OPTIONS ) )
						),
					),
					array(
						'title' => __( 'Read the visitor from key', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'    => $p . 'cookie_key',
						'type'  => 'text',
						'desc'  => __( 'Only while another PostHog plugin still runs PostHog in the browser and 99Trace sends from the server only (a pilot beside it): the project key whose PostHog cookie names the visitor. Empty: that plugin\'s key. Not used once 99Trace runs PostHog in the browser: then the visitor is always in PostHog\'s standard cookie for the project key above, the same cookie other PostHog plugins use, so replacing another plugin with the same project key keeps every visitor.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$chk( 'debug', __( 'Debug logging', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Writes what 99Trace does to WooCommerce → Status → Logs (source 99trace), and to the browser console.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					$end( 'advanced' ),
				);

			default: // Connect.
				$status = '' !== Settings::token()
					? sprintf(
						/* translators: 1: PostHog host address, 2: first characters of the PostHog project key. */
						esc_html__( 'Sending to %1$s, project key %2$s…', '99trace-integration-with-posthog-for-woocommerce' ),
						esc_html( Settings::host() ),
						esc_html( substr( Settings::token(), 0, 10 ) )
					)
					: '<strong>' . esc_html__( 'No project key: nothing is sent.', '99trace-integration-with-posthog-for-woocommerce' ) . '</strong>';
				return array(
					$title( '', esc_html__( 'One PostHog identity across the browser and the shop\'s server: pages and clicks from the browser, purchases and refunds from WooCommerce, on the same person.', '99trace-integration-with-posthog-for-woocommerce' ) . ' ' . $status . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=99trace' ) ) . '">' . esc_html__( 'Coverage report', '99trace-integration-with-posthog-for-woocommerce' ) . '</a>.' ),
					array(
						'title'   => __( 'Mode', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'mode',
						'type'    => 'select',
						'default' => 'off',
						'options' => array(
							'off'  => __( 'Off – do nothing', '99trace-integration-with-posthog-for-woocommerce' ),
							'test' => __( 'Test – capture identities and log events, send nothing', '99trace-integration-with-posthog-for-woocommerce' ),
							'on'   => __( 'On – send', '99trace-integration-with-posthog-for-woocommerce' ),
						),
						'desc'    => __( '<strong>Off</strong>: 99Trace does nothing. <strong>Test</strong>: 99Trace works out every event without sending it: the shop\'s events go to the log (Advanced → Debug logging), and PostHog runs in the browser so you can check it, but records nothing (with Debug logging, the browser console shows each event it would have sent). <strong>On</strong>: events are sent to PostHog.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title' => __( 'Project API key', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'    => $p . 'key',
						'type'  => 'text',
						'desc'  => __( 'The project key (starts with phc_) of the PostHog project to send to: PostHog → Settings → Project → Project API key. Browser and server events go to the same project.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					array(
						'title'   => __( 'PostHog host', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'      => $p . 'host',
						'type'    => 'text',
						'default' => 'https://eu.i.posthog.com',
						'desc'    => __( 'https://eu.i.posthog.com for PostHog EU Cloud, https://us.i.posthog.com for US Cloud.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$chk( 'browser', __( 'Run PostHog in the browser', '99trace-integration-with-posthog-for-woocommerce' ), __( 'Loads PostHog in visitors\' browsers (pages, clicks, experiments). Off: only the shop\'s server sends events. Deactivate any other PostHog plugin first: two PostHog copies on one page split every visitor in two.', '99trace-integration-with-posthog-for-woocommerce' ) ),
					array(
						'title' => __( 'Browser host (proxy)', '99trace-integration-with-posthog-for-woocommerce' ),
						'id'    => $p . 'browser_host',
						'type'  => 'text',
						'desc'  => __( 'Optional. Your own address that forwards to PostHog, e.g. https://ph.example.com, so ad blockers do not block PostHog. Empty: the PostHog host.', '99trace-integration-with-posthog-for-woocommerce' ),
					),
					$end( '' ),
				);
		}
	}

	/** The Events tab's table: one row per event with its switch and its name in PostHog. */
	public static function render_events(): void {
		$sources = array(
			'server'  => __( 'Shop server (WooCommerce)', '99trace-integration-with-posthog-for-woocommerce' ),
			'browser' => __( 'Browser (99Trace)', '99trace-integration-with-posthog-for-woocommerce' ),
			'posthog' => __( 'PostHog automatic', '99trace-integration-with-posthog-for-woocommerce' ),
		);
		$choices = array(
			'posthog' => __( 'PostHog\'s setting', '99trace-integration-with-posthog-for-woocommerce' ),
			'on'      => __( 'Always on', '99trace-integration-with-posthog-for-woocommerce' ),
			'off'     => __( 'Always off', '99trace-integration-with-posthog-for-woocommerce' ),
		);
		$texts   = self::event_descriptions();
		$names   = Settings::custom_event_names();
		echo '<tr><td colspan="2" style="padding-left:0"><table class="widefat striped" style="max-width:1100px"><thead><tr>'
			. '<th style="width:24%">' . esc_html__( 'Event', '99trace-integration-with-posthog-for-woocommerce' ) . '</th>'
			. '<th style="width:16%">' . esc_html__( 'Sent from', '99trace-integration-with-posthog-for-woocommerce' ) . '</th>'
			. '<th style="width:6%">' . esc_html__( 'On', '99trace-integration-with-posthog-for-woocommerce' ) . '</th>'
			. '<th style="width:22%">' . esc_html__( 'Name in PostHog', '99trace-integration-with-posthog-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'What it is', '99trace-integration-with-posthog-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( Settings::EVENTS as $key => $event ) {
			list( $standard, $source, $option ) = $event;
			$what = $texts[ $key ] ?? '';
			if ( isset( Settings::FEATURES[ $option ] ) ) {
				// Autocapture and dead clicks have a switch in the PostHog project too: chosen under Browser features.
				echo '<tr><td><strong>' . esc_html( $standard ) . '</strong></td><td>' . esc_html( $sources[ $source ] ) . '</td>'
					. '<td colspan="2"><a href="' . esc_url( admin_url( self::PAGE . '&section=browser' ) ) . '">' . esc_html( $choices[ Settings::feature( $option ) ] ) . '</a> (' . esc_html__( 'Browser features', '99trace-integration-with-posthog-for-woocommerce' ) . ')</td><td>' . esc_html( $what ) . '</td></tr>';
				continue;
			}
			$on = Settings::on( $option );
			echo '<tr><td><strong>' . esc_html( $standard ) . '</strong></td><td>' . esc_html( $sources[ $source ] ) . '</td>'
				. '<td><input type="hidden" name="w99_phwc_event_on[' . esc_attr( $option ) . ']" value="no"><input type="checkbox" name="w99_phwc_event_on[' . esc_attr( $option ) . ']" value="yes"' . checked( $on, true, false ) . ' aria-label="' . esc_attr(
					/* translators: %s: event name, such as "Order Completed". */
					sprintf( __( '%s on', '99trace-integration-with-posthog-for-woocommerce' ), $standard )
				) . '"></td><td>';
			if ( 'posthog' === $source ) {
				echo '<code>' . esc_html( $standard ) . '</code>';
			} else {
				echo '<input type="text" name="w99_phwc_event_names[' . esc_attr( $key ) . ']" value="' . esc_attr( $names[ $key ] ?? '' ) . '" placeholder="' . esc_attr( $standard ) . '" maxlength="100" style="width:100%">';
			}
			echo '</td><td>' . esc_html( $what ) . '</td></tr>';
		}
		echo '</tbody></table></td></tr>';
	}

	/** Save the Events table (WooCommerce has checked the nonce and the capability before calling this). */
	public static function save_events( array $post ): void {
		$on = isset( $post['w99_phwc_event_on'] ) && is_array( $post['w99_phwc_event_on'] ) ? $post['w99_phwc_event_on'] : array();
		foreach ( Settings::EVENTS as $event ) {
			if ( isset( $on[ $event[2] ] ) && ! isset( Settings::FEATURES[ $event[2] ] ) ) { // Those are saved under Browser features.
				update_option( Settings::PREFIX . $event[2], 'yes' === $on[ $event[2] ] ? 'yes' : 'no' );
			}
		}
		$typed = isset( $post['w99_phwc_event_names'] ) && is_array( $post['w99_phwc_event_names'] ) ? $post['w99_phwc_event_names'] : array();
		list( $kept, $rejected ) = Settings::clean_event_names( $typed );
		update_option( Settings::EVENT_NAMES_OPTION, $kept );
		Settings::flush();
		if ( $rejected && class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: comma-separated list of event names. */
					__( 'These names were not saved, so the standard name is used: %s. A name may not start with "$", be longer than 100 characters, or be the name of another event.', '99trace-integration-with-posthog-for-woocommerce' ),
					implode( ', ', $rejected )
				)
			);
		}
	}

	/** Keep the JSON as typed (WooCommerce's textarea sanitising would turn & into &amp;); validity is reported by a notice. */
	public static function sanitize_json( $value, $option = array(), $raw = '' ) {
		return is_string( $raw ) ? trim( $raw ) : '';
	}

	public function saved(): void {
		Settings::flush();
		if ( 'on' === Settings::mode() ) {
			Settings::arm(); // Records this address only if none is recorded yet.
		}
	}

	/** An administrator confirms that this site, at its new address, is the one that should send. */
	public function confirm_site(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'w99_phwc_confirm_site' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', '99trace-integration-with-posthog-for-woocommerce' ) );
		}
		Settings::arm( true );
		wp_safe_redirect( admin_url( self::PAGE ) );
		exit;
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Settings::active() ) {
			return;
		}
		if ( Settings::site_moved() && 'on' === Settings::mode() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=w99_phwc_confirm_site' ), 'w99_phwc_confirm_site' );
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a class="button" href="%3$s">%4$s</a></p></div>',
				esc_html__( '99Trace has stopped sending to PostHog.', '99trace-integration-with-posthog-for-woocommerce' ),
				wp_kses(
					sprintf(
						/* translators: 1: the site address sending was switched on for, 2: this site's address. */
						__( 'Sending was switched on for %1$s, and this site is %2$s: a copy of the shop must not send into the live project. On a copy, set the mode to Off or Test, or enter a test project\'s key. If this is the live shop at a new address:', '99trace-integration-with-posthog-for-woocommerce' ),
						'<code>' . esc_html( Settings::locked_address() ) . '</code>',
						'<code>' . esc_html( Settings::site_address() ) . '</code>'
					),
					array( 'code' => array() )
				),
				esc_url( $url ),
				esc_html__( 'Send from this address', '99trace-integration-with-posthog-for-woocommerce' )
			);
		}
		if ( ! Settings::browser_enabled() ) {
			return;
		}
		if ( Settings::posthog_js_options_invalid() ) {
			printf(
				'<div class="notice notice-error"><p><strong>99Trace:</strong> %s</p></div>',
				esc_html__( '"Further posthog-js options" is not a JSON object, so none of it is applied.', '99trace-integration-with-posthog-for-woocommerce' )
			);
		}
		$platform = Settings::consent_platform();
		if ( 'none' === Settings::consent_mode() && '' !== $platform && self::on_own_screen() ) {
			$names = array(
				'cookiebot'      => __( 'Cookiebot', '99trace-integration-with-posthog-for-woocommerce' ),
				'wp_consent_api' => __( 'a consent plugin (WP Consent API)', '99trace-integration-with-posthog-for-woocommerce' ),
			);
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: 1: name of the consent platform, 2: link to the Consent settings. */
						__( '99Trace tracks every visitor (Consent: Track every visitor), and this site also runs %1$s. If PostHog should wait for the visitor\'s "statistics" answer, choose it under %2$s. Which one is right is your decision.', '99trace-integration-with-posthog-for-woocommerce' ),
						esc_html( $names[ $platform ] ),
						'<a href="' . esc_url( admin_url( self::PAGE . '&section=consent' ) ) . '">' . esc_html__( 'Consent', '99trace-integration-with-posthog-for-woocommerce' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}
		if ( '' === Settings::browser_consent_mode() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>99Trace:</strong> %s</p></div>',
				esc_html__( 'PostHog does not run in the browser: Consent is set to Automatic and no consent platform was found (Cookiebot plugin, WP Consent API). If your banner is added by the theme or Google Tag Manager, choose it under Consent; choose "Track every visitor" only where no consent is needed.', '99trace-integration-with-posthog-for-woocommerce' )
			);
		}
		$other = Settings::other_posthog_plugin();
		if ( '' !== $other ) {
			printf(
				'<div class="notice notice-error"><p><strong>99Trace:</strong> %s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: plugin file of the other PostHog plugin. */
						__( 'PostHog does not run in the browser while another PostHog plugin is active (%s): two posthog-js instances on one page split visitors and double-count. Deactivate it to use the browser module; server events continue.', '99trace-integration-with-posthog-for-woocommerce' ),
						$other
					)
				)
			);
		}
	}

	/** 99Trace's settings page or the plugins list. */
	private static function on_own_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which page is shown, nothing is changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return ( $screen && 'plugins' === $screen->id ) || ( 'wc-settings' === $page && '99trace' === $tab );
	}

	/* ------------------------------------------------------------- order box */

	public function order_box(): void {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'w99-phwc', __( 'PostHog (99Trace)', '99trace-integration-with-posthog-for-woocommerce' ), array( $this, 'render_box' ), $screen, 'side', 'default' );
	}

	public function render_box( $post_or_order ): void {
		try {
			$this->box( $post_or_order );
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html( '99Trace: ' . $e->getMessage() ) . '</p>';
		}
	}

	private function box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$id   = Orders::identity( $order );
		$ctx  = Orders::capture_context( $order );
		$rows = array(
			__( 'Identity', '99trace-integration-with-posthog-for-woocommerce' )    => ( $id['linked'] ?? false )
				? ( $id['source'] ?? '' )
				/* translators: %s: why the order is not linked to a visitor, such as "consent_refused". */
				: sprintf( __( 'not linked (%s)', '99trace-integration-with-posthog-for-woocommerce' ), $id['reason'] ?? '' ),
			__( 'Distinct ID', '99trace-integration-with-posthog-for-woocommerce' ) => $id['distinct_id'] ?? '',
			__( 'Visitor', '99trace-integration-with-posthog-for-woocommerce' )     => $id['user_state'] ?? '',
			__( 'Session', '99trace-integration-with-posthog-for-woocommerce' )     => $id['session_id'] ?? '',
			__( 'Consent', '99trace-integration-with-posthog-for-woocommerce' )     => $id['consent'] ?? '',
			__( 'Captured', '99trace-integration-with-posthog-for-woocommerce' )    => $ctx['captured_at'] ?? '',
		);
		if ( isset( $ctx['linked_at'] ) ) {
			/* translators: 1: time the order was linked, 2: how it was recorded before. */
			$rows[ __( 'Linked later', '99trace-integration-with-posthog-for-woocommerce' ) ] = sprintf( __( '%1$s (was %2$s)', '99trace-integration-with-posthog-for-woocommerce' ), $ctx['linked_at'], $ctx['linked_from'] ?? '' );
		}
		echo '<table class="widefat striped" style="font-size:12px"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:38%">' . esc_html( $k ) . '</th><td style="word-break:break-all">' . esc_html( (string) $v ) . '</td></tr>';
		}
		$events = array(
			'Order Completed' => Orders::state( $order, Orders::META_COMPLETED ),
			'Order Cancelled' => Orders::state( $order, Orders::META_CANCELLED ),
		);
		foreach ( $order->get_refunds() as $refund ) {
			/* translators: %d: refund ID. */
			$events[ sprintf( __( 'Refund #%d', '99trace-integration-with-posthog-for-woocommerce' ), $refund->get_id() ) ] = Orders::state( $refund, Orders::META_REFUND );
		}
		foreach ( $events as $name => $state ) {
			if ( ! $state ) {
				continue;
			}
			$line = ( $state['status'] ?? '' ) . ( isset( $state['sent_at'] ) ? ' ' . $state['sent_at'] : '' ) . ( isset( $state['uuid'] ) ? ' · ' . $state['uuid'] : '' ) . ( isset( $state['last_error'] ) ? ' · ' . $state['last_error'] : '' ) . ( isset( $state['reason'] ) ? ' · ' . $state['reason'] : '' );
			echo '<tr><th>' . esc_html( $name ) . '</th><td style="word-break:break-all">' . esc_html( $line ) . '</td></tr>';
		}
		if ( array() === Orders::state( $order, Orders::META_COMPLETED ) && '' !== (string) $order->get_meta( Orders::META_LEGACY_SENT, true ) ) {
			/* translators: %s: date and time the order was sent. */
			echo '<tr><th>0.2.x</th><td>' . esc_html( sprintf( __( 'sent %s', '99trace-integration-with-posthog-for-woocommerce' ), (string) $order->get_meta( Orders::META_LEGACY_SENT, true ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* -------------------------------------------------------------- coverage */

	public function status_tab( $tabs ) {
		$tabs['99trace'] = '99Trace';
		return $tabs;
	}

	public function coverage(): void {
		$days   = 30;
		$since  = time() - $days * DAY_IN_SECONDS;
		$query  = array(
			'limit'  => 2000,
			'return' => 'ids',
			'type'   => 'shop_order',
		);
		// Paid in the period, or placed in it and in a paid status: cash on delivery (and other gateways) move an
		// order to Processing without recording a payment date, and 99Trace sends those purchases too.
		$ids    = array_unique(
			array_merge(
				wc_get_orders( $query + array( 'date_paid' => '>' . $since ) ),
				wc_get_orders( $query + array( 'date_created' => '>' . $since, 'status' => Orders::paid_statuses() ) )
			)
		);
		$total  = 0;
		$titles = array(
			'status'         => __( 'Status', '99trace-integration-with-posthog-for-woocommerce' ),
			'identity'       => __( 'Identity', '99trace-integration-with-posthog-for-woocommerce' ),
			'consent'        => __( 'Consent', '99trace-integration-with-posthog-for-woocommerce' ),
			'payment_method' => __( 'Payment method', '99trace-integration-with-posthog-for-woocommerce' ),
		);
		$by     = array_fill_keys( array_keys( $titles ), array() );
		foreach ( $ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof \WC_Order || ! Orders::should_track( $o ) ) {
				continue;
			}
			++$total;
			$state    = Orders::state( $o, Orders::META_COMPLETED );
			$status   = $state['status'] ?? ( '' !== (string) $o->get_meta( Orders::META_LEGACY_SENT, true ) ? __( 'sent (0.2.x)', '99trace-integration-with-posthog-for-woocommerce' ) : __( 'not queued', '99trace-integration-with-posthog-for-woocommerce' ) );
			$id       = Orders::identity( $o );
			$identity = $id['linked'] ? $id['source'] : 'unlinked: ' . $id['reason'];
			$consent  = $id['consent'] ?? 'unknown';
			$method   = $o->get_payment_method() ? $o->get_payment_method() : '(none)';
			foreach ( array( 'status' => $status, 'identity' => $identity, 'consent' => $consent, 'payment_method' => $method ) as $group => $value ) {
				$by[ $group ][ $value ] = ( $by[ $group ][ $value ] ?? 0 ) + 1;
			}
		}
		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %d: number of days. */
				_n( '99Trace coverage, paid web orders in the last %d day', '99Trace coverage, paid web orders in the last %d days', $days, '99trace-integration-with-posthog-for-woocommerce' ),
				$days
			)
		) . '</h2><p>' . esc_html(
			sprintf(
				/* translators: %d: number of orders. */
				_n( '%d order.', '%d orders.', $total, '99trace-integration-with-posthog-for-woocommerce' ),
				$total
			)
		) . ' ' . esc_html__( 'Experiment conversion metrics only see linked purchases: compare the linked share between arms before reading a result.', '99trace-integration-with-posthog-for-woocommerce' ) . '</p>';
		foreach ( $by as $group => $counts ) {
			arsort( $counts );
			echo '<h3>' . esc_html( $titles[ $group ] ) . '</h3><table class="widefat striped" style="max-width:640px"><tbody>';
			foreach ( $counts as $k => $n ) {
				echo '<tr><td>' . esc_html( (string) $k ) . '</td><td style="width:90px">' . (int) $n . '</td><td style="width:90px">' . ( $total ? esc_html( number_format_i18n( 100 * $n / $total, 1 ) ) . '%' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
