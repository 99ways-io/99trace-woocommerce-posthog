<?php
/**
 * The 99Trace tab under WooCommerce → Settings, with one section per subject (Admin::sections()).
 * Loaded only once WooCommerce's settings classes exist (Admin::settings_page).
 *
 * @package W99\Trace
 */

namespace W99\Trace;

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
		return apply_filters( 'woocommerce_get_sections_' . $this->id, Admin::sections() );
	}

	public function get_settings( $section_id = '' ) {
		global $current_section;
		$section = func_num_args() ? (string) $section_id : (string) $current_section;
		return apply_filters( 'woocommerce_get_settings_' . $this->id, Admin::settings_for( $section ), $section );
	}

	protected function get_settings_for_section_core( $section_id ) {
		return Admin::settings_for( (string) $section_id );
	}

	public function save() {
		global $current_section;
		parent::save();
		if ( 'events' === $current_section ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the settings nonce before saving.
			Admin::save_events( $_POST );
		}
	}
}
