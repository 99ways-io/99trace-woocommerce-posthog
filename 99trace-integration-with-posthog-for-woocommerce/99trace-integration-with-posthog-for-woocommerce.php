<?php
/**
 * Plugin Name:          99Trace: Easy Integration with PostHog for WooCommerce
 * Plugin URI:           https://99trace.com
 * Description:          Integrate WooCommerce with PostHog in one click. Track customer activity with unified user identities across server and client sides.
 * Version:              1.1.1
 * Requires at least:    6.5
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * Author:               99Ways
 * Author URI:           https://99ways.io
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          99trace-integration-with-posthog-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.7
 * WC tested up to:      11.3
 *
 * 99Trace was called "99Hog for WooCommerce" before 1.0.0. Stored names keep the `w99_phwc_` prefix
 * (options, order data, scheduled tasks, cookies `ph_wc_*`), so a shop that switches from 99Hog keeps
 * its settings, its delivery state and its visitors' IDs.
 *
 * @package W99Phwc
 */

defined( 'ABSPATH' ) || exit;

define( 'W99_PHWC_TRACE_VERSION', '1.1.1' );
define( 'W99_PHWC_TRACE_FILE', __FILE__ );
define( 'W99_PHWC_TRACE_DIR', __DIR__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'W99Phwc\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

\W99Phwc\Plugin::boot();
