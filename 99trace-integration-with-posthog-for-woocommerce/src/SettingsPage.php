<?php
/**
 * The 99Trace tab under WooCommerce → Settings, with one section per subject (Admin::sections()).
 * Loaded only once WooCommerce's settings classes exist (Admin::settings_page).
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class SettingsPage extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = '99trace';
		$this->label = '99Trace';
		parent::__construct();
	}

	protected function get_own_sections() {
		return Admin::sections();
	}

	/** WooCommerce versions before 5.5 ask for the sections this way. */
	public function get_sections() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook for a settings tab.
		return apply_filters( 'woocommerce_get_sections_' . $this->id, Admin::sections() );
	}

	public function get_settings( $section_id = '' ) {
		global $current_section;
		$section = func_num_args() ? (string) $section_id : (string) $current_section;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook for a settings tab.
		return apply_filters( 'woocommerce_get_settings_' . $this->id, Admin::settings_for( $section ), $section );
	}

	protected function get_settings_for_section_core( $section_id ) {
		return Admin::settings_for( (string) $section_id );
	}

	public function save() {
		global $current_section;
		parent::save();
		if ( 'events' !== $current_section || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// WooCommerce checked this nonce before calling save(); checked again here, where the form is read.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce-settings' ) ) {
			return;
		}
		Admin::save_events(
			array(
				'w99_phwc_event_on'    => isset( $_POST['w99_phwc_event_on'] ) && is_array( $_POST['w99_phwc_event_on'] ) ? map_deep( wp_unslash( $_POST['w99_phwc_event_on'] ), 'sanitize_key' ) : array(),
				'w99_phwc_event_names' => isset( $_POST['w99_phwc_event_names'] ) && is_array( $_POST['w99_phwc_event_names'] ) ? map_deep( wp_unslash( $_POST['w99_phwc_event_names'] ), 'sanitize_text_field' ) : array(),
			)
		);
	}
}
