<?php
/**
 * Server-side checks of 99Trace for WooCommerce on a throwaway WordPress Playground site (latest
 * WordPress and WooCommerce, PHP 8.4, HPOS, shop in Europe/Amsterdam time). All HTTP is intercepted.
 * Never run against a real shop. Results are written to /tests/results.txt (this folder).
 *
 * Covers the server-only pilot mode (browser module off, visitor read from another plugin's
 * posthog-js cookie) and the checkout envelope of the browser module. Browser behaviour itself is
 * covered by staging/tests (Playwright on a copy of a live store).
 *
 * Run: ./run.sh (from this folder)
 */
require_once '/wordpress/wp-load.php';

use W99Phwc\{Queue, Storefront, Orders, Visitor, Identity, Consent};

$results = [];
$fail    = 0;
function check( $name, $ok, $detail = '' ) {
	global $results, $fail;
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $detail ? "  [$detail]" : '' );
	if ( ! $ok ) {
		$fail++;
	}
}

const PROD_KEY = 'phc_PRODKEY';   // The shop's existing PostHog project: its browser cookie holds the visitor ID (pilot mode).
const SEND_KEY = 'phc_TESTSEND';  // Where our plugin sends.
const EMAIL    = 'buyer@example.test';
const BUYER_IP = '93.184.216.34'; // A public IP, stored by WooCommerce at checkout.

update_option( 'timezone_string', 'Europe/Amsterdam' ); // The 0.2.0 one-hour bug only shows outside UTC.
update_option( 'phi_api_key', PROD_KEY );
update_option( 'w99_phwc_key', SEND_KEY );
update_option( 'w99_phwc_host', 'https://test.example' );
update_option( 'w99_phwc_delay_minutes', '10' );
\W99Phwc\Settings::flush();

// A new install (0.5.0): every visitor tracked, orders without consent counted, one visitor ID for browser and server.
check( 'new install defaults: track every visitor, count unconsented orders, one visitor ID', 'none' === \W99Phwc\Settings::consent_mode() && 'anonymous' === \W99Phwc\Settings::get( 'unconsented_orders' ) && \W99Phwc\Settings::on( 'visitor_cookie' ) );
// Sections 1-20 check the consent-following behaviour 0.4.0 shipped with; 21 checks 0.5.0's one visitor ID.
update_option( 'w99_phwc_consent', 'auto' );
update_option( 'w99_phwc_unconsented_orders', 'skip' );
\W99Phwc\Settings::flush();

$GLOBALS['sent']        = [];
$GLOBALS['http_fail']   = false;
$GLOBALS['http_status'] = 200;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['sent'][] = [ 'url' => $url, 'body' => json_decode( $args['body'], true ), 'failed' => $GLOBALS['http_fail'] ];
		$GLOBALS['sent'][ count( $GLOBALS['sent'] ) - 1 ]['failed'] = $GLOBALS['http_fail'] || 200 !== $GLOBALS['http_status'];
		return $GLOBALS['http_fail']
			? new WP_Error( 'down', 'simulated outage' )
			: [ 'headers' => [], 'body' => 200 === $GLOBALS['http_status'] ? '{"status":"Ok"}' : '{"error":"invalid key"}', 'response' => [ 'code' => $GLOBALS['http_status'], 'message' => 'X' ], 'cookies' => [], 'filename' => null ];
	},
	10,
	3
);
function set_opt( $k, $v ) {
	update_option( 'w99_phwc_' . $k, $v );
	\W99Phwc\Settings::flush();
}

check( 'HPOS order tables on, as on the production shops', 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );

$p = new WC_Product_Simple();
$p->set_name( 'Test product' );
$p->set_regular_price( '34.97' );
$p->set_sku( 'TP-1' );
$p->save();

/** An order created as WooCommerce's checkout creates it (the plugin captures identity on that request). */
function checkout( $p, $via = 'checkout', $email = EMAIL, $ip = BUYER_IP ) {
	$o = new WC_Order();
	$o->set_created_via( $via );
	$o->add_product( $p, 1 );
	$o->set_payment_method( 'mollie_wc_gateway_ideal' );
	$o->set_billing_email( $email );
	$o->set_customer_ip_address( $ip );
	$o->calculate_totals();
	do_action( 'woocommerce_checkout_create_order', $o, array() ); // Fired by WC_Checkout before the first save.
	$o->save();
	return wc_get_order( $o->get_id() );
}
/** End the "request" (send its behavioural events), then run queued tasks due within $horizon seconds (the 10-minute upsell wait included), over a few rounds. */
function run_pending( $rounds = 5, $horizon = 3600 ) {
	Queue::flush_events();
	$n = 0;
	for ( $round = 0; $round < $rounds; $round++ ) { // Actions can queue further actions (dependents, retries).
		$ids = as_get_scheduled_actions( [ 'group' => 'w99-phwc', 'status' => 'pending', 'per_page' => 200, 'date' => gmdate( 'Y-m-d H:i:s', time() + $horizon ), 'date_compare' => '<=' ], 'ids' );
		if ( ! $ids ) {
			break;
		}
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, 'test' );
			$n++;
		}
	}
	return $n;
}
function sent_events( $event = null ) {
	$out = [];
	foreach ( $GLOBALS['sent'] as $req ) {
		if ( $req['failed'] ) {
			continue;
		}
		foreach ( $req['body']['batch'] ?? [] as $m ) {
			if ( null === $event || $m['event'] === $event ) {
				$out[] = $m;
			}
		}
	}
	return $out;
}
function last_event( $event ) {
	$e = sent_events( $event );
	return $e ? end( $e ) : null;
}
function count_of( $event ) {
	return count( sent_events( $event ) );
}
function state( $o, $key = '_99w_ph_order_completed' ) {
	$o = wc_get_order( $o->get_id() );
	return json_decode( (string) $o->get_meta( $key ), true ) ?: [];
}

$cookie_name = 'ph_' . PROD_KEY . '_posthog';
$cookie_json = wp_json_encode(
	[
		'distinct_id' => '0197-visitor-abc',
		'$device_id'  => '0197-device-abc',
		'$sesid'      => [ ( time() - 60 ) * 1000, '0197-session-xyz', ( time() - 600 ) * 1000 ],
		'$user_state' => 'anonymous',
	]
);

// 1. OFF: nothing happens.
set_opt( 'mode', 'off' );
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$o->payment_complete( 'tr_1' );
check( 'off: no identity saved', '' === (string) wc_get_order( $o->get_id() )->get_meta( '_99w_ph_identity' ) );
check( 'off: nothing queued or sent', 0 === run_pending() && 0 === count( $GLOBALS['sent'] ) );

// 2. TEST mode: identity frozen, order queued ~10 minutes ahead, logged, nothing sent.
set_opt( 'mode', 'test' );
$o  = checkout( $p );
$id = json_decode( (string) $o->get_meta( '_99w_ph_identity' ), true );
check( 'test mode: visitor read from the other plugin\'s cookie (pilot mode)', '0197-visitor-abc' === ( $id['distinct_id'] ?? '' ) && 'cookie' === ( $id['source'] ?? '' ) );
check( 'test mode: session kept while fresh', '0197-session-xyz' === ( $id['session_id'] ?? '' ) );
check( 'test mode: consent implied by the posthog-js cookie (no consent record)', 'implied' === ( $id['consent'] ?? '' ) );
check( 'test mode: 0.2.x fields still written', '0197-visitor-abc' === $o->get_meta( '_99w_ph_distinct_id' ) && 'yes' === $o->get_meta( '_99w_ph_cookie_found' ) );
$o->payment_complete( 'tr_2' );
$next = as_next_scheduled_action( 'w99_phwc_send', [ 'order_completed', $o->get_id(), 0 ], 'w99-phwc' );
check( 'test mode: order queued about 10 minutes ahead', $next && abs( $next - time() - 600 ) < 30, 'in ' . ( $next ? $next - time() : 'none' ) . ' s' );
run_pending();
check( 'test mode: order marked as logged, nothing sent', 'logged' === ( state( $o )['status'] ?? '' ) && 0 === count( $GLOBALS['sent'] ) );
$logged = $o;

// 3. ON, cookie URL-encoded as a browser sends it: Order Completed under the visitor, no identify.
set_opt( 'mode', 'on' );
$_COOKIE = [ $cookie_name => rawurlencode( $cookie_json ) ];
$o       = checkout( $p );
$o->payment_complete( 'tr_3' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'on: sent to the configured host and key, never the production key', 'https://test.example/batch/' === ( $GLOBALS['sent'][0]['url'] ?? '' ) && SEND_KEY === ( $GLOBALS['sent'][0]['body']['api_key'] ?? '' ) && false === strpos( wp_json_encode( $GLOBALS['sent'] ), PROD_KEY ) );
check( 'on: one Order Completed, no identify', 1 === count_of( 'Order Completed' ) && 0 === count_of( '$identify' ) );
check( 'on: under the visitor ID, on the visitor\'s person profile (default: every visitor)', $m && '0197-visitor-abc' === $m['distinct_id'] && ! isset( $m['properties']['$process_person_profile'] ) );
check( 'on: order_id, total, revenue, products', $m && (string) $o->get_id() === $m['properties']['order_id'] && 34.97 == $m['properties']['total'] && 34.97 == $m['properties']['revenue'] && 'TP-1' === $m['properties']['products'][0]['sku'] && '|TP-1|' === $m['properties']['products_string'] );
check( 'on: session and device carried', $m && '0197-session-xyz' === ( $m['properties']['$session_id'] ?? '' ) && '0197-device-abc' === ( $m['properties']['$device_id'] ?? '' ) );
$without_person = wp_json_encode( array_map( static function ( $r ) {
	foreach ( (array) ( $r['body']['batch'] ?? [] ) as $i => $ev ) {
		unset( $r['body']['batch'][ $i ]['properties']['$set'] );
	}
	return $r;
}, $GLOBALS['sent'] ) );
// Consent here is only implied (Automatic, no consent platform, a posthog-js cookie): no customer details either.
check( 'on: no email anywhere (consent only implied: no customer details)', false === strpos( $without_person, EMAIL ) && false === strpos( wp_json_encode( $GLOBALS['sent'] ), EMAIL ) && ! isset( $m['properties']['$set'] ) );
check( 'on: UUIDv5, server source, provenance', $m && 'server' === $m['properties']['tracking_source'] && 'cookie' === $m['properties']['identity_source'] && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $m['uuid'] ) );
check( 'on: buyer IP passed for location', $m && BUYER_IP === ( $m['properties']['$ip'] ?? '' ) && ! isset( $m['properties']['$geoip_disable'] ) );
$paid_ts = wc_get_order( $o->get_id() )->get_date_paid()->getTimestamp();
check( 'on: timestamp is the payment moment in true UTC (shop in Amsterdam time)', $m && strtotime( $m['timestamp'] ) === $paid_ts && 'Z' === substr( $m['timestamp'], -1 ), $m ? $m['timestamp'] . ' vs ' . gmdate( 'c', $paid_ts ) : '' );
check( 'on: order_placed_at in UTC', $m && strtotime( $m['properties']['order_placed_at'] ) === wc_get_order( $o->get_id() )->get_date_created()->getTimestamp() );
check( 'on: order marked as sent', 'sent' === ( state( $o )['status'] ?? '' ) );
check( 'on: 0.2.x sent marker written too (a rollback never sends it again)', '' !== (string) wc_get_order( $o->get_id() )->get_meta( '_99w_ph_sent_at' ) );

// 3b. A purchase only logged in test mode never reached PostHog: after switching on, its refunds are not sent.
wc_create_refund( [ 'order_id' => $logged->get_id(), 'amount' => 5 ] );
run_pending();
check( 'test then on: refund of a purchase logged in test mode not sent', 0 === count_of( 'Order Refunded' ) );

// 4. Later status changes: no second order event.
$o->update_status( 'completed' );
run_pending();
check( 'no double send on later status change', 1 === count_of( 'Order Completed' ) );

// 5. Classic checkout with the browser module's envelope: identity and browser snapshot from the form.
$_POST[ '_w99_ph_identity' ] = wp_json_encode( [ 'v' => 1, 'token' => SEND_KEY, 'distinct_id' => '0198-browser-def', 'device_id' => '0198-browser-def', 'session_id' => '0198-session-def', 'user_state' => 'anonymous', 'person_processing' => false, 'capturing' => true, 'consent_status' => 'pending', 'flags' => [ 'checkout-test' => 'test' ], 'props' => [ '$browser' => 'Mobile Safari', '$os' => 'iOS', '$device_type' => 'Mobile', '$session_entry_utm_source' => 'instagram', '$current_url' => 'https://shop.example/checkout/?key=wc_order_SECRET&x=1' ], 'captured_at' => gmdate( 'c' ) ] );
$_COOKIE                     = [];
set_opt( 'key', SEND_KEY );
$o = checkout( $p );
unset( $_POST['_w99_ph_identity'] );
$o->payment_complete( 'tr_5' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'envelope: identity from the browser module', $m && '0198-browser-def' === $m['distinct_id'] && 'browser' === $m['properties']['identity_source'] );
check( 'envelope: browser snapshot and attribution carried', $m && 'Mobile Safari' === ( $m['properties']['$browser'] ?? '' ) && 'instagram' === ( $m['properties']['$session_entry_utm_source'] ?? '' ) && [ 'checkout-test' => 'test' ] === ( $m['properties']['checkout_flags'] ?? null ) );
check( 'envelope: order key stripped from URLs', $m && false === strpos( wp_json_encode( $m ), 'wc_order_SECRET' ) && 'https://shop.example/checkout/?x=1' === ( $m['properties']['$current_url'] ?? '' ) );

// 6. No cookie and no envelope (no consent record, no identity): not sent.
$before_n = count( sent_events() );
$o        = checkout( $p );
$o->payment_complete( 'tr_6' );
run_pending();
check( 'no identity, no consent record: not sent, reason recorded', $before_n === count( sent_events() ) && 'skipped' === ( state( $o )['status'] ?? '' ) );

// 6b. Consent refused in Cookiebot while a posthog-js cookie lingers: not linked, not sent.
$_COOKIE = [ $cookie_name => $cookie_json, 'CookieConsent' => rawurlencode( "{stamp:'x',necessary:true,preferences:false,statistics:false,marketing:false,method:'explicit',ver:1}" ) ];
$o       = checkout( $p );
$o->payment_complete( 'tr_6b' );
run_pending();
$id = json_decode( (string) wc_get_order( $o->get_id() )->get_meta( '_99w_ph_identity' ), true );
check( 'cookiebot refused: consent_denied, not sent', 'consent_denied' === ( $id['reason'] ?? '' ) && $before_n === count( sent_events() ) );

// 6c. Count unconsented orders anonymously when the store chooses so: order-scoped ID, no IP, no person.
set_opt( 'unconsented_orders', 'anonymous' );
$o = checkout( $p );
$o->payment_complete( 'tr_6c' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'anonymous counting: order-scoped distinct ID, no IP, personless', $m && 0 === strpos( $m['distinct_id'], '99hog:order:' ) && ! isset( $m['properties']['$ip'] ) && false === $m['properties']['$process_person_profile'] && 'none' === $m['properties']['identity_source'] );
set_opt( 'unconsented_orders', 'skip' );

// 6d. Consent given again after a withdrawal: Cookiebot's answer wins over posthog-js's old opt-out cookie.
$granted_cb = rawurlencode( "{stamp:'y',necessary:true,preferences:false,statistics:true,marketing:false,method:'explicit',ver:1}" );
$_COOKIE    = [ $cookie_name => $cookie_json, '__ph_opt_in_out_' . PROD_KEY => '0', 'CookieConsent' => $granted_cb ];
$o          = checkout( $p );
$id         = json_decode( (string) $o->get_meta( '_99w_ph_identity' ), true );
check( 'consent given again after a withdrawal: order linked', true === ( $id['linked'] ?? null ) && 'granted' === ( $id['consent'] ?? '' ) );

