<?php
/**
 * Wiring.
 *
 * @package W99\Trace
 */

namespace W99\Trace;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const LOG_SOURCE = '99trace';

	public static function boot(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( self::class, 'init' ), 20 );
	}

	public static function declare_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', W99_TRACE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', W99_TRACE_FILE, true );
		}
	}

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( self::legacy_active() ) {
			// Both plugins would handle the same queue, orders and visitors: 99Trace waits until 99Hog is deactivated.
			add_action( 'admin_notices', array( self::class, 'legacy_notice' ) );
			return;
		}
		self::upgrade();
		Settings::mark_sending();
		( new Admin() )->register();
		( new Privacy() )->register();
		// Hooks are always registered and every handler checks the mode itself, so a mode change takes
		// effect at once (settings screen, WP-CLI) and queued tasks always find their handler.
		$queue = new Queue();
		$queue->register();
		( new Orders( $queue ) )->register();
		( new Storefront( $queue ) )->register();
		( new Visitor() )->register();
	}

	/**
	 * 99Hog for WooCommerce, the name of this plugin before 1.0.0, is still active. It shares 99Trace's
	 * settings, order data and scheduled tasks, so the two must never run together.
	 */
	public static function legacy_active(): bool {
		return defined( 'W99_PHWC_VERSION' );
	}

	public static function legacy_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( '99Trace is waiting.', '99trace-posthog-for-woocommerce' ),
			esc_html__( '99Hog for WooCommerce, the plugin 99Trace replaces, is still active, and both would send every event. Deactivate 99Hog: 99Trace then starts with its settings, its delivery state and its visitors.', '99trace-posthog-for-woocommerce' ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to Plugins', '99trace-posthog-for-woocommerce' )
		);
	}

	/** Once per version: remember the key 0.2.x sent to; record the address sending is on for. */
	private static function upgrade(): void {
		$installed = (string) get_option( Settings::PREFIX . 'version', '' );
		self::keep_consent_defaults( $installed );
		if ( W99_TRACE_VERSION === $installed ) {
			return;
		}
		Settings::normalize_features(); // 0.4.0's browser-feature tick boxes become the choices they meant
		if ( '' === $installed && false === get_option( Settings::PREFIX . 'legacy_key', false ) ) {
			// Refunds of purchases 0.2.x sent follow them only into the project those purchases are in.
			update_option( Settings::PREFIX . 'legacy_key', Settings::token(), false );
		}
		if ( '' !== $installed && version_compare( $installed, '0.4.0', '<' ) ) {
			// 0.4.0 gives removals their own switches: they start as the switch that covered them before.
			foreach ( array( 'ev_cart_removed' => 'ev_cart', 'ev_coupon_removed' => 'ev_coupon' ) as $new => $old ) {
				if ( false === get_option( Settings::PREFIX . $new, false ) && false !== get_option( Settings::PREFIX . $old, false ) ) {
					update_option( Settings::PREFIX . $new, get_option( Settings::PREFIX . $old ) );
				}
			}
			Settings::flush();
		}
		if ( 'on' === Settings::mode() ) {
			Settings::arm();
		}
		update_option( Settings::PREFIX . 'version', W99_TRACE_VERSION );
	}

	/**
	 * 0.5.0 changed two defaults (track every visitor; count orders without consent anonymously). A shop
	 * that ran an earlier version and never saved these settings keeps what it had: an update must not
	 * change its consent handling. Recorded in its own option, once per site (not tied to the version:
	 * a pre-release build already called itself 0.5.0).
	 */
	private static function keep_consent_defaults( string $installed ): void {
		if ( false !== get_option( Settings::PREFIX . 'consent_defaults', false ) ) {
			return;
		}
		// 0.2.x stored no version: a project key (or 0.2.x's own options) means the plugin ran here before.
		$existing = '' !== $installed || false !== get_option( Settings::PREFIX . 'key', false ) || false !== get_option( Settings::PREFIX . 'mode', false );
		if ( $existing ) {
			foreach ( array( 'consent' => 'auto', 'unconsented_orders' => 'skip' ) as $name => $before ) {
				if ( false === get_option( Settings::PREFIX . $name, false ) ) {
					update_option( Settings::PREFIX . $name, $before );
				}
			}
			Settings::flush();
		}
		update_option( Settings::PREFIX . 'consent_defaults', $existing ? 'kept' : 'new' );
	}

	public static function log( string $level, string $message ): void {
		if ( 'debug' === $level && ! Settings::on( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( 'debug' === $level ? 'info' : $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}
}
