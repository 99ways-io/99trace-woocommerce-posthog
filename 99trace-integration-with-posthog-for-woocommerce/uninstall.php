<?php
/**
 * Uninstall: removes 99Trace's settings, its scheduled tasks and its temporary data.
 *
 * Order data stays with the orders: the identity frozen at checkout and each event's delivery state.
 * A later install therefore never sends an order twice; WooCommerce's personal data eraser anonymises it.
 *
 * @package W99Phwc
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete 99Trace's options, transients and scheduled tasks on the current site.
 */
function w99_phwc_uninstall_site() {
	global $wpdb;
	// Every name 99Trace (and 99Hog before it) stores starts with w99_phwc_; no API lists options by prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read once at uninstall.
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'w99_phwc_' ) . '%',
			$wpdb->esc_like( '_transient_w99_phwc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_w99_phwc_' ) . '%'
		)
	);
	foreach ( (array) $names as $name ) {
		delete_option( $name );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'w99-phwc' );
		foreach ( array( 'w99_phwc_send', 'w99_phwc_send_event', 'w99_phwc_sweep', 'w99_phwc_send_order', 'w99_phwc_send_refund', 'w99_phwc_send_identify', 'w99_phwc_send_message' ) as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $w99_phwc_site_id ) {
		switch_to_blog( $w99_phwc_site_id );
		w99_phwc_uninstall_site();
		restore_current_blog();
	}
} else {
	w99_phwc_uninstall_site();
}