// 6e. A later capture (payment retry from a browser without consent) never replaces the buyer frozen at checkout.
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$_COOKIE = [ 'CookieConsent' => rawurlencode( "{stamp:'z',necessary:true,preferences:false,statistics:false,marketing:false,method:'explicit',ver:1}" ) ];
$o       = wc_get_order( $o->get_id() );
do_action( 'woocommerce_checkout_create_order', $o, array() );
$o->save();
$id = json_decode( (string) wc_get_order( $o->get_id() )->get_meta( '_99w_ph_identity' ), true );
check( 'later capture without consent: identity from checkout kept', '0197-visitor-abc' === ( $id['distinct_id'] ?? '' ) && true === ( $id['linked'] ?? null ) );
$_COOKIE = [ $cookie_name => $cookie_json ];

// 7. Order placed before the plugin was switched on: nothing frozen, not sent.
set_opt( 'mode', 'off' );
$_COOKIE  = [ $cookie_name => $cookie_json ];
$before_n = count( sent_events() );
$o        = checkout( $p );
set_opt( 'mode', 'on' );
( new Orders( new Queue() ) )->maybe_queue_completed( $o->get_id() );
$o->payment_complete( 'tr_7' );
run_pending();
check( 'order from before switch-on: not sent', $before_n === count( sent_events() ) );

// 8. Broken or foreign cookie: no identity.
$_COOKIE = [ $cookie_name => '{not json', 'ph_otherkey_posthog' => $cookie_json ];
$o       = checkout( $p );
$id      = json_decode( (string) $o->get_meta( '_99w_ph_identity' ), true );
check( 'bad cookie: no identity', false === ( $id['linked'] ?? null ) );

// 9. Failed and staff orders: no purchase; a failed web order sends Payment Failed.
$_COOKIE  = [ $cookie_name => $cookie_json ];
$o        = checkout( $p );
$before_o = count_of( 'Order Completed' );
$o->update_status( 'failed' );
run_pending();
$pf = last_event( 'Payment Failed' );
check( 'failed order: no purchase, one Payment Failed', $before_o === count_of( 'Order Completed' ) && $pf && (string) $o->get_id() === $pf['properties']['order_id'] && 'failed' === $pf['properties']['failure_status'] );
$o = checkout( $p, 'admin' );
$o->payment_complete( 'tr_9' );
run_pending();
check( 'staff order: not sent', $before_o === count_of( 'Order Completed' ) );

// 9b. A paid status another plugin adds without registering it as paid: still a purchase.
add_filter( 'wc_order_statuses', static fn( $st ) => $st + [ 'wc-awaiting-ship' => 'Awaiting shipment' ] );
add_filter( 'woocommerce_payment_complete_order_status', static fn() => 'awaiting-ship' );
$_COOKIE  = [ $cookie_name => $cookie_json ];
$o        = checkout( $p );
$before_o = count_of( 'Order Completed' );
$o->payment_complete( 'tr_9b' );
run_pending();
check( 'custom paid status: purchase sent', $before_o + 1 === count_of( 'Order Completed' ) && 'awaiting-ship' === wc_get_order( $o->get_id() )->get_status() );
remove_all_filters( 'woocommerce_payment_complete_order_status' );

// 10. PostHog unreachable: retried with backoff, then sent with the same uuid, timestamp and identity.
$GLOBALS['http_fail'] = true;
$o                    = checkout( $p );
$o->payment_complete( 'tr_10' );
run_pending( 1 ); // The first attempt only; the retry is due in a minute.
$s     = state( $o );
$retry = as_next_scheduled_action( 'w99_phwc_send', [ 'order_completed', $o->get_id(), 0 ], 'w99-phwc' );
check( 'outage: first attempt failed, retry in ~1 minute', 'retrying' === ( $s['status'] ?? '' ) && $retry && abs( $retry - time() - 60 ) < 30 );
$GLOBALS['http_fail'] = false;
// Make the retry due now.
as_unschedule_action( 'w99_phwc_send', [ 'order_completed', $o->get_id(), 0 ], 'w99-phwc' );
as_enqueue_async_action( 'w99_phwc_send', [ 'order_completed', $o->get_id(), 0 ], 'w99-phwc' );
run_pending();
$tries = [];
foreach ( $GLOBALS['sent'] as $req ) {
	foreach ( $req['body']['batch'] ?? [] as $e ) {
		if ( 'Order Completed' === $e['event'] && (string) $o->get_id() === $e['properties']['order_id'] ) {
			$tries[] = $e['uuid'] . '|' . $e['timestamp'] . '|' . $e['distinct_id'];
		}
	}
}
check( 'outage: sent on retry', 'sent' === ( state( $o )['status'] ?? '' ) && 2 === ( state( $o )['attempts'] ?? 0 ) );
check( 'outage: byte-identical event on every try (uuid, timestamp, identity)', count( $tries ) >= 2 && 1 === count( array_unique( $tries ) ) );
check( 'outage: one pending action per order at a time', 0 === count( as_get_scheduled_actions( [ 'hook' => 'w99_phwc_send', 'args' => [ 'order_completed', $o->get_id(), 0 ], 'status' => 'pending' ], 'ids' ) ) );

// 10b. PostHog refuses the key (401): failed; the sweep retries it only once the key has changed.
$GLOBALS['http_status'] = 401;
$o                      = checkout( $p );
$o->payment_complete( 'tr_10b' );
run_pending();
check( 'refused key: order failed with the HTTP status', 'failed' === ( state( $o )['status'] ?? '' ) && 401 === ( state( $o )['http'] ?? 0 ) );
$GLOBALS['http_status'] = 200;
( new Queue() )->sweep();
run_pending();
check( 'refused key: not retried with the same key', 'failed' === ( state( $o )['status'] ?? '' ) );
set_opt( 'key', 'phc_FIXEDKEY' );
( new Queue() )->sweep();
run_pending();
check( 'refused key: sent once the key was changed', 'sent' === ( state( $o )['status'] ?? '' ) );
set_opt( 'key', SEND_KEY );

// 11. Refunds: partial then full, same visitor; refund before delivery waits for the purchase; none for unconsented orders.
$o = checkout( $p );
$o->payment_complete( 'tr_11' );
run_pending();
wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 10 ] );
run_pending();
$r = last_event( 'Order Refunded' );
check( 'refund: partial refund sent', $r && 10.0 === (float) $r['properties']['refund_amount'] && false === $r['properties']['full_refund'] && (string) $o->get_id() === $r['properties']['order_id'] && '0197-visitor-abc' === $r['distinct_id'] );
wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 24.97 ] );
run_pending();
$r = last_event( 'Order Refunded' );
check( 'refund: IP and true UTC time', $r && BUYER_IP === ( $r['properties']['$ip'] ?? '' ) && abs( strtotime( $r['timestamp'] ) - time() ) < 120 && 'Z' === substr( $r['timestamp'], -1 ) );
check( 'refund: full refund flagged', $r && true === $r['properties']['full_refund'] && 2 === count_of( 'Order Refunded' ) );
$o = checkout( $p );
$o->payment_complete( 'tr_11a' );
wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 5 ] ); // Before the purchase is delivered.
$before_r = count_of( 'Order Refunded' );
run_pending();
check( 'refund before delivery: sent after the purchase', count_of( 'Order Refunded' ) === $before_r + 1 && 'sent' === ( state( $o )['status'] ?? '' ) );
$_COOKIE = [];
$o       = checkout( $p );
$o->payment_complete( 'tr_11b' );
wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 5 ] );
$before_r = count_of( 'Order Refunded' );
run_pending();
check( 'refund: unconsented order not sent', $before_r === count_of( 'Order Refunded' ) );

// 11b. Paid order cancelled after a partial refund: only the rest is reversed.
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$o->payment_complete( 'tr_11c' );
run_pending();
wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 4.97 ] );
run_pending();
wc_get_order( $o->get_id() )->update_status( 'cancelled' );
run_pending();
$c = last_event( 'Order Cancelled' );
check( 'cancelled after partial refund: reverses only the rest', $c && 30.0 === (float) $c['properties']['total'] && (string) $o->get_id() === $c['properties']['order_id'] );

// 11c. Paid order cancelled, then refunded: the cancellation reversed everything; the refund reverses nothing more.
$o = checkout( $p );
$o->payment_complete( 'tr_11d' );
run_pending();
wc_get_order( $o->get_id() )->update_status( 'cancelled' );
$cancel_at = state( $o, '_99w_ph_order_cancelled' )['at'] ?? '';
run_pending();
$c = last_event( 'Order Cancelled' );
wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 10 ] );
run_pending();
$r = last_event( 'Order Refunded' );
check( 'cancelled then refunded: cancellation reverses the total', $c && (string) $o->get_id() === $c['properties']['order_id'] && 34.97 == $c['properties']['revenue'] );
check( 'cancelled then refunded: refund revenue 0, amount kept', $r && (string) $o->get_id() === $r['properties']['order_id'] && 0.0 === (float) $r['properties']['revenue'] && 10.0 === (float) $r['properties']['refund_amount'] && true === ( $r['properties']['after_cancellation'] ?? null ) );
check( 'cancellation timestamped at the status change', $c && '' !== $cancel_at && $cancel_at === $c['timestamp'] );

// 11d. Cancelled by mistake and reinstated before the cancellation was sent: nothing reversed.
$o = checkout( $p );
$o->payment_complete( 'tr_11e' );
run_pending();
$before_x = count_of( 'Order Cancelled' );
wc_get_order( $o->get_id() )->update_status( 'cancelled' );
wc_get_order( $o->get_id() )->update_status( 'processing' );
run_pending();
check( 'cancelled and reinstated before sending: no cancellation sent', $before_x === count_of( 'Order Cancelled' ) && [] === state( $o, '_99w_ph_order_cancelled' ) );

// 12. Checkout Started from the server: once per cart within 30 minutes, again when the cart changes.
WC()->initialize_session();
WC()->initialize_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $p->get_id(), 1 );
add_filter( 'woocommerce_is_checkout', '__return_true' );
$_SERVER['REQUEST_URI'] = '/checkouts/test-product/?utm_source=x&key=wc_order_SECRET';
$_SERVER['REMOTE_ADDR'] = '2a02:8108:1234::1';
$_COOKIE                = [ $cookie_name => $cookie_json ];

$before_c               = count_of( 'Checkout Started' );
( new Storefront( new Queue() ) )->checkout_started(); // One request each.
run_pending();
$c = last_event( 'Checkout Started' );
check( 'checkout: sent under the visitor', $c && '0197-visitor-abc' === $c['distinct_id'] && $before_c + 1 === count_of( 'Checkout Started' ) );
check( 'checkout: same properties as the previous plugin', $c && 34.97 == $c['properties']['value'] && 34.97 == $c['properties']['revenue'] && 'TP-1' === $c['properties']['products'][0]['sku'] && '|TP-1|' === $c['properties']['products_string'] && isset( $c['properties']['currency'], $c['properties']['shipping'] ) );
check( 'checkout: request IP passed', $c && '2a02:8108:1234::1' === ( $c['properties']['$ip'] ?? '' ) );
check( 'checkout: URL kept, order key stripped', $c && '/checkouts/test-product/' === $c['properties']['$pathname'] && false === strpos( $c['properties']['$current_url'], 'wc_order_SECRET' ) && false !== strpos( $c['properties']['$current_url'], 'utm_source=x' ) );
( new Storefront( new Queue() ) )->checkout_started(); // One request each.
run_pending();
check( 'checkout: reload within 30 minutes not counted again', $before_c + 1 === count_of( 'Checkout Started' ) );
WC()->cart->add_to_cart( $p->get_id(), 1 );
( new Storefront( new Queue() ) )->checkout_started(); // One request each.
run_pending();
check( 'checkout: changed cart counted again', $before_c + 2 === count_of( 'Checkout Started' ) );
$_COOKIE = [];
WC()->cart->add_to_cart( $p->get_id(), 1 );
( new Storefront( new Queue() ) )->checkout_started(); // One request each.
run_pending();
check( 'checkout: no identity, not sent', $before_c + 2 === count_of( 'Checkout Started' ) );
$_COOKIE = [ $cookie_name => $cookie_json ];
set_opt( 'ev_checkout', 'no' );
WC()->cart->add_to_cart( $p->get_id(), 1 );
( new Storefront( new Queue() ) )->checkout_started(); // One request each.
run_pending();
check( 'checkout: switched off in settings, not sent', $before_c + 2 === count_of( 'Checkout Started' ) );
set_opt( 'ev_checkout', 'yes' );
remove_filter( 'woocommerce_is_checkout', '__return_true' );

// 12b. Cart and coupon events from WooCommerce's own hooks.
$before_a = count_of( 'Product Added' );
WC()->cart->add_to_cart( $p->get_id(), 2 );
run_pending();
$a = last_event( 'Product Added' );
check( 'cart: Product Added from the cart hook', $a && $before_a + 1 === count_of( 'Product Added' ) && 2 === $a['properties']['quantity'] && 'TP-1' === $a['properties']['sku'] && '0197-visitor-abc' === $a['distinct_id'], 'before ' . $before_a . ' now ' . count_of( 'Product Added' ) . ' ' . wp_json_encode( $a ? array( $a['distinct_id'], $a['properties']['quantity'] ?? null, $a['properties']['sku'] ?? null ) : null ) );

// 12b2. Events settings: a renamed event is sent under its new name only; removals have their own switch.
update_option( \W99Phwc\Settings::EVENT_NAMES_OPTION, [ 'product_added' => 'Add to Cart' ] );
\W99Phwc\Settings::flush();
$renamed_before  = count_of( 'Add to Cart' );
$standard_before = count_of( 'Product Added' );
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'events: a renamed event is sent under its new name only', $renamed_before + 1 === count_of( 'Add to Cart' ) && $standard_before === count_of( 'Product Added' ) );
delete_option( \W99Phwc\Settings::EVENT_NAMES_OPTION );
set_opt( 'ev_cart_removed', 'no' );
$removed_before = count_of( 'Product Removed' );
$added_before   = count_of( 'Product Added' );
WC()->cart->remove_cart_item( array_key_first( WC()->cart->get_cart() ) );
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'events: Product Removed switched off on its own, Product Added still sent', $removed_before === count_of( 'Product Removed' ) && $added_before + 1 === count_of( 'Product Added' ) );
set_opt( 'ev_cart_removed', 'yes' );

// 12c. A funnel checkout that fills the cart while the page renders: still counted (one Storefront per request).
WC()->cart->empty_cart();
add_filter( 'woocommerce_is_checkout', '__return_true' );
$sf       = new Storefront( new Queue() );
$before_c = count_of( 'Checkout Started' );
$sf->checkout_started(); // template_include: cart still empty
WC()->cart->add_to_cart( $p->get_id(), 3 );
$sf->checkout_started(); // wp_footer
run_pending();
check( 'checkout: cart filled during rendering still counted', $before_c + 1 === count_of( 'Checkout Started' ) );
check( 'checkout: an ordinary page load is not marked speculative', ! isset( last_event( 'Checkout Started' )['properties']['speculative_load'] ) );

// 12d. The page fetched by the browser's speculative prefetch (WordPress's speculation rules): marked.
WC()->cart->add_to_cart( $p->get_id(), 1 );
$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
( new Storefront( new Queue() ) )->checkout_started();
unset( $_SERVER['HTTP_SEC_PURPOSE'] );
run_pending();
$cs = last_event( 'Checkout Started' );
$pa = last_event( 'Product Added' ); // added by an ordinary request just before
check( 'checkout: prefetched page marked speculative_load', true === ( $cs['properties']['speculative_load'] ?? null ) && ! isset( $pa['properties']['speculative_load'] ) );
remove_filter( 'woocommerce_is_checkout', '__return_true' );

// 13. IP rules: private IP or setting off → no IP, GeoIP disabled (never the server's location).
$o = checkout( $p, 'checkout', EMAIL, '192.168.1.5' ); // A private address recorded at checkout.
$o->payment_complete( 'tr_13b' );
run_pending();
$last = last_event( 'Order Completed' );
check( 'private IP: not passed, GeoIP disabled', $last && ! isset( $last['properties']['$ip'] ) && true === $last['properties']['$geoip_disable'] );
set_opt( 'send_ip', 'no' );
$o = checkout( $p );
$o->payment_complete( 'tr_13c' );
run_pending();
$last = last_event( 'Order Completed' );
check( 'IP setting off: not passed, GeoIP disabled', $last && ! isset( $last['properties']['$ip'] ) && true === $last['properties']['$geoip_disable'] );
set_opt( 'send_ip', 'yes' );

