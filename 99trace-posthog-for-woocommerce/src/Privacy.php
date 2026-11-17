<?php
/**
 * Personal data the plugin keeps on orders: the buyer's IP address, user agent and checkout URL
 * (for location and browser context of server events), the PostHog identifiers, and the frozen
 * events. WordPress's personal data export lists them; WooCommerce's order eraser removes the
 * personal parts and keeps what exactly-once delivery needs (state, UUIDs, the pseudonymous IDs).
 * Data already in PostHog is erased there (delete the person).
 *
 * @package W99\Trace
 */

namespace W99\Trace;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	/** Event properties that describe the buyer's device, network or pages. */
	private const EVENT_PERSONAL = array(
		'$ip', '$raw_user_agent', '$current_url', '$pathname', '$referrer', '$referring_domain',
		'$session_entry_url', '$session_entry_referrer', '$session_entry_pathname', '$screen_height', '$screen_width',
		'$viewport_height', '$viewport_width', '$timezone', '$timezone_offset', '$browser_language', '$browser_language_prefix',
		'wc_attribution_referrer', 'wc_attribution_session_entry', '$fbc', '$fbp', 'fbclid', 'gclid', '$session_entry_fbclid', '$session_entry_gclid',
	);

	public function register(): void {
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $this, 'erase_order' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy → Policy Guide). It follows the current
	 * settings: consent, the browser module, IP location, customer accounts and customer details.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$consent  = 'none' !== Settings::consent_mode();
		$browser  = Settings::on( 'browser' );
		$parts    = array();
		$parts[]  = '<p class="privacy-policy-tutorial">' . esc_html__( '99Trace sends data about visits and orders to PostHog, the analytics service you connected. This suggestion follows your current 99Trace settings: check it again when you change them, add how long your PostHog project keeps data, and say so if you pass PostHog data on to advertising platforms (PostHog destinations).', '99trace-posthog-for-woocommerce' ) . '</p>';
		$parts[]  = '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', '99trace-posthog-for-woocommerce' ) . ' </strong>' . esc_html__( 'We use PostHog, a product analytics service, to understand how visitors use our shop and to count our orders correctly.', '99trace-posthog-for-woocommerce' ) . '</p>';
		if ( $browser ) {
			$parts[] = '<p>' . ( $consent
				? esc_html__( 'After you accept statistics cookies, PostHog runs in your browser and records the pages you view, the buttons and links you click, how far you scroll, and technical details such as your browser, device and screen size. It keeps an anonymous visitor ID in cookies whose names start with ph_, so that your visits can be recognised.', '99trace-posthog-for-woocommerce' )
				: esc_html__( 'PostHog runs in your browser and records the pages you view, the buttons and links you click, how far you scroll, and technical details such as your browser, device and screen size. It keeps an anonymous visitor ID in cookies whose names start with ph_, so that your visits can be recognised.', '99trace-posthog-for-woocommerce' ) ) . '</p>';
		}
		$parts[] = '<p>' . esc_html__( 'Our shop\'s server sends PostHog what happens to your cart and your orders: products added or removed, coupons, the start of the checkout, and orders that are paid, refunded or cancelled, with their products, amounts and payment method, under the same anonymous visitor ID.', '99trace-posthog-for-woocommerce' )
			. ( Settings::on( 'send_ip' ) ? ' ' . esc_html__( 'These events include your IP address, which PostHog uses to estimate your country and city.', '99trace-posthog-for-woocommerce' ) : '' ) . '</p>';
		if ( $consent && 'anonymous' === Settings::get( 'unconsented_orders' ) ) {
			$parts[] = '<p>' . esc_html__( 'If you did not accept statistics cookies, a paid order is counted without your visitor ID, IP address or any other detail that identifies you.', '99trace-posthog-for-woocommerce' ) . '</p>';
		}
		if ( $browser && Settings::on( 'identify_customers' ) ) {
			$parts[] = '<p>' . esc_html__( 'If you are signed in to your account, your visits on different devices are joined using an anonymous account number, never your e-mail address.', '99trace-posthog-for-woocommerce' ) . '</p>';
		}
		if ( Settings::on( 'person_details' ) ) {
			$parts[] = '<p>' . ( $consent
				? esc_html__( 'If you accepted statistics cookies, the e-mail address, name and phone number you enter at checkout or in your account are stored on your visitor profile in PostHog.', '99trace-posthog-for-woocommerce' )
				: esc_html__( 'The e-mail address, name and phone number you enter at checkout or in your account are stored on your visitor profile in PostHog.', '99trace-posthog-for-woocommerce' ) ) . '</p>';
		}
		$parts[] = '<p>' . ( false !== strpos( Settings::host(), 'eu.' )
			? esc_html__( 'PostHog stores this data in the European Union.', '99trace-posthog-for-woocommerce' )
			: esc_html__( 'PostHog may store this data in the United States.', '99trace-posthog-for-woocommerce' ) )
			. ' ' . sprintf(
				/* translators: %s: link to PostHog's privacy policy. */
				esc_html__( 'PostHog\'s privacy policy: %s', '99trace-posthog-for-woocommerce' ),
				'<a href="https://posthog.com/privacy">https://posthog.com/privacy</a>'
			) . '</p>';
		wp_add_privacy_policy_content( '99Trace', wp_kses_post( implode( '', $parts ) ) );
	}

	/** Runs after WooCommerce anonymised the order (setting "Remove personal data from orders on request"). */
	public function erase_order( $order ): void {
		try {
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
			$capture = Orders::state( $order, Orders::META_CAPTURE );
			if ( array() !== $capture ) {
				unset( $capture['ip'], $capture['user_agent'], $capture['current_url'] );
				$capture['erased'] = gmdate( 'Y-m-d\TH:i:s\Z' );
				$order->update_meta_data( Orders::META_CAPTURE, wp_json_encode( $capture ) );
			}
			$identity = Orders::state( $order, Orders::META_IDENTITY );
			if ( array() !== $identity ) {
				$identity['props'] = array();
				$order->update_meta_data( Orders::META_IDENTITY, wp_json_encode( $identity ) );
			}
			foreach ( array( Orders::META_COMPLETED, Orders::META_CANCELLED ) as $meta ) {
				self::erase_event( $order, $meta );
			}
			$order->save_meta_data();
			foreach ( $order->get_refunds() as $refund ) {
				if ( self::erase_event( $refund, Orders::META_REFUND ) ) {
					$refund->save_meta_data();
				}
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Erasing 99Trace data of an order failed: ' . $e->getMessage() );
		}
	}

	/** @param \WC_Order|\WC_Order_Refund $object */
	private static function erase_event( $object, string $meta ): bool {
		$state = Orders::state( $object, $meta );
		if ( ! isset( $state['event']['properties'] ) || ! is_array( $state['event']['properties'] ) ) {
			return false;
		}
		foreach ( self::EVENT_PERSONAL as $key ) {
			unset( $state['event']['properties'][ $key ] );
		}
		// Customer details set on the person (e-mail, name, phone): never kept, never sent after an erasure.
		unset( $state['event']['properties']['$set'], $state['event']['properties']['$set_once'] );
		$state['event']['properties']['$geoip_disable'] = true;
		$object->update_meta_data( $meta, wp_json_encode( $state ) );
		return true;
	}

	public function exporters( $exporters ) {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['w99-phwc'] = array(
			'exporter_friendly_name' => __( '99Trace (PostHog) order data', '99trace-posthog-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function export( $email, $page = 1 ): array {
		$data   = array();
		$orders = array();
		try {
			$orders = wc_get_orders( array( 'billing_email' => (string) $email, 'limit' => 10, 'page' => max( 1, (int) $page ), 'type' => 'shop_order' ) );
			foreach ( $orders as $order ) {
				$id    = Orders::identity( $order );
				$ctx   = Orders::state( $order, Orders::META_CAPTURE );
				$items = array(
					array( 'name' => __( 'PostHog distinct ID', '99trace-posthog-for-woocommerce' ), 'value' => ! empty( $id['linked'] ) ? (string) $id['distinct_id'] : '' ),
					array( 'name' => __( 'PostHog device ID', '99trace-posthog-for-woocommerce' ), 'value' => (string) ( $id['device_id'] ?? '' ) ),
					array( 'name' => __( 'PostHog session ID', '99trace-posthog-for-woocommerce' ), 'value' => (string) ( $id['session_id'] ?? '' ) ),
					array( 'name' => __( 'Analytics consent at checkout', '99trace-posthog-for-woocommerce' ), 'value' => (string) ( $id['consent'] ?? '' ) ),
					array( 'name' => __( 'IP address (location in PostHog)', '99trace-posthog-for-woocommerce' ), 'value' => (string) ( $ctx['ip'] ?? '' ) ),
					array( 'name' => __( 'Browser (user agent)', '99trace-posthog-for-woocommerce' ), 'value' => (string) ( $ctx['user_agent'] ?? '' ) ),
				);
				$items = array_values( array_filter( $items, static fn( $i ) => '' !== $i['value'] ) );
				if ( $items ) {
					$data[] = array( 'group_id' => 'w99-phwc', 'group_label' => __( '99Trace (PostHog)', '99trace-posthog-for-woocommerce' ), 'item_id' => 'w99-phwc-order-' . $order->get_id(), 'data' => $items );
				}
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'error', 'Personal data export failed: ' . $e->getMessage() );
		}
		return array( 'data' => $data, 'done' => count( $orders ) < 10 );
	}
}
