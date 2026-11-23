<?php
/**
 * Who is recorded (Visitors & orders → Who is recorded): shop staff, page editors and preview loads.
 *
 * A signed-in user who is not recorded, and a preview load, get no posthog-js and no cart, coupon,
 * checkout or person events. A purchase such a user makes is still sent, without their visits
 * (the identity captured at checkout stays empty), as for staff since 0.2.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Audience {

	/**
	 * Query parameters that mark a page opened for editing or previewing: Elementor's editor frame
	 * (`elementor-preview`), WordPress previews (`preview`, `preview_id`, `preview_nonce`, also used by
	 * Elementor's and FunnelKit's preview buttons), the Customizer, and FunnelKit's upsell customizer.
	 */
	private const PREVIEW_PARAMS = array( 'elementor-preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'customize_messenger_channel', 'wfocu_customize' );

	/** Whether this signed-in user's visits are recorded. Users without an account always are. */
	public static function records_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return true;
		}
		$record = true;
		if ( user_can( $user_id, 'edit_shop_orders' ) ) {
			// Shop staff: administrators, shop managers, any role that can manage orders.
			$record = Settings::on( 'record_staff' ) || (bool) apply_filters( 'w99_phwc_track_staff', false );
		} elseif ( user_can( $user_id, 'edit_pages' ) ) {
			// Page editors: editors and custom roles that build pages but cannot manage orders.
			$record = Settings::on( 'record_editors' );
		}
		return (bool) apply_filters( 'w99_phwc_record_user', $record, $user_id );
	}

	/** Whether this request may be recorded: its signed-in user is, and it is not a preview load. */
	public static function records_request(): bool {
		$user = get_current_user_id();
		if ( $user && ! self::records_user( $user ) ) {
			return false;
		}
		return Settings::on( 'record_previews' ) || ! self::is_preview();
	}

	/**
	 * A page opened in a page builder or as a preview, or an AJAX or REST call made from one. Only a
	 * signed-in user can open these, so a shopper's link that happens to carry such a parameter is
	 * still recorded.
	 */
	public static function is_preview(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only detection, nothing is changed.
		if ( self::has_preview_params( $_GET ) || ( isset( $_GET['preview'] ) && 'true' === $_GET['preview'] ) ) {
			return true;
		}
		// phpcs:enable
		if ( ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) || ( did_action( 'parse_query' ) && function_exists( 'is_preview' ) && is_preview() ) ) {
			return true;
		}
		if ( class_exists( '\WFFN_Common' ) && method_exists( '\WFFN_Common', 'is_page_builder_preview' ) && did_action( 'wp' ) && \WFFN_Common::is_page_builder_preview() ) {
			return true; // FunnelKit's own test (Elementor's preview mode, and the builders FunnelKit supports).
		}
		$ajax = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $ajax && self::is_preview_url( (string) wp_get_raw_referer() );
	}

	/** A preview or editor address: the page a preview's AJAX call comes from. */
	public static function is_preview_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( false !== strpos( $path, '/wp-admin/customize.php' ) ) {
			return true;
		}
		$query = array();
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( false !== strpos( $path, '/wp-admin/post.php' ) && 'elementor' === ( $query['action'] ?? '' ) ) {
			return true; // Elementor's editor
		}
		return self::has_preview_params( $query ) || 'true' === ( $query['preview'] ?? '' );
	}

	private static function has_preview_params( array $query ): bool {
		foreach ( self::PREVIEW_PARAMS as $param ) {
			if ( isset( $query[ $param ] ) && '' !== $query[ $param ] ) {
				return true;
			}
		}
		return false;
	}
}