// 14. No project key: nothing sent, the order is not marked sent.
set_opt( 'key', '' );
$before_n = count( $GLOBALS['sent'] );
$o        = checkout( $p );
$o->payment_complete( 'tr_14' );
run_pending();
check( 'no project key: nothing sent, order not marked sent', $before_n === count( $GLOBALS['sent'] ) && 'sent' !== ( state( $o )['status'] ?? '' ) );
set_opt( 'key', SEND_KEY );

// 15. Explicit "read visitor from key" overrides the other plugin's key.
set_opt( 'cookie_key', 'phc_OTHER' );
$_COOKIE = [ 'ph_phc_OTHER_posthog' => wp_json_encode( [ 'distinct_id' => 'visitor-other' ] ) ];
$o       = checkout( $p );
check( 'cookie key setting used', 'visitor-other' === ( json_decode( (string) $o->get_meta( '_99w_ph_identity' ), true )['distinct_id'] ?? '' ) );
set_opt( 'cookie_key', '' );

// 16. Upgrade from 0.2.x: orders it sent are never re-sent; its queued tasks are routed, not lost.
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$o->update_meta_data( '_99w_ph_sent_at', gmdate( 'c' ) );
$o->save_meta_data();
$before_o = count_of( 'Order Completed' );
$o->payment_complete( 'tr_16' );
run_pending();
check( '0.2.x-sent order not re-sent', $before_o === count_of( 'Order Completed' ) );
$o16 = $o;
// Its refunds follow it while the key is still the one 0.2.x sent to, under 0.2.x's person (hashed e-mail).
update_option( 'w99_phwc_legacy_key', SEND_KEY );
wc_create_refund( [ 'order_id' => $o16->get_id(), 'amount' => 3 ] );
run_pending();
$r = last_event( 'Order Refunded' );
check( '0.2.x-sent purchase: refund sent under 0.2.x\'s person', $r && (string) $o16->get_id() === $r['properties']['order_id'] && wp_hash( EMAIL ) === $r['distinct_id'] );
update_option( 'w99_phwc_legacy_key', 'phc_PILOT' ); // After the rollout to another project, those purchases are not there.
$before_r = count_of( 'Order Refunded' );
wc_create_refund( [ 'order_id' => $o16->get_id(), 'amount' => 2 ] );
run_pending();
check( '0.2.x-sent purchase in another project: refund not sent', $before_r === count_of( 'Order Refunded' ) );
$o = checkout( $p );
$o->set_status( 'processing' );
$o->set_date_paid( time() );
$o->save();
as_unschedule_all_actions( 'w99_phwc_send', [], 'w99-phwc' );
$before_o = count_of( 'Order Completed' );
do_action( 'w99_phwc_send_order', $o->get_id() ); // A 0.2.x task firing after the upgrade.
run_pending();
check( '0.2.x queued order task routed into the new queue', $before_o + 1 === count_of( 'Order Completed' ) );

// 17. Browser module: off by default (pilot mode prints nothing); when on, the page config is the same for every visitor.
set_opt( 'browser', 'no' );
check( 'browser module off: nothing printed', ! \W99Phwc\Settings::browser_enabled() );
set_opt( 'browser', 'yes' );
check( 'browser module: automatic consent with no consent platform found stays off', ! \W99Phwc\Settings::browser_module_runs() );
set_opt( 'consent', 'cookiebot' );
$render = function ( $cookies ) {
	global $wp_scripts;
	$wp_scripts = new WP_Scripts();
	$_COOKIE    = $cookies;
	( new Storefront( new Queue() ) )->enqueue();
	return (string) $wp_scripts->get_data( 'w99-phwc', 'before' )[1] ?? '';
};
$a = $render( [ $cookie_name => $cookie_json ] );
$b = $render( [] );
check( 'browser module on: config printed, identical for two visitors (cache-safe)', '' !== $a && $a === $b && false === strpos( $a, '0197-visitor-abc' ) );
$customer = wp_insert_user( [ 'user_login' => 'buyer42', 'user_pass' => wp_generate_password(), 'user_email' => 'buyer42@example.test', 'role' => 'customer' ] );
wp_set_current_user( $customer );
$c = $render( [] );
wp_set_current_user( 0 );
check( 'browser module: a signed-in customer\'s page carries no account ID (cache-safe)', '' !== $c && false === strpos( $c, \W99Phwc\Settings::customer_distinct_id( (int) $customer ) ) && $c === $a );
// Settings from the panel reach the page: the consent switch, and further posthog-js options (protected ones dropped).
set_opt( 'early_start', 'yes' );
set_opt( 'posthog_js_options', '{"capture_heatmaps": false, "disable_session_recording": true, "person_profiles": "always", "persistence": "memory"}' );
$e   = $render( [] );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
check( 'panel: consent switch reaches the page', true === ( $cfg['earlyStart'] ?? null ) );
check( 'panel: further posthog-js options reach the page, protected ones dropped', false === ( $cfg['posthogConfig']['capture_heatmaps'] ?? null ) && true === ( $cfg['posthogConfig']['disable_session_recording'] ?? null ) && ! isset( $cfg['posthogConfig']['person_profiles'] ) && ! isset( $cfg['posthogConfig']['persistence'] ) );
set_opt( 'posthog_js_options', '{not json' );
$e   = $render( [] );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
check( 'panel: invalid JSON is ignored and reported', [] === (array) ( $cfg['posthogConfig'] ?? [ 'x' ] ) && \W99Phwc\Settings::posthog_js_options_invalid() );
set_opt( 'posthog_js_options', '' );
// Before the visitor answers the banner: 0.3.0's consent switch carries over; "Record the visit" and its page-storage option reach the page.
check( 'before consent: a saved 0.3.0 consent switch reads as "switched off"', 'switch' === \W99Phwc\Settings::before_consent() && 'switch' === ( $cfg['beforeConsent'] ?? null ) );
set_opt( 'before_consent', 'hold' );
set_opt( 'hold_across_pages', 'yes' );
$e   = $render( [] );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
check( 'before consent: "record the visit" reaches the page, with keeping it across pages', 'hold' === ( $cfg['beforeConsent'] ?? null ) && true === ( $cfg['earlyStart'] ?? null ) && true === ( $cfg['holdAcrossPages'] ?? null ) );
set_opt( 'before_consent', 'off' );
$e   = $render( [] );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
check( 'before consent: "wait" loads nothing before consent, though the old switch is still saved', 'off' === ( $cfg['beforeConsent'] ?? null ) && false === ( $cfg['earlyStart'] ?? null ) && false === ( $cfg['holdAcrossPages'] ?? null ) );
set_opt( 'before_consent', 'hold' );
set_opt( 'consent', 'none' );
$e   = $render( [] );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
$tag = ( new Storefront( new Queue() ) )->script_tag( '<script src="99hog.js"></script>', 'w99-phwc' );
check( 'track every visitor: no waiting, and Cookiebot told to leave the loader alone', 'none' === ( $cfg['consent'] ?? null ) && 'off' === ( $cfg['beforeConsent'] ?? null ) && false !== strpos( $tag, 'data-cookieconsent="ignore"' ) );
delete_option( 'w99_phwc_before_consent' );
set_opt( 'hold_across_pages', 'no' );
set_opt( 'early_start', 'no' );
set_opt( 'browser', 'no' );
set_opt( 'consent', 'auto' );

// 17b. Person profiles "Only logged-in customers": the server's events about anonymous visitors stay personless, like posthog-js.
set_opt( 'person_profiles', 'identified_only' );
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$o->payment_complete( 'tr_17b' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'person profiles "only logged-in customers": anonymous buyer\'s server purchase is personless', $m && (string) $o->get_id() === $m['properties']['order_id'] && false === ( $m['properties']['$process_person_profile'] ?? null ) );
set_opt( 'person_profiles', 'always' );

// 18. A copy of the shop carrying the live settings: sending stops until an administrator confirms the address.
\W99Phwc\Settings::arm(); // as on saving the settings with sending on
$lock_raw = (string) get_option( \W99Phwc\Settings::LOCK_OPTION );
$here     = \W99Phwc\Settings::site_address();
check( 'site lock: stored encoded, so moving the site\'s address does not rewrite it', '' !== $here && 0 === strpos( $lock_raw, 'b64:' ) && false === strpos( $lock_raw, $here ) && $here === \W99Phwc\Settings::locked_address() );
update_option( \W99Phwc\Settings::LOCK_OPTION, $here ); // a 0.3.0 preview's plain value: still read, re-stored encoded
check( 'site lock: a plain value for this address still counts as this site', ! \W99Phwc\Settings::site_moved() );
\W99Phwc\Settings::arm();
check( 'site lock: a plain value is re-stored encoded', 0 === strpos( (string) get_option( \W99Phwc\Settings::LOCK_OPTION ), 'b64:' ) );
update_option( \W99Phwc\Settings::LOCK_OPTION, 'b64:' . base64_encode( 'live-shop.example' ) );
$_COOKIE  = [ $cookie_name => $cookie_json ];
$before_n = count( $GLOBALS['sent'] );
$o        = checkout( $p );
$o->payment_complete( 'tr_18' );
run_pending();
check( 'copied site: nothing sent', $before_n === count( $GLOBALS['sent'] ) && ! \W99Phwc\Settings::can_send() );
\W99Phwc\Settings::arm( true );
$o = checkout( $p );
$o->payment_complete( 'tr_18b' );
run_pending();
check( 'copied site: sends again once an administrator confirms this address', \W99Phwc\Settings::can_send() && 'sent' === ( state( $o )['status'] ?? '' ) );

// 19. Erasure and export (WordPress privacy tools): IP, user agent and URLs removed; state and IDs kept.
$exp = ( new \W99Phwc\Privacy() )->export( EMAIL, 1 );
check( 'export: 99Trace data listed for the buyer\'s e-mail', ! empty( $exp['data'] ) && false !== strpos( wp_json_encode( $exp ), BUYER_IP ) );
WC_Privacy_Erasers::remove_order_personal_data( wc_get_order( $o->get_id() ) );
$ctx = json_decode( (string) wc_get_order( $o->get_id() )->get_meta( '_99w_ph_capture' ), true );
$st  = state( $o );
check( 'erasure: IP, user agent and URL removed, delivery state kept', ! isset( $ctx['ip'] ) && ! isset( $ctx['user_agent'] ) && ! isset( $st['event']['properties']['$ip'] ) && ! isset( $st['event']['properties']['$raw_user_agent'] ) && 'sent' === ( $st['status'] ?? '' ) && ! empty( $st['uuid'] ) );

// 20. 0.4.0 settings: event names, browser features, the tabbed settings page, the upgrade.
list( $kept, $rejected ) = \W99Phwc\Settings::clean_event_names( [ 'order_completed' => ' Purchase ', 'order_refunded' => '$refund', 'checkout_started' => 'Order Completed', 'cart_viewed' => str_repeat( 'x', 101 ), 'pageview' => 'Page', 'product_viewed' => 'Product Viewed' ] );
check( 'event names: kept trimmed; "$", another event\'s name and over 100 characters refused; PostHog\'s own ignored', [ 'order_completed' => 'Purchase' ] === $kept && [ 'Order Refunded', 'Checkout Started', 'Cart Viewed' ] === $rejected );
set_opt( 'browser', 'yes' );
set_opt( 'consent', 'cookiebot' );
update_option( \W99Phwc\Settings::EVENT_NAMES_OPTION, [ 'product_viewed' => 'Viewed Product', 'order_completed' => 'Purchase' ] );
set_opt( 'ft_replay', 'no' );
set_opt( 'ft_heatmaps', 'no' );
set_opt( 'ft_exceptions', 'yes' );
set_opt( 'posthog_js_options', '{"capture_heatmaps": true}' );
set_opt( 'capture_pageview', 'no' );
set_opt( 'ev_confirmation_viewed', 'no' );
$e   = $render( [] );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
check( 'browser: renamed browser events reach the page, server ones stay on the server', [ 'Product Viewed' => 'Viewed Product' ] === ( $cfg['eventNames'] ?? null ) );
check( 'browser features: unticked ones off, ticked extras on, further options on top', true === ( $cfg['posthogConfig']['disable_session_recording'] ?? null ) && true === ( $cfg['posthogConfig']['capture_heatmaps'] ?? null ) && true === ( $cfg['posthogConfig']['capture_exceptions'] ?? null ) && ! isset( $cfg['posthogConfig']['rageclick'] ) );
check( 'events: page-view and confirmation switches reach the page', false === ( $cfg['pageview'] ?? null ) && false === ( $cfg['events']['confirmationViewed'] ?? null ) );
// Three choices per feature (16 Nov): PostHog's setting sets nothing; Always on / Always off override it.
$page_cfg = static function () use ( $render ) {
	$e = $render( [] );
	return json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
};
set_opt( 'posthog_js_options', '' );
foreach ( [ 'ft_web_vitals', 'ft_heatmaps', 'ft_exceptions', 'ft_replay', 'ft_surveys', 'autocapture', 'capture_dead_clicks' ] as $k ) {
	delete_option( 'w99_phwc_' . $k );
}
\W99Phwc\Settings::flush();
$c  = $page_cfg();
$pc = $c['posthogConfig'] ?? [];
check( 'features: by default PostHog\'s project settings decide (nothing set for web vitals, heatmaps, errors, replay, surveys, dead clicks)', ! isset( $pc['capture_performance'], $pc['capture_heatmaps'], $pc['capture_exceptions'], $pc['disable_session_recording'], $pc['disable_surveys'] ) && array_key_exists( 'deadClicks', $c ) && null === $c['deadClicks'] && true === ( $c['autocapture'] ?? null ), wp_json_encode( [ $pc, $c['deadClicks'] ?? null, $c['autocapture'] ?? null ] ) );
set_opt( 'ft_web_vitals', 'off' );
set_opt( 'ft_heatmaps', 'on' );
set_opt( 'capture_dead_clicks', 'off' );
set_opt( 'autocapture', 'off' );
$c  = $page_cfg();
$pc = $c['posthogConfig'] ?? [];
check( 'features: Always off for web vitals leaves network timing to PostHog; Always on / off reach the page', [ 'web_vitals' => false ] === ( $pc['capture_performance'] ?? null ) && true === ( $pc['capture_heatmaps'] ?? null ) && false === ( $c['deadClicks'] ?? null ) && false === ( $c['autocapture'] ?? null ), wp_json_encode( [ $pc, $c['deadClicks'] ?? null ] ) );
set_opt( 'ft_web_vitals', 'on' );
set_opt( 'capture_dead_clicks', 'on' );
$c = $page_cfg();
check( 'features: Always on for web vitals and dead clicks', [ 'web_vitals' => true ] === ( $c['posthogConfig']['capture_performance'] ?? null ) && true === ( $c['deadClicks'] ?? null ) );
// 1.0.0: the developer filter may add options, but never replace the ones 99Trace's scrubbing and identity depend on.
$js_filter = static fn( $o ) => $o + [ 'before_send' => 'x', 'bootstrap' => [ 'distinctID' => 'y' ], 'persistence' => 'memory', 'session_idle_timeout_seconds' => 1800 ];
add_filter( 'w99_phwc_posthog_js_config', $js_filter );
$c = $page_cfg();
remove_filter( 'w99_phwc_posthog_js_config', $js_filter );
check( 'posthog-js filter: adds options, never protected ones (before_send, bootstrap, persistence)', ! isset( $c['posthogConfig']['before_send'], $c['posthogConfig']['bootstrap'], $c['posthogConfig']['persistence'] ) && 1800 === ( $c['posthogConfig']['session_idle_timeout_seconds'] ?? null ), wp_json_encode( $c['posthogConfig'] ?? null ) );
set_opt( 'ft_replay', 'on' ); // not a choice for replay: PostHog's switch decides whether it records
check( 'features: replay cannot be forced on from the page (read as PostHog\'s setting)', 'posthog' === \W99Phwc\Settings::feature( 'ft_replay' ) );
set_opt( 'ft_exceptions', 'yes' );
set_opt( 'ft_replay', 'no' );
\W99Phwc\Settings::normalize_features();
check( 'features: 0.4.0 tick boxes become the choices they meant (errors ticked = on, replay unticked = off)', 'on' === get_option( 'w99_phwc_ft_exceptions' ) && 'off' === get_option( 'w99_phwc_ft_replay' ) );
foreach ( [ 'ft_web_vitals', 'ft_heatmaps', 'ft_exceptions', 'ft_replay', 'ft_surveys', 'autocapture', 'capture_dead_clicks' ] as $k ) {
	delete_option( 'w99_phwc_' . $k );
}
\W99Phwc\Settings::flush();
set_opt( 'browser', 'no' ); // the purchase below: server events only, visitor from the test cookie as in the other purchase checks
set_opt( 'consent', 'auto' );
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$o->payment_complete( 'tr_20' );
run_pending();
check( 'events: a renamed purchase is sent once under its new name, with the usual UUID', 1 === count( array_filter( sent_events( 'Purchase' ), fn( $m ) => (string) $o->get_id() === $m['properties']['order_id'] ) ) && ( state( $o )['uuid'] ?? '' ) === ( last_event( 'Purchase' )['uuid'] ?? 'x' ) );
foreach ( [ 'ft_replay', 'ft_heatmaps', 'capture_pageview', 'ev_confirmation_viewed' ] as $k ) {
	set_opt( $k, 'yes' );
}
set_opt( 'ft_exceptions', 'no' );
set_opt( 'posthog_js_options', '' );
delete_option( \W99Phwc\Settings::EVENT_NAMES_OPTION );

