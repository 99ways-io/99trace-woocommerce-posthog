<?php
/**
 * Deterministic event IDs (RFC 9562 version 5).
 *
 * PostHog collapses events that share uuid, event name, distinct_id and UTC day. Deriving the
 * uuid from the order makes retries, replays and duplicate hooks land on the same stored event.
 * The namespace and the `99hog:v1:` name scheme date from 99Trace's first name (99Hog) and never
 * change: an order sent before the rename and retried after it must keep its ID.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Uuid {

	public const NAMESPACE = 'ade32437-dad7-45f6-8919-68d855bb110c';

	public static function v5( string $name, string $namespace = self::NAMESPACE ): string {
		$ns = hex2bin( str_replace( '-', '', $namespace ) );
		if ( false === $ns || 16 !== strlen( $ns ) ) {
			throw new \InvalidArgumentException( 'Invalid namespace UUID.' );
		}
		$hash    = sha1( $ns . $name );
		$time_hi = ( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000;
		$clock   = ( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000;
		return sprintf( '%s-%s-%04x-%04x-%s', substr( $hash, 0, 8 ), substr( $hash, 8, 4 ), $time_hi, $clock, substr( $hash, 20, 12 ) );
	}

	/** "99hog:v1:woocommerce:<store>:<kind>:<id>[:<id>…]". */
	public static function event( string $store, string $kind, array $ids ): string {
		return self::v5( implode( ':', array_merge( array( '99hog', 'v1', 'woocommerce', strtolower( $store ), $kind ), array_map( 'strval', $ids ) ) ) );
	}

	/**
	 * A random, time-ordered v7: the form posthog-js gives an anonymous visitor's ID, so a visitor ID
	 * the shop's server creates looks like any other once posthog-js starts with it.
	 */
	public static function v7( ?int $ms = null ): string {
		$ms  = null === $ms ? (int) floor( microtime( true ) * 1000 ) : $ms;
		$hex = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT ) . bin2hex( random_bytes( 10 ) );
		$hex[12] = '7';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/** A random v4, for events that are not about a stored object. */
	public static function v4(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
		$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}
}
