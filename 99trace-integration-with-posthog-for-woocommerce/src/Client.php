<?php
/**
 * PostHog capture over the WordPress HTTP API (`/batch/`), so the site's proxy, SSL and timeout
 * settings apply. In test mode it logs what it would send and sends nothing.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Client {

	/**
	 * @param array<int, array<string, mixed>> $events  Capture payloads.
	 * @param int                              $timeout Seconds to wait for PostHog.
	 * @return array{ok: bool, retry: bool, status: ?int, error: ?string}
	 */
	public static function send( array $events, string $what = '', int $timeout = 10 ): array {
		if ( 'test' === Settings::mode() ) {
			foreach ( $events as $e ) {
				// Customer details are named, never written to the log files.
				foreach ( array( '$set', '$set_once' ) as $k ) {
					if ( isset( $e['properties'][ $k ] ) && is_array( $e['properties'][ $k ] ) ) {
						$e['properties'][ $k ] = array_fill_keys( array_keys( $e['properties'][ $k ] ), '[not logged]' );
					}
				}
				Plugin::log( 'info', 'TEST MODE, not sent (' . $what . '): ' . wp_json_encode( $e ) );
			}
			return array( 'ok' => true, 'retry' => false, 'status' => null, 'error' => null );
		}
		if ( ! Settings::can_send() ) {
			return array( 'ok' => false, 'retry' => false, 'status' => null, 'error' => 'not_configured' );
		}
		$body     = array( 'api_key' => Settings::token(), 'batch' => array_values( $events ) );
		$response = wp_remote_post(
			Settings::host() . '/batch/',
			array(
				'timeout'     => $timeout,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
				'user-agent'  => '99trace/' . W99_PHWC_TRACE_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			Plugin::log( 'warning', 'Send failed (' . $what . '): ' . $response->get_error_message() );
			return array( 'ok' => false, 'retry' => true, 'status' => null, 'error' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			Plugin::log( 'info', 'Sent ' . $what );
			return array( 'ok' => true, 'retry' => false, 'status' => $status, 'error' => null );
		}
		// 400/401/413 fail the same way next time; 408, 429 and 5xx are worth retrying.
		$retry = 0 === $status || 408 === $status || 429 === $status || $status >= 500;
		$error = 'HTTP ' . $status . ' ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );
		Plugin::log( 'warning', 'Send failed (' . $what . '): ' . $error );
		return array( 'ok' => false, 'retry' => $retry, 'status' => $status, 'error' => trim( $error ) );
	}
}