require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
$page     = new \W99Phwc\SettingsPage();
$html     = '';
$all_desc = true;
$tabs_ok  = true;
foreach ( array_keys( \W99Phwc\Admin::sections() ) as $section ) {
	$fields  = $page->get_settings_for_section( $section );
	$tabs_ok = $tabs_ok && is_array( $fields ) && count( $fields ) >= 3;
	foreach ( $fields as $f ) {
		if ( ! in_array( $f['type'], [ 'title', 'sectionend', 'w99_phwc_events' ], true ) && '' === trim( (string) ( $f['desc'] ?? '' ) ) ) {
			$all_desc = false;
		}
	}
	ob_start();
	WC_Admin_Settings::output_fields( $fields );
	$html .= ob_get_clean();
}
check( 'settings page: six tabs render, and every setting is explained', 6 === count( \W99Phwc\Admin::sections() ) && $tabs_ok && $all_desc && false !== strpos( $html, 'Name in PostHog' ) && false !== strpos( $html, 'Track every visitor' ) && false !== strpos( $html, 'Session replay' ) );
check( 'settings page: Browser features offer PostHog\'s setting / Always on / Always off', false !== strpos( $html, 'PostHog&#039;s setting (recommended)' ) || false !== strpos( $html, "PostHog's setting (recommended)" ) );
\W99Phwc\Admin::save_events( [ 'w99_phwc_event_on' => [ 'ev_refund' => 'no', 'autocapture' => 'yes' ], 'w99_phwc_event_names' => [ 'order_completed' => 'Purchase', 'order_refunded' => '$bad' ] ] );
check( 'events table saved: switch and name kept, a "$" name refused', 'no' === get_option( 'w99_phwc_ev_refund' ) && [ 'order_completed' => 'Purchase' ] === get_option( \W99Phwc\Settings::EVENT_NAMES_OPTION ) );
set_opt( 'ev_refund', 'yes' );
delete_option( \W99Phwc\Settings::EVENT_NAMES_OPTION );

// 1.1.1 (WordPress.org review): the Events form is read only with WooCommerce's settings nonce and the manager's capability.
$w99_options = function () {
	global $wpdb;
	$out = [];
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'w99\\_phwc\\_%'" ) as $name ) {
		$out[ $name ] = get_option( $name );
	}
	return $out;
};
$w99_restore = function ( array $snapshot ) use ( $w99_options ) {
	foreach ( array_keys( $w99_options() ) as $name ) {
		if ( ! array_key_exists( $name, $snapshot ) ) {
			delete_option( $name );
		}
	}
	foreach ( $snapshot as $name => $value ) {
		update_option( $name, $value );
	}
	\W99Phwc\Settings::flush();
};
$w99_snapshot = $w99_options();
$GLOBALS['current_section'] = 'events';
wp_set_current_user( 1 );
$_POST    = [ 'w99_phwc_event_names' => [ 'order_completed' => 'Bought <b>it</b>' ] ];
$_REQUEST = [ '_wpnonce' => wp_create_nonce( 'woocommerce-settings' ) ];
$page->save();
check( 'events form: saved with the settings nonce, the name sanitized', [ 'order_completed' => 'Bought it' ] === get_option( \W99Phwc\Settings::EVENT_NAMES_OPTION ) );
$w99_restore( $w99_snapshot );
$_REQUEST = [];
$page->save();
check( 'events form: not read without the settings nonce', false === get_option( \W99Phwc\Settings::EVENT_NAMES_OPTION ) );
$w99_restore( $w99_snapshot );
wp_set_current_user( 0 );
$_REQUEST = [ '_wpnonce' => wp_create_nonce( 'woocommerce-settings' ) ];
$page->save();
check( 'events form: not read for a visitor without the manager capability', false === get_option( \W99Phwc\Settings::EVENT_NAMES_OPTION ) );
$w99_restore( $w99_snapshot );
$_POST    = [];
$_REQUEST = [];
$GLOBALS['current_section'] = '';
check( 'snapshot restored after the form checks', $w99_snapshot === $w99_options() );

// 1.1.1: IDs that text sanitizing would change are refused; the Meta click ID is sanitized.
check( 'IDs: tags or encoded characters are refused, real IDs kept', null === \W99Phwc\Util::clean_id( '<b>x</b>' ) && null === \W99Phwc\Util::clean_id( 'a%3Cb' ) && 'buyer@example.com' === \W99Phwc\Util::clean_id( 'buyer@example.com' ) && '019a7b2c-1d2e-7f30-8a41-5b6c7d8e9f00' === \W99Phwc\Util::clean_id( '019a7b2c-1d2e-7f30-8a41-5b6c7d8e9f00' ) );
$fbc_identity = Identity::from_cookie( wp_json_encode( [ 'distinct_id' => '019a7b2c-1d2e-7f30-8a41-5b6c7d8e9f00', '$fbc_persistence' => [ 'value' => 'fb.1.1763000000000.IwAR<b>x</b>2' ] ] ), time() );
check( 'Meta click ID from the cookie is sanitized', 'fb.1.1763000000000.IwARx2' === ( $fbc_identity['props']['$fbc'] ?? null ) );
$fbc_identity = Identity::from_cookie( wp_json_encode( [ 'distinct_id' => '019a7b2c-1d2e-7f30-8a41-5b6c7d8e9f00', '$fbc_persistence' => [ 'value' => 'fb.1.1763000000000.IwAR2xYz_-AbC' ] ] ), time() );
check( 'a real Meta click ID is unchanged', 'fb.1.1763000000000.IwAR2xYz_-AbC' === ( $fbc_identity['props']['$fbc'] ?? null ) );
\W99Phwc\Settings::flush();

update_option( 'w99_phwc_version', '0.3.0' );
set_opt( 'ev_cart', 'no' );
delete_option( 'w99_phwc_ev_cart_removed' );
$upgrade = new ReflectionMethod( \W99Phwc\Plugin::class, 'upgrade' );
$upgrade->setAccessible( true );
delete_option( 'w99_phwc_consent' );
delete_option( 'w99_phwc_unconsented_orders' );
delete_option( 'w99_phwc_consent_defaults' ); // a site that ran 0.4.0 or earlier has no record of the step
$upgrade->invoke( null );
check( 'upgrade from 0.3.0: Product Removed keeps the switch that covered it', 'no' === get_option( 'w99_phwc_ev_cart_removed' ) && W99_PHWC_TRACE_VERSION === get_option( 'w99_phwc_version' ) );
check( 'upgrade: a shop that never saved its consent settings keeps the old behaviour (follow the banner, orders without consent not sent)', 'auto' === get_option( 'w99_phwc_consent' ) && 'skip' === get_option( 'w99_phwc_unconsented_orders' ) && 'kept' === get_option( 'w99_phwc_consent_defaults' ) );
delete_option( 'w99_phwc_consent' );
update_option( 'w99_phwc_version', W99_PHWC_TRACE_VERSION ); // e.g. a site that ran the first 0.5.0 build: same version, step not yet recorded
delete_option( 'w99_phwc_consent_defaults' );
$upgrade->invoke( null );
check( 'upgrade step tied to its own record, not the version: a site already at this version still keeps its consent handling', 'auto' === get_option( 'w99_phwc_consent' ) );
set_opt( 'ev_cart', 'yes' );
set_opt( 'ev_cart_removed', 'yes' );
set_opt( 'browser', 'no' );
set_opt( 'consent', 'auto' );

// 21. 0.5.0: one visitor ID for browser and server (the shop's cookie), and behavioural events sent at the end of the request.
$_POST = [];
$_GET  = [];
set_opt( 'browser', 'yes' );
set_opt( 'consent', 'none' );
set_opt( 'unconsented_orders', 'anonymous' );
check( 'one visitor ID: on where the browser module runs', Visitor::enabled() );
$own_cookie  = 'ph_' . SEND_KEY . '_posthog';
$own_json    = wp_json_encode( [ 'distinct_id' => '0198-browser-id', '$device_id' => '0198-browser-id', '$user_state' => 'anonymous' ] );
$granted_cb  = rawurlencode( "{stamp:'g',necessary:true,preferences:false,statistics:true,marketing:false,method:'explicit',ver:1}" );
$denied_cb   = rawurlencode( "{stamp:'d',necessary:true,preferences:false,statistics:false,marketing:false,method:'explicit',ver:1}" );
$identity_of = static fn( $o ) => json_decode( (string) wc_get_order( $o->get_id() )->get_meta( '_99w_ph_identity' ), true );
$event_kept  = static fn( $uuid ) => false !== get_transient( 'w99_phwc_evt_' . $uuid ) || as_has_scheduled_action( Queue::HOOK_EVENT, [ $uuid, 1 ], Queue::GROUP );
WC()->cart->empty_cart();
$_COOKIE = [];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';
run_pending(); // Nothing from earlier sections left.

// 21a. The server acts first (an "add to cart" link as the landing page): it creates the ID, keeps it in the cookie, sends under it.
$before_a = count_of( 'Product Added' );
$sent_n   = count( $GLOBALS['sent'] );
WC()->cart->add_to_cart( $p->get_id(), 1 );
check( 'end of request: nothing sent while the request runs', $sent_n === count( $GLOBALS['sent'] ) );
$vid = Visitor::parse( $_COOKIE[ Visitor::COOKIE ] ?? '' );
check( 'server first: a new ID in posthog-js\'s form, kept in the cookie, marked as the server\'s', is_array( $vid ) && $vid['minted'] && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $vid['id'] ), (string) ( $_COOKIE[ Visitor::COOKIE ] ?? 'none' ) );
Queue::flush_events();
$a = last_event( 'Product Added' );
check( 'server first: Product Added under that ID, one person like any posthog-js visitor', $a && $vid && $vid['id'] === $a['distinct_id'] && 'shop_cookie' === ( $a['properties']['identity_source'] ?? '' ) && $vid['id'] === ( $a['properties']['$device_id'] ?? '' ) && ! isset( $a['properties']['$process_person_profile'] ) && $before_a + 1 === count_of( 'Product Added' ) );
check( 'end of request: sent in one batch, nothing written to the database', $a && $sent_n + 1 === count( $GLOBALS['sent'] ) && ! $event_kept( $a['uuid'] ) );

// 21a2. A crawler or link preview following an "add to cart" link: no ID created, nothing sent (as in 0.4.0).
$_COOKIE  = [];
$human_ua = $_SERVER['HTTP_USER_AGENT'];
$before_a = count_of( 'Product Added' );
foreach ( [ 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'WhatsApp/2.23.20.0 A', '' ] as $bot_ua ) {
	$_SERVER['HTTP_USER_AGENT'] = $bot_ua;
	WC()->cart->add_to_cart( $p->get_id(), 1 );
}
run_pending();
check( 'crawler or link preview: no visitor ID created, nothing sent', ! isset( $_COOKIE[ Visitor::COOKIE ] ) && $before_a === count_of( 'Product Added' ) );
$_SERVER['HTTP_USER_AGENT'] = $human_ua;

// 21b. The next requests carry the cookie (adopted by the browser): same ID, nothing rewritten.
$_COOKIE = [ Visitor::COOKIE => $vid['id'] ];
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'next request: the same visitor ID, the cookie left as it is', $vid['id'] === last_event( 'Product Added' )['distinct_id'] && $vid['id'] === $_COOKIE[ Visitor::COOKIE ] );

// 21c. posthog-js has its own cookie: its ID wins.
$_COOKIE = [ Visitor::COOKIE => $vid['id'], $own_cookie => $own_json ];
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
$a = last_event( 'Product Added' );
check( 'posthog-js\'s cookie wins over the shop cookie', '0198-browser-id' === $a['distinct_id'] && 'cookie' === ( $a['properties']['identity_source'] ?? '' ) );

// 21d. posthog-js blocked at checkout (an ad blocker): the order is on the same ID as the cart.
$_COOKIE = [ Visitor::COOKIE => $vid['id'] ];
$o       = checkout( $p );
$id      = $identity_of( $o );
check( 'posthog-js blocked at checkout: the order is linked to the shop\'s ID', true === ( $id['linked'] ?? null ) && 'shop_cookie' === ( $id['source'] ?? '' ) && $vid['id'] === ( $id['distinct_id'] ?? '' ) );
$o->payment_complete( 'tr_21d' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'posthog-js blocked: the purchase is on the cart\'s person', $m && (string) $o->get_id() === $m['properties']['order_id'] && $vid['id'] === $m['distinct_id'] && 'shop_cookie' === ( $m['properties']['identity_source'] ?? '' ) && ! isset( $m['properties']['$process_person_profile'] ) );

// 21e. No ID anywhere at checkout (no script ran, no cart event before): one is created for the buyer.
$_COOKIE = [];
$o       = checkout( $p );
$id      = $identity_of( $o );
$new     = Visitor::parse( $_COOKIE[ Visitor::COOKIE ] ?? '' );
check( 'no ID anywhere at checkout: one is created and the order linked to it', true === ( $id['linked'] ?? null ) && 'shop_cookie' === ( $id['source'] ?? '' ) && $new && $new['minted'] && $new['id'] === ( $id['distinct_id'] ?? '' ) );

// 21f. Consent modes: nothing created or sent before the answer, nor after a refusal; after consent, as above.
set_opt( 'consent', 'cookiebot' );
$_COOKIE  = [];
$before_a = count_of( 'Product Added' );
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'no answer yet: no ID created, nothing sent', ! isset( $_COOKIE[ Visitor::COOKIE ] ) && $before_a === count_of( 'Product Added' ) );
$_COOKIE = [ 'CookieConsent' => $denied_cb, Visitor::COOKIE => $vid['id'] ];
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'refused: a shop cookie left behind is not used', $before_a === count_of( 'Product Added' ) );
$_COOKIE = [ 'CookieConsent' => $granted_cb ];
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
$a = last_event( 'Product Added' );
check( 'consented, posthog-js blocked: an ID is created and used', $before_a + 1 === count_of( 'Product Added' ) && isset( $_COOKIE[ Visitor::COOKIE ] ) && 'shop_cookie' === ( $a['properties']['identity_source'] ?? '' ) && 'granted' === ( $a['properties']['consent'] ?? '' ) );

// 21g. A signed-in customer (no consent needed): the account; no visitor cookie created.
set_opt( 'consent', 'none' );
wp_set_current_user( $customer );
$_COOKIE = [];
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'signed in: the account ID, no visitor cookie created', \W99Phwc\Settings::customer_distinct_id( (int) $customer ) === last_event( 'Product Added' )['distinct_id'] && ! isset( $_COOKIE[ Visitor::COOKIE ] ) );
wp_set_current_user( 0 );
$_COOKIE = [ Visitor::COOKIE => $vid['id'] ];
do_action( 'wp_logout', (int) $customer );
check( 'logout: the visitor cookie is cleared', ! isset( $_COOKIE[ Visitor::COOKIE ] ) );

// 21h. An order placed before the answer, accepted on its thank-you page before it is sent: linked.
set_opt( 'consent', 'cookiebot' );
$_COOKIE = [];
$o       = checkout( $p );
check( 'order before the answer: not linked, waiting for a link', false === ( $identity_of( $o )['linked'] ?? null ) && Orders::awaits_link( wc_get_order( $o->get_id() ) ) );
$_GET = [ 'key' => wc_get_order( $o->get_id() )->get_order_key() ];
$sf   = new Storefront( new Queue() );
$sf->detect_order_page();
$wp_scripts = new WP_Scripts();
$sf->enqueue();
$e   = (string) ( $wp_scripts->get_data( 'w99-phwc', 'before' )[1] ?? '' );
$cfg = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
$_GET = [];
check( 'thank-you page: the order is offered for linking; no ID printed', true === ( $cfg['orderLink']['claimable'] ?? null ) && ! isset( $cfg['orderLink']['distinct_id'] ), wp_json_encode( $cfg['orderLink'] ?? null ) );
check( 'page config: the visitor cookie and the link address, the same for every visitor', Visitor::COOKIE === ( $cfg['visitorCookie'] ?? '' ) && false !== strpos( (string) ( $cfg['linkUrl'] ?? '' ), Visitor::AJAX ) );
$o->payment_complete( 'tr_21h' );
$env      = [ 'v' => 1, 'token' => SEND_KEY, 'distinct_id' => '0198-browser-id', 'device_id' => '0198-browser-id', 'session_id' => \W99Phwc\Uuid::v7( ( time() + 300 ) * 1000 ), 'user_state' => 'anonymous', 'capturing' => true, 'consent_status' => 'granted', 'props' => [ '$browser' => 'Mobile Safari' ] ];
$accepted = Identity::resolve( Identity::from_browser( wp_json_encode( $env ), SEND_KEY ), null, null, Consent::GRANTED, '' );
$ask      = static fn( $o, $env, $over = [] ) => $over + [ 'order_id' => (string) $o->get_id(), 'key' => wc_get_order( $o->get_id() )->get_order_key(), 'identity' => wp_json_encode( $env ) ];
$granted  = [ 'CookieConsent' => $granted_cb ];
check( 'link request refused: wrong order key', false === Visitor::link_request( $ask( $o, $env, [ 'key' => 'wc_order_NotTheKey' ] ), $granted ) );
check( 'link request refused: the request carries a refusal', false === Visitor::link_request( $ask( $o, $env ), [ 'CookieConsent' => $denied_cb ] ) );
check( 'link request refused: posthog-js not capturing', false === Visitor::link_request( $ask( $o, [ 'capturing' => false ] + $env ), $granted ) );
check( 'link request refused: an identified person or an account never takes a guest\'s order', false === Visitor::link_request( $ask( $o, [ 'user_state' => 'identified' ] + $env ), $granted ) && false === Visitor::link_request( $ask( $o, [ 'distinct_id' => 'wc:shop:7' ] + $env ), $granted ) );
add_filter( 'w99_phwc_return_link_max_age', $too_old = static fn() => -1 );
check( 'link request refused: older than the thank-you page link', false === Visitor::link_request( $ask( $o, $env ), $granted ) );
remove_filter( 'w99_phwc_return_link_max_age', $too_old );
check( 'none of the refused requests changed the order', false === ( $identity_of( $o )['linked'] ?? null ) );
check( 'accepted on the thank-you page: the order is linked', true === Visitor::link_request( $ask( $o, $env ), $granted ) && 'browser' === ( $identity_of( $o )['source'] ?? '' ) );
run_pending();
$m = last_event( 'Order Completed' );
check( 'linked after consent: purchase under the browser\'s ID, at the payment, without the session that began after it', $m && (string) $o->get_id() === $m['properties']['order_id'] && '0198-browser-id' === $m['distinct_id'] && ! isset( $m['properties']['$session_id'] ) && 'Mobile Safari' === ( $m['properties']['$browser'] ?? '' ) );
check( 'order box: the late link is recorded', isset( Orders::capture_context( wc_get_order( $o->get_id() ) )['linked_at'] ) );
$_COOKIE = [];
$o2      = checkout( $p );
$o2->payment_complete( 'tr_21h2' );
run_pending(); // never answered: counted anonymously
check( 'a purchase already sent (anonymously) is never linked afterwards', 'sent' === ( state( $o2 )['status'] ?? '' ) && 0 === strpos( (string) ( state( $o2 )['distinct_id'] ?? '' ), '99hog:order:' ) && false === Visitor::link_request( $ask( $o2, $env ), $granted ) );
$_COOKIE = [ 'CookieConsent' => $denied_cb ];
$o       = checkout( $p );
check( 'refused at checkout: never linked later', ! Orders::awaits_link( wc_get_order( $o->get_id() ) ) && false === Orders::link_later( wc_get_order( $o->get_id() ), $accepted ) );

// 21h2. "Switched off" before the answer: the checkout carries an envelope of a posthog-js that is not capturing.
set_opt( 'before_consent', 'switch' );
$_COOKIE                = [];
$_POST[ Orders::FIELD ] = wp_json_encode( [ 'v' => 1, 'token' => SEND_KEY, 'distinct_id' => '0198-switched-off', 'device_id' => '0198-switched-off', 'user_state' => 'anonymous', 'capturing' => false, 'consent_status' => 'pending' ] );
$o                      = checkout( $p );
unset( $_POST[ Orders::FIELD ] );
$id = $identity_of( $o );
check( 'switched off, not answered: no visitor ID created at checkout; consent unknown, not implied; waits for a link', ! isset( $_COOKIE[ Visitor::COOKIE ] ) && false === ( $id['linked'] ?? null ) && 'capture_disabled' === ( $id['reason'] ?? '' ) && 'unknown' === ( $id['consent'] ?? '' ) && Orders::awaits_link( wc_get_order( $o->get_id() ) ), wp_json_encode( [ $id['reason'] ?? null, $id['consent'] ?? null, $_COOKIE ] ) );
$o->payment_complete( 'tr_21h3' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'switched off, never accepted: counted anonymously, without IP or browser details', $m && (string) $o->get_id() === $m['properties']['order_id'] && 0 === strpos( $m['distinct_id'], '99hog:order:' ) && ! isset( $m['properties']['$ip'] ) && ! isset( $m['properties']['$raw_user_agent'] ) );
delete_option( 'w99_phwc_before_consent' );
\W99Phwc\Settings::flush();
$_COOKIE  = [ Visitor::COOKIE => $vid['id'] ];
$before_a = count_of( 'Product Added' );
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'no answer, only the shop\'s visitor cookie: no evidence of consent, nothing sent', $before_a === count_of( 'Product Added' ) );
add_filter( 'wp_consent_cookie_prefix', $prefix = static fn() => 'myshop' );
check( 'WP Consent API with its own cookie prefix: its stored refusal is read', Consent::DENIED === Consent::evaluate( 'wp_consent_api', [ 'myshop_statistics' => 'deny' ], SEND_KEY, true, static fn() => false ) && Consent::UNKNOWN === Consent::evaluate( 'wp_consent_api', [ 'wp_consent_statistics' => 'deny' ], SEND_KEY, true, static fn() => false ) );
remove_filter( 'wp_consent_cookie_prefix', $prefix );

// 21i. PostHog unreachable at the end of the request: tried twice, then kept in the database and retried.
set_opt( 'consent', 'none' );
$_COOKIE              = [ Visitor::COOKIE => $vid['id'] ];
$before_a             = count_of( 'Product Added' );
$GLOBALS['http_fail'] = true;
$sent_n               = count( $GLOBALS['sent'] );
WC()->cart->add_to_cart( $p->get_id(), 1 );
Queue::flush_events();
$tries                = array_slice( $GLOBALS['sent'], $sent_n );
$uuid                 = $tries[0]['body']['batch'][0]['uuid'] ?? '';
check( 'PostHog unreachable: tried twice, then kept for retries', 2 === count( $tries ) && '' !== $uuid && $event_kept( $uuid ) && false !== get_transient( Queue::DOWN ) );
$sent_n = count( $GLOBALS['sent'] );
WC()->cart->add_to_cart( $p->get_id(), 1 );
Queue::flush_events();
check( 'for five minutes after: no request waits on PostHog, its events are kept at once', $sent_n === count( $GLOBALS['sent'] ) );
$GLOBALS['http_fail'] = false;
run_pending( 5, 120 );
check( 'kept events retried: sent once PostHog answers, then removed', $before_a + 2 === count_of( 'Product Added' ) && false === get_transient( 'w99_phwc_evt_' . $uuid ) );
delete_transient( Queue::DOWN );
$o = checkout( $p );
add_filter( 'wp_doing_cron', '__return_true' );
$before_f = count_of( 'Payment Failed' );
wc_get_order( $o->get_id() )->update_status( 'failed' );
check( 'outside a shopper\'s request (cron): sent at once, not held to the end of a long run', $before_f + 1 === count_of( 'Payment Failed' ) );
remove_filter( 'wp_doing_cron', '__return_true' );

// 18p. Customer details on the person: with consent, the purchase sets e-mail, name and phone;
// never on an anonymous order; a sign-in sets the account's details; the setting switches it all off.
set_opt( 'consent', 'none' );
set_opt( 'person_details', 'yes' );
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p, 'checkout', 'Ana.Lopez@Example.test' );
$o->set_billing_first_name( 'Ana' );
$o->set_billing_last_name( 'Lopez' );
$o->set_billing_phone( '+33 6 12 34 56 78' );
$o->save();
$o->payment_complete( 'tr_18p' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'details: the purchase sets e-mail, name and phone on the buyer\'s person', $m && (int) $o->get_id() === (int) $m['properties']['order_id'] && [ 'email' => 'ana.lopez@example.test', 'first_name' => 'Ana', 'last_name' => 'Lopez', 'name' => 'Ana Lopez', 'phone' => '+33 6 12 34 56 78' ] === ( $m['properties']['$set'] ?? null ), wp_json_encode( $m['properties']['$set'] ?? null ) );
set_opt( 'consent', 'cookiebot' );
$_COOKIE = [];
$o       = checkout( $p );
$o->payment_complete( 'tr_18p2' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'details: never on an anonymous order (no answer to the banner)', $m && (int) $o->get_id() === (int) $m['properties']['order_id'] && ! isset( $m['properties']['$set'] ) );
set_opt( 'consent', 'none' );
set_opt( 'person_details', 'no' );
$_COOKIE = [ $cookie_name => $cookie_json ];
$o       = checkout( $p );
$o->payment_complete( 'tr_18p3' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'details: setting off, the purchase sets none', $m && (int) $o->get_id() === (int) $m['properties']['order_id'] && ! isset( $m['properties']['$set'] ) );
set_opt( 'person_details', 'yes' );
$member = wp_insert_user( [ 'user_login' => 'details18', 'user_pass' => wp_generate_password(), 'user_email' => 'details18@example.test', 'role' => 'customer' ] );
update_user_meta( $member, 'billing_first_name', 'Bo' );
// A sign-in: the details go on the browser's visitor (posthog-js's cookie), which the browser module then
// identifies as the account; never on the account ID itself (PostHog could refuse the later merge).
set_opt( 'browser', 'yes' );
$own_cookie = \W99Phwc\Identity::cookie_name( \W99Phwc\Settings::cookie_key() );
$_COOKIE    = [ $own_cookie => $cookie_json ];
$before     = count_of( '$set' );
do_action( 'wp_login', 'details18', get_user_by( 'id', $member ) );
run_pending();
$e = last_event( '$set' );
check( 'details: a sign-in sets the account\'s details on the browser\'s visitor', $before + 1 === count_of( '$set' ) && $e && '0197-visitor-abc' === $e['distinct_id'] && 'details18@example.test' === ( $e['properties']['$set']['email'] ?? '' ) && 'Bo' === ( $e['properties']['$set']['first_name'] ?? '' ), wp_json_encode( $e ) );
set_opt( 'consent', 'cookiebot' );
$_COOKIE = [];
$before  = count_of( '$set' );
do_action( 'wp_login', 'details18', get_user_by( 'id', $member ) );
run_pending();
check( 'details: a sign-in without consent sets nothing', $before === count_of( '$set' ) );
set_opt( 'consent', 'none' );
$_COOKIE = [];
$before  = count_of( '$set' );
do_action( 'wp_login', 'details18', get_user_by( 'id', $member ) );
run_pending();
check( 'details: a sign-in from a browser without posthog-js\'s visitor sets nothing (no person made for the account ahead of it)', $before === count_of( '$set' ) );
set_opt( 'browser', 'no' );
$_COOKIE = [ $own_cookie => $cookie_json ];
$before  = count_of( '$set' );
do_action( 'wp_login', 'details18', get_user_by( 'id', $member ) );
run_pending();
check( 'details: with 99Trace\'s browser module off (another plugin runs PostHog), a sign-in sets nothing', $before === count_of( '$set' ) );

// 21j. Switched off: 0.4.0's behaviour (only posthog-js's cookie; nothing created).
set_opt( 'visitor_cookie', 'no' );
$_COOKIE  = [];
$before_a = count_of( 'Product Added' );
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending();
check( 'one visitor ID off: no cookie created, nothing sent without posthog-js', ! isset( $_COOKIE[ Visitor::COOKIE ] ) && $before_a === count_of( 'Product Added' ) );
set_opt( 'visitor_cookie', 'yes' );
set_opt( 'browser', 'no' );
set_opt( 'consent', 'auto' );
set_opt( 'unconsented_orders', 'skip' );
$_COOKIE = [];
// 29. Corner cases of the team's changes (16 Nov): customer details and the browser-feature choices.
try {
$_COOKIE = [ $cookie_name => $cookie_json ];
set_opt( 'mode', 'on' );
set_opt( 'browser', 'no' );
set_opt( 'consent', 'none' );
set_opt( 'person_details', 'yes' );
set_opt( 'person_profiles', 'always' );
$o = checkout( $p, 'checkout', 'MiXeD.Case@Example.TEST' );
$o->set_billing_first_name( '  Zoé ' );
$o->set_billing_last_name( '' );
$o->set_billing_phone( '' );
$o->save();
$o->payment_complete( 'tr_29a' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'details corner: e-mail in lower case, names trimmed, empty fields left out', $m && [ 'email' => 'mixed.case@example.test', 'first_name' => 'Zoé', 'name' => 'Zoé' ] === ( $m['properties']['$set'] ?? null ), wp_json_encode( $m['properties']['$set'] ?? null ) );
// Persons only for signed-in customers: an anonymous buyer gets no details, a signed-in customer does.
set_opt( 'person_profiles', 'identified_only' );
$o = checkout( $p );
$o->payment_complete( 'tr_29c' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'details corner: identified_only, anonymous buyer: no details (no person is made)', $m && false === ( $m['properties']['$process_person_profile'] ?? null ) && ! isset( $m['properties']['$set'] ) );
$buyer = wp_insert_user( [ 'user_login' => 'corner29', 'user_pass' => wp_generate_password(), 'user_email' => 'corner29@example.test', 'role' => 'customer' ] );
wp_set_current_user( $buyer );
set_opt( 'identify_customers', 'yes' );
$_COOKIE = [];
$o = new WC_Order();
$o->set_created_via( 'checkout' );
$o->set_customer_id( $buyer ); // signed in at checkout: the account identity is captured
$o->add_product( $p, 1 );
$o->set_payment_method( 'mollie_wc_gateway_ideal' );
$o->set_billing_email( 'corner29@example.test' );
$o->set_customer_ip_address( BUYER_IP );
$o->calculate_totals();
do_action( 'woocommerce_checkout_create_order', $o, array() );
$o->save();
$o = wc_get_order( $o->get_id() );
$o->payment_complete( 'tr_29d' );
run_pending();
$m = last_event( 'Order Completed' );
check( 'details corner: identified_only, signed-in customer: details on the account\'s person', $m && \W99Phwc\Settings::customer_distinct_id( (int) $buyer ) === $m['distinct_id'] && 'corner29@example.test' === ( $m['properties']['$set']['email'] ?? '' ), wp_json_encode( $m ? [ $m['distinct_id'], $m['properties']['$set'] ?? null ] : null ) );
wp_set_current_user( 0 );
set_opt( 'person_profiles', 'always' );
// Staff signing in: never sent. A new customer account: its details set at once.
$staff  = wp_insert_user( [ 'user_login' => 'staff29', 'user_pass' => wp_generate_password(), 'user_email' => 'staff29@example.test', 'role' => 'shop_manager' ] );
$before = count_of( '$set' );
do_action( 'wp_login', 'staff29', get_user_by( 'id', $staff ) );
run_pending();
check( 'details corner: a shop manager signing in sends nothing', $before === count_of( '$set' ) );
set_opt( 'browser', 'yes' );
$_COOKIE = [ \W99Phwc\Identity::cookie_name( \W99Phwc\Settings::cookie_key() ) => $cookie_json ];
$new = wp_insert_user( [ 'user_login' => 'new29', 'user_pass' => wp_generate_password(), 'user_email' => 'New29@Example.test', 'role' => 'customer' ] );
do_action( 'woocommerce_created_customer', $new, [ 'user_login' => 'new29', 'user_email' => 'New29@Example.test', 'role' => 'customer' ], false );
run_pending();
$e = last_event( '$set' );
set_opt( 'browser', 'no' );
check( 'details corner: a new customer account sets its e-mail on the browser\'s visitor', $before + 1 === count_of( '$set' ) && $e && '0197-visitor-abc' === $e['distinct_id'] && 'new29@example.test' === ( $e['properties']['$set']['email'] ?? '' ) );
// WooCommerce's personal-data eraser removes the customer details from the stored purchase.
set_opt( 'consent', 'none' );
$_COOKIE = [ $cookie_name => $cookie_json ];
$o = checkout( $p, 'checkout', 'erase29@example.test' );
$o->payment_complete( 'tr_29e' );
run_pending();
$had = isset( state( $o )['event']['properties']['$set']['email'] );
do_action( 'woocommerce_privacy_remove_order_personal_data', wc_get_order( $o->get_id() ) );
$after = state( wc_get_order( $o->get_id() ) )['event']['properties'] ?? [];
check( 'details corner: erasing the order removes the customer details from the stored purchase', $had && ! isset( $after['$set'] ), wp_json_encode( [ $had, array_keys( $after ) ] ) );
// Cookiebot without its answer on record: a posthog-js cookie implies nothing (no cart event, no details, an order counted only per the setting).
set_opt( 'consent', 'cookiebot' );
set_opt( 'unconsented_orders', 'skip' );
$_COOKIE  = [ $cookie_name => $cookie_json ];
$before_a = count_of( 'Product Added' );
WC()->cart->add_to_cart( $p->get_id(), 1 );
run_pending( 5, 60 );
$o = checkout( $p );
$o->payment_complete( 'tr_29f' );
run_pending();
check( 'consent corner: Cookiebot without an answer on record, posthog-js cookie present: nothing linked or sent', $before_a === count_of( 'Product Added' ) && 'skipped' === ( state( $o )['status'] ?? '' ), wp_json_encode( [ count_of( 'Product Added' ) - $before_a, state( $o )['status'] ?? null ] ) );
set_opt( 'unconsented_orders', 'anonymous' );
// The feature value 0.4.0 stored for dead clicks ("yes", forced on then) now means PostHog's setting.
update_option( 'w99_phwc_capture_dead_clicks', 'yes' );
\W99Phwc\Settings::flush();
check( 'features corner: 0.4.0\'s stored "yes" for dead clicks follows PostHog (no longer forced on)', 'posthog' === \W99Phwc\Settings::feature( 'capture_dead_clicks' ) );
update_option( 'w99_phwc_capture_dead_clicks', 'no' );
\W99Phwc\Settings::flush();
check( 'features corner: 0.4.0\'s stored "no" for dead clicks stays off', 'off' === \W99Phwc\Settings::feature( 'capture_dead_clicks' ) );
delete_option( 'w99_phwc_capture_dead_clicks' );
set_opt( 'consent', 'auto' );
$_COOKIE = [];
} catch ( \Throwable $e ) {
	check( 'corner cases: ran without error', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}
wp_set_current_user( 0 );
set_opt( 'person_profiles', 'always' );
set_opt( 'consent', 'auto' );
$_COOKIE = [];
\W99Phwc\Settings::flush();

// 30. Every combination of the settings that interact.
@set_time_limit( 0 );
@ini_set( 'memory_limit', '1024M' );
try {
// 30a. The page: what the browser module is told, for each combination (720).
update_option( 'cookiebot-cbid', 'test-cbid' ); // a Cookiebot plugin is "installed", so Automatic resolves to Cookiebot
set_opt( 'posthog_js_options', '' );
$bad   = [];
$count = 0;
foreach ( [ 'off', 'test', 'on' ] as $mode ) {
	foreach ( [ 'yes', 'no' ] as $browser ) {
		foreach ( [ 'auto', 'cookiebot', 'wp_consent_api', 'posthog_opt_in', 'none' ] as $consent ) {
			foreach ( [ 'off', 'hold', 'switch' ] as $before ) {
				foreach ( [ 'yes', 'no' ] as $across ) {
					foreach ( [ 'always', 'identified_only' ] as $persons ) {
						foreach ( [ 'yes', 'no' ] as $flags ) {
							$count++;
							foreach ( [ 'mode' => $mode, 'browser' => $browser, 'consent' => $consent, 'before_consent' => $before, 'hold_across_pages' => $across, 'person_profiles' => $persons, 'ft_flags' => $flags ] as $k => $v ) {
								update_option( 'w99_phwc_' . $k, $v );
							}
							\W99Phwc\Settings::flush();
							$e        = $render( [] );
							$resolved = 'auto' === $consent ? 'cookiebot' : $consent;
							$want_on  = 'off' !== $mode && 'yes' === $browser;
							$label    = "$mode/$browser/$consent/$before/$across/$persons/flags:$flags";
							if ( ( '' !== $e ) !== $want_on ) {
								$bad[] = "$label: module " . ( '' !== $e ? 'printed' : 'missing' );
								continue;
							}
							if ( '' === $e ) {
								continue;
							}
							$c   = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
							$exp = in_array( $resolved, [ 'cookiebot', 'wp_consent_api' ], true ) ? $before : 'off';
							if ( 'switch' === $exp && 'no' === $flags ) {
								$exp = 'off';
							}
							$want = [
								'consent'         => $resolved,
								'beforeConsent'   => $exp,
								'earlyStart'      => 'off' !== $exp,
								'holdAcrossPages' => 'hold' === $exp && 'yes' === $across,
								'personProfiles'  => $persons,
								'testMode'        => 'test' === $mode,
								'flagsOff'        => 'no' === $flags,
							];
							$got = [
								'consent'         => $c['consent'] ?? null,
								'beforeConsent'   => $c['beforeConsent'] ?? null,
								'earlyStart'      => $c['earlyStart'] ?? null,
								'holdAcrossPages' => $c['holdAcrossPages'] ?? null,
								'personProfiles'  => $c['personProfiles'] ?? null,
								'testMode'        => $c['testMode'] ?? null,
								'flagsOff'        => true === ( $c['posthogConfig']['advanced_disable_feature_flags'] ?? false ),
							];
							if ( $want !== $got ) {
								$bad[] = $label . ': ' . wp_json_encode( array_diff_assoc( array_map( 'wp_json_encode', $want ), array_map( 'wp_json_encode', $got ) ) );
							}
						}
					}
				}
			}
		}
	}
}
check( "combinations: the page is right for all $count combinations of mode, browser, consent, before-consent, across pages, person profiles and experiments", 720 === $count && [] === $bad, implode( ' | ', array_slice( $bad, 0, 5 ) ) . ( count( $bad ) > 5 ? ' (+' . ( count( $bad ) - 5 ) . ' more)' : '' ) );
delete_option( 'cookiebot-cbid' );
foreach ( [ 'before_consent', 'hold_across_pages', 'ft_flags' ] as $k ) {
	delete_option( 'w99_phwc_' . $k );
}
set_opt( 'person_profiles', 'always' );
set_opt( 'browser', 'no' );
set_opt( 'consent', 'auto' );
set_opt( 'mode', 'on' );

// 30c. Customer details reach the page as set, in every consent mode.
update_option( 'cookiebot-cbid', 'test-cbid' ); // a Cookiebot plugin is "installed", so Automatic resolves to Cookiebot
$bad = [];
foreach ( [ 'auto', 'cookiebot', 'wp_consent_api', 'posthog_opt_in', 'none' ] as $consent ) {
	foreach ( [ 'yes', 'no' ] as $details ) {
		set_opt( 'mode', 'on' );
		set_opt( 'browser', 'yes' );
		set_opt( 'consent', $consent );
		set_opt( 'person_details', $details );
		$e = $render( [] );
		$c = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
		if ( ( 'yes' === $details ) !== ( $c['personDetails'] ?? null ) ) {
			$bad[] = "$consent/$details";
		}
	}
}
check( 'combinations: the customer-details setting reaches the page in every consent mode', [] === $bad, implode( ' | ', $bad ) );
delete_option( 'cookiebot-cbid' );
set_opt( 'person_details', 'yes' );

// 30d. Browser features: every combination of the three choices (648) reaches posthog-js as intended.
set_opt( 'mode', 'on' );
set_opt( 'browser', 'yes' );
set_opt( 'consent', 'none' );
$bad   = [];
$count = 0;
foreach ( [ 'posthog', 'off' ] as $ac ) {
foreach ( [ 'posthog', 'on', 'off' ] as $dc ) {
foreach ( [ 'posthog', 'on', 'off' ] as $wv ) {
foreach ( [ 'posthog', 'on', 'off' ] as $hm ) {
foreach ( [ 'posthog', 'off' ] as $rp ) {
foreach ( [ 'posthog', 'off' ] as $sv ) {
foreach ( [ 'posthog', 'on', 'off' ] as $ex ) {
	$count++;
	foreach ( [ 'autocapture' => $ac, 'capture_dead_clicks' => $dc, 'ft_web_vitals' => $wv, 'ft_heatmaps' => $hm, 'ft_replay' => $rp, 'ft_surveys' => $sv, 'ft_exceptions' => $ex ] as $k => $v ) {
		update_option( 'w99_phwc_' . $k, $v );
	}
	\W99Phwc\Settings::flush();
	$e  = $render( [] );
	$c  = json_decode( substr( $e, strpos( $e, '{' ), strrpos( $e, '}' ) - strpos( $e, '{' ) + 1 ), true );
	$pc = $c['posthogConfig'] ?? [];
	$tri  = static fn( $v ) => 'posthog' === $v ? 'unset' : ( 'on' === $v );
	$want = [ 'off' !== $ac, $tri( $dc ), 'posthog' === $wv ? 'unset' : [ 'web_vitals' => 'on' === $wv ], $tri( $hm ), 'off' === $rp ? true : 'unset', 'off' === $sv ? true : 'unset', $tri( $ex ) ];
	$got  = [ $c['autocapture'] ?? null, array_key_exists( 'deadClicks', $c ) && null !== $c['deadClicks'] ? $c['deadClicks'] : 'unset', $pc['capture_performance'] ?? 'unset', $pc['capture_heatmaps'] ?? 'unset', $pc['disable_session_recording'] ?? 'unset', $pc['disable_surveys'] ?? 'unset', $pc['capture_exceptions'] ?? 'unset' ];
	if ( $want !== $got ) {
		$bad[] = "$ac/$dc/$wv/$hm/$rp/$sv/$ex: " . wp_json_encode( $got );
	}
}}}}}}}
check( "combinations: the browser features are right for all $count combinations of the three choices", 648 === $count && [] === $bad, implode( ' | ', array_slice( $bad, 0, 5 ) ) );
foreach ( [ 'autocapture', 'capture_dead_clicks', 'ft_web_vitals', 'ft_heatmaps', 'ft_replay', 'ft_surveys', 'ft_exceptions' ] as $k ) {
	delete_option( 'w99_phwc_' . $k );
}
set_opt( 'browser', 'no' );
set_opt( 'consent', 'auto' );
gc_collect_cycles();

// 30b. Purchases and refunds: for each combination (64), what PostHog receives.
$bad   = [];
$count = 0;
$visitor_cookie = $cookie_json;
foreach ( [ 'test', 'on' ] as $mode ) {
	foreach ( [ 'none', 'granted', 'denied', 'no_answer' ] as $answer ) {
		foreach ( [ 'skip', 'anonymous' ] as $unconsented ) {
			foreach ( [ 'always', 'identified_only' ] as $persons ) {
				foreach ( [ 'yes', 'no' ] as $ev_order ) {
				foreach ( [ 'yes', 'no' ] as $details ) {
					$count++;
					$GLOBALS['sent'] = []; // keep memory low over the run
					set_opt( 'person_details', $details );
					set_opt( 'mode', $mode );
					set_opt( 'consent', 'none' === $answer ? 'none' : 'cookiebot' );
					set_opt( 'unconsented_orders', $unconsented );
					set_opt( 'person_profiles', $persons );
					set_opt( 'ev_order', $ev_order );
					$_COOKIE = [];
					if ( 'no_answer' !== $answer ) {
						$_COOKIE[ $cookie_name ] = $visitor_cookie;
					}
					if ( 'granted' === $answer || 'denied' === $answer ) {
						$_COOKIE['CookieConsent'] = "{stamp:'x',necessary:true,preferences:false,statistics:" . ( 'granted' === $answer ? 'true' : 'false' ) . ",marketing:false,method:'explicit',ver:1}";
					}
					$before_n = count( $GLOBALS['sent'] );
					$o        = checkout( $p );
					$o->payment_complete( 'tr_21_' . $count );
					run_pending();
					wc_create_refund( [ 'order_id' => $o->get_id(), 'amount' => 5, 'reason' => 'combo' ] );
					run_pending();
					$new  = array_slice( $GLOBALS['sent'], $before_n );
					$evts = [];
					foreach ( $new as $req ) {
						foreach ( $req['failed'] ? [] : ( $req['body']['batch'] ?? [] ) as $m ) {
							if ( (string) ( $m['properties']['order_id'] ?? '' ) === (string) $o->get_id() ) {
								$evts[] = $m;
							}
						}
					}
					$purchase = array_values( array_filter( $evts, fn( $m ) => 'Order Completed' === $m['event'] ) );
					$refunds  = array_values( array_filter( $evts, fn( $m ) => 'Order Refunded' === $m['event'] ) );
					$linked   = in_array( $answer, [ 'none', 'granted' ], true );
					$sends    = 'on' === $mode && 'yes' === $ev_order && ( $linked || 'anonymous' === $unconsented );
					$label    = "$mode/$answer/$unconsented/$persons/order:$ev_order/details:$details";
					if ( count( $purchase ) !== ( $sends ? 1 : 0 ) || count( $refunds ) !== ( $sends ? 1 : 0 ) ) {
						$bad[] = "$label: purchases " . count( $purchase ) . ', refunds ' . count( $refunds );
						continue;
					}
					if ( ! $sends ) {
						continue;
					}
					$m          = $purchase[0];
					$want_id    = $linked ? '0197-visitor-abc' : '99hog:order:';
					$personless = ! $linked || 'identified_only' === $persons;
					$ok = 0 === strpos( $m['distinct_id'], $want_id )
						&& $personless === ( false === ( $m['properties']['$process_person_profile'] ?? null ) )
						&& $refunds[0]['distinct_id'] === $m['distinct_id']
						&& ( $linked ? isset( $m['properties']['$ip'] ) : ! isset( $m['properties']['$ip'] ) )
						// Customer details only on a person PostHog keeps, with consent, when the setting is on.
						&& ( $linked && ! $personless && 'yes' === $details ) === ( EMAIL === ( $m['properties']['$set']['email'] ?? null ) )
						&& ! isset( $refunds[0]['properties']['$set'] );
					if ( ! $ok ) {
						$bad[] = "$label: " . wp_json_encode( [ $m['distinct_id'], $m['properties']['$process_person_profile'] ?? null, $refunds[0]['distinct_id'], isset( $m['properties']['$ip'] ), $m['properties']['$set'] ?? null ] );
					}
					gc_collect_cycles();
				}
				}
			}
		}
	}
}
check( "combinations: purchases and refunds are right for all $count combinations of mode, consent answer, orders without consent, person profiles, Order Completed and customer details", 128 === $count && [] === $bad, implode( ' | ', array_slice( $bad, 0, 5 ) ) . ( count( $bad ) > 5 ? ' (+' . ( count( $bad ) - 5 ) . ' more)' : '' ) );
$_COOKIE = [];
set_opt( 'mode', 'on' );
set_opt( 'consent', 'auto' );
set_opt( 'unconsented_orders', 'skip' );
set_opt( 'person_profiles', 'always' );
set_opt( 'ev_order', 'yes' );
set_opt( 'person_details', 'yes' );

} catch ( \Throwable $e ) {
	check( 'combinations: ran without error', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

// 31. Switching over from another plugin (0.5.1): a project gets only the purchases paid after this shop
// started sending to it. The previous plugin reported the earlier ones; a later status change (shipping)
// or the sweep must not report them again.
try {
	$GLOBALS['sent'] = [];
	set_opt( 'consent', 'none' );
	set_opt( 'unconsented_orders', 'anonymous' );
	set_opt( 'mode', 'off' );
	$before = checkout( $p );
	$before->payment_complete( 'tr_31a' ); // Paid while the previous plugin tracked the shop.
	$before = wc_get_order( $before->get_id() );
	$before->set_date_paid( time() - DAY_IN_SECONDS );
	$before->save();
	delete_option( \W99Phwc\Settings::SINCE_OPTION );
	set_opt( 'mode', 'on' );
	check( 'switch-over: no start recorded before sending starts', null === \W99Phwc\Settings::sending_since() );
	\W99Phwc\Settings::mark_sending();
	$since = \W99Phwc\Settings::sending_since();
	check( 'switch-over: the start of sending to this project is recorded', null !== $since && abs( $since - time() ) <= 5 );
	\W99Phwc\Settings::mark_sending();
	check( 'switch-over: recorded once', $since === \W99Phwc\Settings::sending_since() );
	( new Queue() )->sweep();
	wc_get_order( $before->get_id() )->update_status( 'completed' ); // Shipped after the switch.
	run_pending();
	$resent = array_filter( sent_events( 'Order Completed' ), fn( $e ) => (string) $before->get_id() === (string) ( $e['properties']['order_id'] ?? '' ) );
	check( 'switch-over: a purchase paid before the switch is not sent (sweep, later status change)', [] === $resent && [] === state( $before ) && ! Orders::should_track( wc_get_order( $before->get_id() ) ) );
	$after = checkout( $p );
	$after->payment_complete( 'tr_31b' );
	run_pending();
	check( 'switch-over: a purchase paid after the switch is sent', 'sent' === ( state( $after )['status'] ?? '' ) && 1 === count( array_filter( sent_events( 'Order Completed' ), fn( $e ) => (string) $after->get_id() === (string) ( $e['properties']['order_id'] ?? '' ) ) ) );
	set_opt( 'key', 'phc_OTHERPROJ' );
	check( 'switch-over: another project has its own start', null === \W99Phwc\Settings::sending_since() );
	set_opt( 'key', SEND_KEY );
	check( 'switch-over: the first project keeps its start', $since === \W99Phwc\Settings::sending_since() );
	set_opt( 'mode', 'test' );
	delete_option( \W99Phwc\Settings::SINCE_OPTION );
	\W99Phwc\Settings::mark_sending();
	check( 'switch-over: test mode records no start (nothing reaches the project)', null === \W99Phwc\Settings::sending_since() );
} catch ( \Throwable $e ) {
	check( 'switch-over: ran without error', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}
delete_option( \W99Phwc\Settings::SINCE_OPTION );
set_opt( 'mode', 'on' );
set_opt( 'consent', 'auto' );
set_opt( 'unconsented_orders', 'skip' );

// 32. Who is recorded (0.6.0): shop staff, page editors and preview loads are not recorded by default; a cart
// the checkout page fills while loading is not an add to cart; server events of a page the browser loaded in
// advance wait until it is shown (a press or a touch prefetches pages the visitor never opens).
try {
	global $wp_actions;
	$GLOBALS['sent'] = [];
	set_opt( 'consent', 'none' );
	set_opt( 'unconsented_orders', 'anonymous' );
	set_opt( 'mode', 'on' );
	set_opt( 'browser', 'no' );
	check( 'who: defaults, staff, page editors and previews not recorded; checkout-page carts not sent', ! \W99Phwc\Settings::on( 'record_staff' ) && ! \W99Phwc\Settings::on( 'record_editors' ) && ! \W99Phwc\Settings::on( 'record_previews' ) && ! \W99Phwc\Settings::on( 'cart_checkout_fill' ) );
	add_role( 'page_builder32', 'Page builder (can edit pages, not orders)', [ 'read' => true, 'edit_pages' => true, 'edit_others_pages' => true, 'publish_pages' => true, 'edit_posts' => true, 'edit_products' => true ] );
	$u = [];
	foreach ( [ 'administrator', 'shop_manager', 'editor', 'page_builder32', 'customer' ] as $role ) {
		$u[ $role ] = (int) wp_insert_user( [ 'user_login' => 'who32_' . $role, 'user_pass' => wp_generate_password(), 'user_email' => 'who32_' . $role . '@example.test', 'role' => $role ] );
	}
	$records = fn() => array_map( fn( $id ) => \W99Phwc\Audience::records_user( $id ), $u );
	check( 'who: by default only customers are recorded (not administrators, shop managers, editors or a custom page-building role)', [ 'administrator' => false, 'shop_manager' => false, 'editor' => false, 'page_builder32' => false, 'customer' => true ] === $records(), wp_json_encode( $records() ) );
	set_opt( 'record_editors', 'yes' );
	check( 'who: page editors recorded on their own (staff still not)', [ 'administrator' => false, 'shop_manager' => false, 'editor' => true, 'page_builder32' => true, 'customer' => true ] === $records(), wp_json_encode( $records() ) );
	set_opt( 'record_editors', 'no' );
	set_opt( 'record_staff', 'yes' );
	check( 'who: shop staff recorded on their own (page editors still not)', [ 'administrator' => true, 'shop_manager' => true, 'editor' => false, 'page_builder32' => false, 'customer' => true ] === $records(), wp_json_encode( $records() ) );
	set_opt( 'record_staff', 'no' );
	add_filter( 'w99_phwc_track_staff', '__return_true' );
	check( 'who: the 0.5 filter w99_phwc_track_staff still records staff', true === \W99Phwc\Audience::records_user( $u['shop_manager'] ) && false === \W99Phwc\Audience::records_user( $u['editor'] ) );
	remove_filter( 'w99_phwc_track_staff', '__return_true' );

	// A page editor testing the shop: no cart or checkout event, no browser module; recorded when the shop says so.
	$_COOKIE = [ $cookie_name => $cookie_json ];
	WC()->cart->empty_cart();
	wp_set_current_user( $u['page_builder32'] );
	$before_a = count_of( 'Product Added' );
	$before_c = count_of( 'Checkout Started' );
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	add_filter( 'woocommerce_is_checkout', '__return_true' );
	( new Storefront( new Queue() ) )->checkout_started();
	remove_filter( 'woocommerce_is_checkout', '__return_true' );
	run_pending( 5, 60 );
	set_opt( 'browser', 'yes' );
	$page = $render( [ $cookie_name => $cookie_json ] );
	set_opt( 'browser', 'no' );
	check( 'who: a page editor\'s cart, checkout and pages send nothing', $before_a === count_of( 'Product Added' ) && $before_c === count_of( 'Checkout Started' ) && '' === $page, wp_json_encode( [ count_of( 'Product Added' ) - $before_a, count_of( 'Checkout Started' ) - $before_c, strlen( $page ) ] ) );
	set_opt( 'record_editors', 'yes' );
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	run_pending( 5, 60 );
	check( 'who: page editors set to Recorded: their cart is sent', $before_a + 1 === count_of( 'Product Added' ) );
	set_opt( 'record_editors', 'no' );
	// A purchase by a page editor still counts, without their visits (as for staff).
	$o = checkout( $p );
	$o->payment_complete( 'tr_32a' );
	wp_set_current_user( 0 );
	run_pending();
	$m = state( $o );
	check( 'who: a page editor\'s own purchase is still sent, not joined to their visits', 'sent' === ( $m['status'] ?? '' ) && '0197-visitor-abc' !== ( $m['event']['distinct_id'] ?? '' ), wp_json_encode( [ $m['status'] ?? null, $m['event']['distinct_id'] ?? null ] ) );

	// Preview loads: Elementor's editor frame, WordPress previews, AJAX calls made from a preview.
	wp_set_current_user( $u['customer'] );
	$before_a           = count_of( 'Product Added' );
	$_GET['elementor-preview'] = '123';
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	unset( $_GET['elementor-preview'] );
	$_GET['preview'] = 'true';
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	unset( $_GET['preview'] );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$_SERVER['HTTP_REFERER'] = home_url( '/checkouts/test-product/?elementor-preview=123&ver=1' );
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	$_SERVER['HTTP_REFERER'] = home_url( '/checkouts/test-product/' );
	WC()->cart->add_to_cart( $p->get_id(), 1 ); // an ordinary AJAX add on the same page: recorded
	remove_filter( 'wp_doing_ajax', '__return_true' );
	unset( $_SERVER['HTTP_REFERER'] );
	run_pending( 5, 60 );
	check( 'who: preview loads and their AJAX calls send nothing; the same page outside a preview does', $before_a + 1 === count_of( 'Product Added' ), (string) ( count_of( 'Product Added' ) - $before_a ) );
	check(
		'who: preview and editor addresses recognised, shop pages not',
		\W99Phwc\Audience::is_preview_url( home_url( '/wp-admin/post.php?post=9&action=elementor' ) ) && \W99Phwc\Audience::is_preview_url( home_url( '/wp-admin/customize.php?url=x' ) )
			&& \W99Phwc\Audience::is_preview_url( home_url( '/?p=9&preview_id=9&preview_nonce=abc&preview=true' ) ) && \W99Phwc\Audience::is_preview_url( home_url( '/offer/?wfocu_customize=loaded' ) )
			&& ! \W99Phwc\Audience::is_preview_url( home_url( '/checkouts/test-product/?utm_source=x' ) ) && ! \W99Phwc\Audience::is_preview_url( home_url( '/wp-admin/post.php?post=9&action=edit' ) ) && ! \W99Phwc\Audience::is_preview_url( '' )
	);
	set_opt( 'record_previews', 'yes' );
	$_GET['elementor-preview'] = '123';
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	unset( $_GET['elementor-preview'] );
	set_opt( 'record_previews', 'no' );
	wp_set_current_user( 0 );
	$_GET['elementor-preview'] = '123'; // a shopper's link that happens to carry the parameter (only signed-in users can preview)
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	unset( $_GET['elementor-preview'] );
	run_pending( 5, 60 );
	check( 'who: previews set to Recorded are sent; a signed-out visitor is never treated as a preview', $before_a + 3 === count_of( 'Product Added' ), (string) ( count_of( 'Product Added' ) - $before_a ) );

	// A checkout page that fills the cart while it loads (FunnelKit): not an add to cart by default; Checkout Started still counted.
	WC()->cart->empty_cart();
	WC()->session->set( 'w99_phwc_checkout', null ); // no checkout counted for this cart in the last 30 minutes
	add_filter( 'woocommerce_is_checkout', '__return_true' );
	$wp_actions['wp'] = 1; // the page's main query has run, as when a funnel checkout renders
	$before_a         = count_of( 'Product Added' );
	$before_c         = count_of( 'Checkout Started' );
	$sf               = new Storefront( new Queue() );
	$sf->checkout_started(); // template_include: empty cart
	WC()->cart->add_to_cart( $p->get_id(), 1 ); // the funnel fills it
	$sf->checkout_started();
	run_pending( 5, 60 );
	check( 'checkout fill: no Product Added for the cart the checkout page fills; Checkout Started sent', $before_a === count_of( 'Product Added' ) && $before_c + 1 === count_of( 'Checkout Started' ), wp_json_encode( [ count_of( 'Product Added' ) - $before_a, count_of( 'Checkout Started' ) - $before_c ] ) );
	set_opt( 'cart_checkout_fill', 'yes' );
	WC()->cart->add_to_cart( $p->get_id(), 2 );
	run_pending( 5, 60 );
	$a = last_event( 'Product Added' );
	check( 'checkout fill: set to Sent, it is Product Added marked source checkout_page', $before_a + 1 === count_of( 'Product Added' ) && 'checkout_page' === ( $a['properties']['source'] ?? '' ) );
	set_opt( 'cart_checkout_fill', 'no' );
	unset( $wp_actions['wp'] );
	remove_filter( 'woocommerce_is_checkout', '__return_true' );
	WC()->cart->add_to_cart( $p->get_id(), 1 ); // the shopper adds on an ordinary page
	run_pending( 5, 60 );
	check( 'checkout fill: a product the shopper adds is still sent', $before_a + 2 === count_of( 'Product Added' ) && 'checkout_page' !== ( last_event( 'Product Added' )['properties']['source'] ?? '' ) );

	// A checkout the browser loaded in advance: held until the page reports itself shown.
	set_opt( 'browser', 'yes' );
	$_COOKIE = [ \W99Phwc\Identity::cookie_name( \W99Phwc\Settings::cookie_key() ) => $cookie_json ];
	add_filter( 'woocommerce_is_checkout', '__return_true' );
	$prefetch = function () {
		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
		$sf = new Storefront( new Queue() );
		$sf->checkout_started();
		unset( $_SERVER['HTTP_SEC_PURPOSE'] );
		ob_start();
		$sf->print_shown();
		$html = (string) ob_get_clean();
		return preg_match( '/shown="([a-f0-9]{32})"/', $html, $m ) ? $m[1] : '';
	};
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	$before_c = count_of( 'Checkout Started' );
	$token    = $prefetch();
	run_pending( 5, 60 );
	$timeout = (int) get_option( '_transient_timeout_' . \W99Phwc\Storefront::SHOWN_PREFIX . $token );
	check( 'loaded in advance: nothing sent while the page is not shown; its token printed for the browser, kept 30 minutes', '' !== $token && $before_c === count_of( 'Checkout Started' ) && abs( $timeout - ( time() + 1800 ) ) <= 5, wp_json_encode( [ $token, count_of( 'Checkout Started' ) - $before_c, $timeout - time() ] ) );
	$released = ( new Storefront( new Queue() ) )->release_shown( $token ); // not $sent: that is the global record of requests
	run_pending( 5, 60 );
	$c = last_event( 'Checkout Started' );
	check( 'loaded in advance: shown, its Checkout Started is sent once, marked speculative_load', 1 === $released && $before_c + 1 === count_of( 'Checkout Started' ) && true === ( $c['properties']['speculative_load'] ?? null ) && '0197-visitor-abc' === $c['distinct_id'] );
	check( 'loaded in advance: the token works once', 0 === ( new Storefront( new Queue() ) )->release_shown( $token ) && 0 === ( new Storefront( new Queue() ) )->release_shown( 'not-a-token' ) );
	// Pressed or touched but never opened: nothing is ever sent.
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	$before_c = count_of( 'Checkout Started' );
	$never    = $prefetch();
	run_pending( 5, 60 );
	check( 'loaded in advance: a page never shown sends nothing', '' !== $never && $before_c === count_of( 'Checkout Started' ) );
	// It was not counted either: the visitor opening that checkout later (an ordinary request) is counted, and
	// a late report from the old copy is then dropped.
	( new Storefront( new Queue() ) )->checkout_started();
	run_pending( 5, 60 );
	$late = ( new Storefront( new Queue() ) )->release_shown( $never );
	run_pending( 5, 60 );
	check( 'loaded in advance: the checkout opened later is counted once', $before_c + 1 === count_of( 'Checkout Started' ) && 0 === $late, wp_json_encode( [ count_of( 'Checkout Started' ) - $before_c, $late ] ) );
	// The token also reaches the module's settings when the events were held before the page's scripts.
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
	global $wp_scripts;
	$wp_scripts = new WP_Scripts();
	$sf         = new Storefront( new Queue() );
	$sf->checkout_started();
	$sf->enqueue();
	unset( $_SERVER['HTTP_SEC_PURPOSE'] );
	$cfg = (string) $wp_scripts->get_data( 'w99-phwc', 'before' )[1] ?? '';
	$cfg = json_decode( substr( $cfg, strpos( $cfg, '{' ), strrpos( $cfg, '}' ) - strpos( $cfg, '{' ) + 1 ), true );
	check( 'loaded in advance: the module is told where to report and which token', preg_match( '/^[a-f0-9]{32}$/', (string) ( $cfg['shown'] ?? '' ) ) && false !== strpos( (string) ( $cfg['shownUrl'] ?? '' ), 'wc-ajax=w99_phwc_shown' ) && defined( 'DONOTCACHEPAGE' ) );
	( new Storefront( new Queue() ) )->release_shown( (string) ( $cfg['shown'] ?? '' ) );
	run_pending( 5, 60 );
	// Without 99Trace's browser module nothing could report the page shown: sent at once, marked (0.5).
	set_opt( 'browser', 'no' );
	$_COOKIE = [ $cookie_name => $cookie_json ];
	WC()->cart->add_to_cart( $p->get_id(), 1 );
	$before_c = count_of( 'Checkout Started' );
	$none     = $prefetch();
	run_pending( 5, 60 );
	check( 'loaded in advance: without the browser module sent at once, marked speculative_load', '' === $none && $before_c + 1 === count_of( 'Checkout Started' ) && true === ( last_event( 'Checkout Started' )['properties']['speculative_load'] ?? null ) );
	remove_filter( 'woocommerce_is_checkout', '__return_true' );
} catch ( \Throwable $e ) {
	check( 'who: ran without error', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}
wp_set_current_user( 0 );
unset( $_SERVER['HTTP_SEC_PURPOSE'], $_GET['preview'], $_GET['elementor-preview'] );
foreach ( [ 'record_staff', 'record_editors', 'record_previews', 'cart_checkout_fill' ] as $k ) {
	delete_option( 'w99_phwc_' . $k );
}
set_opt( 'browser', 'no' );
set_opt( 'consent', 'auto' );
set_opt( 'unconsented_orders', 'skip' );

// Coverage report (1.0.0): cash on delivery moves an order to Processing without a payment date. 99Trace sends the
// purchase, and the report must count it as well.
try {
	set_opt( 'unconsented_orders', 'anonymous' );
	$cod = checkout( $p );
	$cod->set_payment_method( 'cod' );
	$cod->save();
	$gateways = WC()->payment_gateways()->payment_gateways();
	if ( isset( $gateways['cod'] ) ) {
		$gateways['cod']->process_payment( $cod->get_id() ); // WooCommerce's own cash-on-delivery path.
	} else {
		$cod->update_status( 'processing' );
	}
	run_pending( 5, 3600 );
	$cod = wc_get_order( $cod->get_id() );
	ob_start();
	( new \W99Phwc\Admin() )->coverage();
	$report = (string) ob_get_clean();
	check( 'coverage report: counts a cash-on-delivery order, which is sent although it has no payment date', 'sent' === ( state( $cod )['status'] ?? '' ) && false !== strpos( $report, '<td>cod</td>' ), 'date_paid ' . ( $cod->get_date_paid() ? 'set' : 'empty' ) );
	set_opt( 'unconsented_orders', 'skip' );
} catch ( \Throwable $e ) {
	check( 'coverage report: ran without error', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

// 1.1.0: every public WooCommerce product property in products[] and product events; custom fields only when chosen,
// and never in data printed into pages.
try {
	set_opt( 'unconsented_orders', 'anonymous' );
	// A global attribute "Bundle" with its terms, as shops sell bundles.
	$attr_id = wc_attribute_taxonomy_id_by_name( 'bundle' );
	if ( ! $attr_id ) {
		$attr_id = wc_create_attribute( [ 'name' => 'Bundle', 'slug' => 'bundle', 'type' => 'select' ] );
	}
	if ( ! taxonomy_exists( 'pa_bundle' ) ) {
		register_taxonomy( 'pa_bundle', 'product', [ 'hierarchical' => false ] );
	}
	$t1 = wp_insert_term( '1 x Product', 'pa_bundle', [ 'slug' => '1-x-product' ] );
	$t2 = wp_insert_term( '2 x Product', 'pa_bundle', [ 'slug' => '2-x-product' ] );
	$cat_a = wp_insert_term( 'Gadgets', 'product_cat' );
	$cat_b = wp_insert_term( 'Car accessories', 'product_cat' );
	$tag   = wp_insert_term( 'Bestseller', 'product_tag' );
	$ship  = wp_insert_term( 'Small parcel', 'product_shipping_class', [ 'slug' => 'small-parcel' ] );

	$bundle = new WC_Product_Attribute();
	$bundle->set_id( (int) $attr_id );
	$bundle->set_name( 'pa_bundle' );
	$bundle->set_options( [ (int) $t1['term_id'], (int) $t2['term_id'] ] );
	$bundle->set_visible( true );
	$bundle->set_variation( true );
	$material = new WC_Product_Attribute();
	$material->set_name( 'Material' );
	$material->set_options( [ 'Steel' ] );
	$material->set_visible( true );
	$material->set_variation( false );
	$hidden = new WC_Product_Attribute();
	$hidden->set_name( 'Internal code' );
	$hidden->set_options( [ 'X-99' ] );
	$hidden->set_visible( false );
	$hidden->set_variation( false );

	$vp = new WC_Product_Variable();
	$vp->set_name( 'Bundle product' );
	$vp->set_sku( 'BP' );
	$vp->set_description( str_repeat( 'Long description. ', 200 ) );
	$vp->set_attributes( [ $bundle, $material, $hidden ] );
	$vp->set_category_ids( [ (int) $cat_a['term_id'], (int) $cat_b['term_id'] ] );
	$vp->set_tag_ids( [ (int) $tag['term_id'] ] );
	$vp->set_shipping_class_id( (int) $ship['term_id'] );
	$vp->set_weight( '0.25' );
	$vp->update_meta_data( 'material_note', 'brushed steel' );
	$vp->update_meta_data( '_purchase_cost', '12.50' );
	$vp->save();
	wp_set_object_terms( $vp->get_id(), [ (int) $t1['term_id'], (int) $t2['term_id'] ], 'pa_bundle' );
	$var = new WC_Product_Variation();
	$var->set_parent_id( $vp->get_id() );
	$var->set_attributes( [ 'pa_bundle' => '2-x-product' ] );
	$var->set_regular_price( '59.94' );
	$var->set_sale_price( '49.95' );
	$var->set_sku( 'BP-2' );
	$var->set_manage_stock( true );
	$var->set_stock_quantity( 40 );
	$var->update_meta_data( '_variation_cost', '20.00' );
	$var->save();
	$var = wc_get_product( $var->get_id() );

	$before_o = count_of( 'Order Completed' );
	$vo       = checkout( $var );
	$vo->payment_complete( 'tr_pp1' );
	run_pending( 5, 3600 );
	$ev   = last_event( 'Order Completed' );
	$line = $ev['properties']['products'][0] ?? [];
	check( 'product properties: purchase sent with the variation line', $before_o + 1 === count_of( 'Order Completed' ) && (string) $vo->get_id() === ( $ev['properties']['order_id'] ?? '' ) && (string) $var->get_id() === ( $line['variant'] ?? '' ) );
	check(
		'product properties: fields sent before 1.1.0 keep their names and values',
		(string) $vp->get_id() === ( $line['product_id'] ?? '' ) && 'BP-2' === ( $line['sku'] ?? '' ) && '' !== ( $line['category'] ?? '' ) && ( $line['categories'][0] ?? null ) === $line['category'] && 1 === ( $line['quantity'] ?? 0 ) && 1 === ( $line['position'] ?? 0 )
			&& 49.95 === (float) ( $line['price'] ?? 0 ) && get_permalink( $vp->get_id() ) === ( $line['url'] ?? '' ) && '2 x Product' === ( $line['variant_name'] ?? '' ),
		wp_json_encode( array_intersect_key( $line, array_flip( [ 'product_id', 'sku', 'category', 'quantity', 'position', 'price', 'url', 'variant_name' ] ) ) )
	);
	$attrs = $line['attributes'] ?? [];
	check(
		'product properties: attributes by name (the variation\'s bundle, the visible custom attribute; never the hidden one)',
		'2 x Product' === ( $attrs['pa_bundle'] ?? '' ) && 'Steel' === ( $attrs['material'] ?? '' ) && ! isset( $attrs['internal-code'] ),
		wp_json_encode( $attrs )
	);
	check( 'product properties: the variation\'s own address carries its attributes, as the previous plugin\'s url did', false !== strpos( (string) ( $line['variant_url'] ?? '' ), 'attribute_pa_bundle=2-x-product' ), (string) ( $line['variant_url'] ?? '' ) );
	check(
		'product properties: WooCommerce data, names for terms, variation inheriting the parent',
		'variation' === ( $line['product_type'] ?? '' ) && 'Bundle product' === ( $line['parent_name'] ?? '' ) && 'BP' === ( $line['parent_sku'] ?? '' )
			&& [ 'Car accessories', 'Gadgets' ] === array_values( array_intersect( [ 'Car accessories', 'Gadgets' ], (array) ( $line['categories'] ?? [] ) ) ) && [ 'Bestseller' ] === ( $line['tags'] ?? [] )
			&& 'Small parcel' === ( $line['shipping_class'] ?? '' ) && 59.94 === ( $line['regular_price'] ?? null ) && 49.95 === ( $line['sale_price'] ?? null ) && true === ( $line['on_sale'] ?? null )
			&& 0.25 === ( $line['weight'] ?? null ) && is_int( $line['stock_quantity'] ?? null ) && $line['stock_quantity'] >= 38 && $line['stock_quantity'] <= 40 && 'instock' === ( $line['stock_status'] ?? '' ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) ( $line['date_created'] ?? '' ) ),
		wp_json_encode( array_intersect_key( $line, array_flip( [ 'product_type', 'parent_name', 'parent_sku', 'categories', 'tags', 'shipping_class', 'regular_price', 'sale_price', 'on_sale', 'weight', 'stock_quantity', 'stock_status', 'date_created' ] ) ) )
	);
	check(
		'product properties: no descriptions, downloads, password, raw meta or custom fields by default',
		! array_intersect_key( $line, array_flip( [ 'description', 'short_description', 'purchase_note', 'downloads', 'post_password', 'meta_data', 'custom_fields', 'image_id', 'category_ids' ] ) ),
		implode( ',', array_keys( $line ) )
	);
	check( 'product properties: WooCommerce\'s cost of goods (cogs_*) is internal, not sent by default', ! preg_grep( '/^cogs_/', array_keys( $line ) ), implode( ',', preg_grep( '/^cogs_/', array_keys( $line ) ) ) );
	$size = strlen( (string) wp_json_encode( $line ) );
	check( 'product properties: a product line stays small (under 4 KB)', $size < 4096, $size . ' bytes' );

	set_opt( 'product_meta', 'visible' );
	$vo2 = checkout( $var );
	$vo2->payment_complete( 'tr_pp2' );
	run_pending( 5, 3600 );
	$cf = last_event( 'Order Completed' )['properties']['products'][0]['custom_fields'] ?? [];
	check( 'custom fields "visible": the visible field, no hidden ones', 'brushed steel' === ( $cf['material_note'] ?? '' ) && ! isset( $cf['_purchase_cost'] ) && ! isset( $cf['_variation_cost'] ), wp_json_encode( $cf ) );

	set_opt( 'product_meta', 'all' );
	$vo3 = checkout( $var );
	$vo3->payment_complete( 'tr_pp3' );
	run_pending( 5, 3600 );
	$cf = last_event( 'Order Completed' )['properties']['products'][0]['custom_fields'] ?? [];
	check( 'custom fields "all": hidden fields of the variation and its parent too', '12.50' === ( $cf['_purchase_cost'] ?? '' ) && '20.00' === ( $cf['_variation_cost'] ?? '' ) && 'brushed steel' === ( $cf['material_note'] ?? '' ), wp_json_encode( $cf ) );
	$all_line = last_event( 'Order Completed' )['properties']['products'][0] ?? [];
	check( 'custom fields "all": WooCommerce\'s cost of goods fields come with it', ! method_exists( $var, 'get_cogs_value_is_additive' ) || array_key_exists( 'cogs_value_is_additive', $all_line ), implode( ',', preg_grep( '/^cogs_/', array_keys( $all_line ) ) ) );

	$page = \W99Phwc\OrderData::product_fields( $var, $vp->get_id(), $var->get_id(), \W99Phwc\OrderData::FOR_PAGE );
	check( 'page data (printed for every visitor): attributes yes; custom fields, sales count and stock numbers never, even with "all"', '2 x Product' === ( $page['attributes']['pa_bundle'] ?? '' ) && ! array_intersect_key( $page, array_flip( [ 'custom_fields', 'total_sales', 'stock_quantity', 'low_stock_amount' ] ) ) && ! preg_grep( '/^cogs_/', array_keys( $page ) ), implode( ',', array_keys( $page ) ) );

	// Product Added for the variation: the same properties at the top level, without changing the event's own fields.
	$_COOKIE  = [ $cookie_name => $cookie_json ];
	$before_a = count_of( 'Product Added' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $vp->get_id(), 2, $var->get_id(), [ 'attribute_pa_bundle' => '2-x-product' ] );
	run_pending( 1, 60 );
	$a = last_event( 'Product Added' );
	$ap = $a['properties'] ?? [];
	check(
		'Product Added: product properties added, its own fields unchanged (quantity, price, value, source)',
		$before_a + 1 === count_of( 'Product Added' ) && 2 === ( $ap['quantity'] ?? 0 ) && 49.95 === (float) ( $ap['price'] ?? 0 ) && 99.9 === (float) ( $ap['value'] ?? 0 )
			&& '2 x Product' === ( $ap['attributes']['pa_bundle'] ?? '' ) && 'variation' === ( $ap['product_type'] ?? '' ) && isset( $ap['source'] ) && isset( $ap['custom_fields']['_purchase_cost'] ),
		wp_json_encode( array_intersect_key( $ap, array_flip( [ 'quantity', 'price', 'value', 'source', 'product_type' ] ) ) )
	);
	WC()->cart->empty_cart();
	$_COOKIE = [];
	delete_option( 'w99_phwc_product_meta' );
	\W99Phwc\Settings::flush();
	check( 'custom fields: off by default', 'none' === \W99Phwc\Settings::get( 'product_meta' ) && [] === \W99Phwc\OrderData::custom_fields( $var, $vp ) );
	set_opt( 'unconsented_orders', 'skip' );
} catch ( \Throwable $e ) {
	check( 'product properties: ran without error', false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

global $wpdb;
check( 'orders stored in HPOS tables with frozen identity', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_99w_ph_identity'" ) >= 10 );

$out  = implode( "\n", $results ) . "\n" . ( $fail ? "$fail FAILED\n" : "ALL PASSED\n" );
$out .= 'Sample Order Completed: ' . wp_json_encode( sent_events( 'Order Completed' )[0] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
$out .= 'Sample Checkout Started: ' . wp_json_encode( sent_events( 'Checkout Started' )[0] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
file_put_contents( '/tests/results.txt', $out );
