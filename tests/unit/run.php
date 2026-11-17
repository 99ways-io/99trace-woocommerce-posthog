<?php
/**
 * Unit tests for the pure parts of 99Trace for WooCommerce (no WordPress needed).
 * Run: php tests/unit/run.php   (PHP 8.0+)
 */
define( 'W99_TRACE_TESTS', true );
define( 'W99_TRACE_VERSION', 'test' );
$src = dirname( __DIR__, 2 ) . '/99trace-posthog-for-woocommerce/src/';
// The plugin's files stop unless WordPress loaded them (ABSPATH); here they are loaded with that guard lifted.
// Events.php and Orders.php need WordPress for most methods; their pure rules are tested on their own.
foreach ( array( 'Util', 'Uuid', 'Consent', 'Identity', 'Settings', 'Visitor', 'Events', 'Orders' ) as $c ) {
	eval( '?>' . str_replace( "defined( 'ABSPATH' ) || exit;", "defined( 'ABSPATH' ) || defined( 'W99_TRACE_TESTS' ) || exit;", file_get_contents( $src . $c . '.php' ) ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test harness only.
}

use W99\Trace\{Util, Uuid, Consent, Identity, Events, Orders, Visitor};

$pass = 0;
$fail = 0;
function check( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) {
		$pass++;
		return;
	}
	$fail++;
	echo "FAIL $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n";
}

// --- UUIDs: identical to the other 99Trace integrations (shared fixture vectors).
$fx = json_decode( file_get_contents( __DIR__ . '/fixtures/uuid-v5.json' ), true );
foreach ( $fx['vectors'] as $v ) {
	check( 'uuid v5 ' . $v['name'], Uuid::v5( $v['name'] ), $v['uuid'] );
	if ( 'woocommerce' === $v['platform'] ) {
		check( 'uuid event ' . $v['name'], Uuid::event( $v['store'], $v['kind'], $v['ids'] ), $v['uuid'] );
	}
}
check( 'uuid v4 shape', (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Uuid::v4() ), true );

// --- UTC: a shop in Amsterdam time must not shift timestamps (the 0.2.0 bug).
date_default_timezone_set( 'Europe/Amsterdam' );
$paid = new DateTime( '2026-11-12 10:36:00', new DateTimeZone( 'Europe/Amsterdam' ) ); // 09:36 UTC
check( 'utc from DateTime in Amsterdam', Util::utc( $paid ), '2026-11-12T09:36:00Z' );
check( 'utc from unix', Util::utc( 1794489644 ), '2026-11-12T13:20:44Z' );
$summer = new DateTime( '2026-07-01 12:00:00', new DateTimeZone( 'Europe/Amsterdam' ) ); // CEST, 10:00 UTC
check( 'utc in summer time', Util::utc( $summer ), '2026-07-01T10:00:00Z' );
date_default_timezone_set( 'UTC' );

// --- Buyer IP: only public addresses reach GeoIP.
check( 'public ipv4', Util::public_ip( '90.84.0.10' ), '90.84.0.10' );
check( 'private ipv4', Util::public_ip( '172.18.0.1' ), '' );
check( 'loopback', Util::public_ip( '127.0.0.1' ), '' );
check( 'public ipv6', Util::public_ip( '2a01:cb00::1' ), '2a01:cb00::1' );
check( 'garbage ip', Util::public_ip( 'not-an-ip' ), '' );

// --- URLs: order keys and e-mails never reach analytics.
$strip = array( 'key', 'order_key', 'email', '_wpnonce' );
check( 'strip key', Util::strip_url( 'https://s.example/order-confirmed/ty/?wfty_source=1&key=wc_order_abc&order_id=9', $strip ), 'https://s.example/order-confirmed/ty/?wfty_source=1&order_id=9' );
check( 'strip all', Util::strip_url( 'https://s.example/x/?key=wc_order_abc', $strip ), 'https://s.example/x/' );
check( 'strip keeps hash', Util::strip_url( 'https://s.example/x/?email=a%40b.c&utm_source=ig#top', $strip ), 'https://s.example/x/?utm_source=ig#top' );
check( 'strip case-insensitive', Util::strip_url( 'https://s.example/?Key=1&a=2', $strip ), 'https://s.example/?a=2' );
check( 'strip no query', Util::strip_url( 'https://s.example/x/', $strip ), 'https://s.example/x/' );
check( 'strip any parameter holding an order key', Util::strip_url( 'https://s.example/?download_file=5&order=wc_order_Zz1&b=2', $strip ), 'https://s.example/?download_file=5&b=2' );
check( 'strip order key with no list', Util::strip_url( 'https://s.example/?order=wc_order_Zz1', array() ), 'https://s.example/' );

// --- Distinct IDs: placeholders and PostHog's illegal IDs are refused.
check( 'clean id ok', Util::clean_id( '01a1cfe7-d872-727f-923d-bb87f20c59a5' ), '01a1cfe7-d872-727f-923d-bb87f20c59a5' );
check( 'clean id cookieless', Util::clean_id( '$posthog_cookieless' ), null );
check( 'clean id illegal', Util::clean_id( 'undefined' ), null );
check( 'clean id control chars', Util::clean_id( "abc\n" ), 'abc' );
check( 'clean id too long', Util::clean_id( str_repeat( 'a', 201 ) ), null );

// --- User agents: the same names posthog-js reports, plus the in-app browser.
$ig = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 389.0.0.36.83 (iPhone15,3; iOS 18_6; fr_FR; fr; scale=3.00; 1290x2796; 761234567)';
$u  = Util::user_agent( $ig );
check( 'ig browser', $u['$browser'] ?? null, 'Mobile Safari' );
check( 'ig os', ( $u['$os'] ?? '' ) . ' ' . ( $u['$os_version'] ?? '' ), 'iOS 18.6.0' );
check( 'ig device type', $u['$device_type'] ?? null, 'Mobile' );
check( 'ig in-app', $u['in_app_browser'] ?? null, 'Instagram' );
$fb = Util::user_agent( 'Mozilla/5.0 (Linux; Android 15; SM-S921B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.7390.37 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/498.0.0.52.95;]' );
check( 'fb android browser', $fb['$browser'] ?? null, 'Chrome' );
check( 'fb android in-app', $fb['in_app_browser'] ?? null, 'Facebook' );
check( 'fb android os', $fb['$os'] ?? null, 'Android' );
$fbios = Util::user_agent( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.110;FBBV/622000000;FBDV/iPhone14,7;FBMD/iPhone;FBSN/iOS;FBSV/17.5;FBSS/3;FBCR/;FBID/phone;FBLC/fr_FR;FBOP/5]' );
check( 'fb ios browser', $fbios['$browser'] ?? null, 'Facebook Mobile' );
$safari = Util::user_agent( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1' );
check( 'safari ios', array( $safari['$browser'] ?? null, $safari['$browser_version'] ?? null, isset( $safari['in_app_browser'] ) ), array( 'Mobile Safari', 18.6, false ) );
$chrome = Util::user_agent( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' );
check( 'chrome mac', array( $chrome['$browser'] ?? null, $chrome['$os'] ?? null, $chrome['$device_type'] ?? null ), array( 'Chrome', 'Mac OS X', 'Desktop' ) );
$ipad = Util::user_agent( 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' );
check( 'ipad tablet', $ipad['$device_type'] ?? null, 'Tablet' );
check( 'empty ua', Util::user_agent( '' ), array() );

// --- Consent: Cookiebot's cookie as the server receives it.
$granted = rawurlencode( "{stamp:'abc',necessary:true,preferences:false,statistics:true,marketing:false,method:'explicit',ver:1,utc:1731400000000,region:'nl'}" );
$denied  = rawurlencode( "{stamp:'abc',necessary:true,preferences:false,statistics:false,marketing:false,method:'explicit',ver:1}" );
check( 'cookiebot granted', Consent::cookiebot( $granted ), Consent::GRANTED );
check( 'cookiebot denied', Consent::cookiebot( $denied ), Consent::DENIED );
check( 'cookiebot not required', Consent::cookiebot( '-1' ), Consent::NOT_REQUIRED );
check( 'cookiebot absent', Consent::cookiebot( null ), null );
check( 'evaluate cookiebot granted', Consent::evaluate( 'cookiebot', array( 'CookieConsent' => $granted ), 'phc_x', true ), Consent::GRANTED );
check( 'evaluate cookiebot denied beats identity', Consent::evaluate( 'cookiebot', array( 'CookieConsent' => $denied ), 'phc_x', true ), Consent::DENIED );
// Cookiebot is the authority (16 Nov): without its answer on record, a posthog-js cookie implies nothing
// ("Switched off" keeps a returning visitor's cookie while the banner asks again).
check( 'evaluate cookiebot, no record, identity present: not answered', Consent::evaluate( 'cookiebot', array(), 'phc_x', true ), Consent::UNKNOWN );
check( 'evaluate cookiebot, no record, posthog opt-in mark: not answered', Consent::evaluate( 'cookiebot', array( '__ph_opt_in_out_phc_x' => '1' ), 'phc_x', true ), Consent::UNKNOWN );
check( 'evaluate site\'s own opt-in, no record, identity present: still implied', Consent::evaluate( 'posthog_opt_in', array(), 'phc_x', true ), Consent::IMPLIED );
check( 'details need an answer on record or none needed', array( Consent::allows_details( Consent::GRANTED ), Consent::allows_details( Consent::NOT_REQUIRED ), Consent::allows_details( Consent::IMPLIED ), Consent::allows_details( Consent::UNKNOWN ) ), array( true, true, false, false ) );
check( 'evaluate no record, no identity', Consent::evaluate( 'cookiebot', array(), 'phc_x', false ), Consent::UNKNOWN );
// The consent platform's answer wins over posthog-js's own record: consent given again after a withdrawal counts.
check( 'evaluate platform granted beats posthog opt-out', Consent::evaluate( 'auto', array( '__ph_opt_in_out_phc_x' => '0', 'CookieConsent' => $granted ), 'phc_x', true ), Consent::GRANTED );
check( 'evaluate cookiebot mode, platform granted beats opt-out', Consent::evaluate( 'cookiebot', array( '__ph_opt_in_out_phc_x' => '0', 'CookieConsent' => $granted ), 'phc_x', true ), Consent::GRANTED );
check( 'evaluate posthog opt-out without platform answer', Consent::evaluate( 'cookiebot', array( '__ph_opt_in_out_phc_x' => '0' ), 'phc_x', true ), Consent::DENIED );
check( 'evaluate posthog opt-in without platform answer', Consent::evaluate( 'posthog_opt_in', array( '__ph_opt_in_out_phc_x' => '1', 'CookieConsent' => $denied ), 'phc_x', false ), Consent::GRANTED );
check( 'evaluate wp consent api beats posthog opt-out', Consent::evaluate( 'wp_consent_api', array( '__ph_opt_in_out_phc_x' => '0' ), 'phc_x', true, static fn( $c ) => true ), Consent::GRANTED );
check( 'evaluate none', Consent::evaluate( 'none', array(), 'phc_x', false ), Consent::NOT_REQUIRED );
check( 'evaluate wp consent api, refusal stored', Consent::evaluate( 'wp_consent_api', array( 'wp_consent_statistics' => 'deny' ), 'phc_x', true, static fn( $c ) => false ), Consent::DENIED );
// In opt-in regions wp_has_consent() is false until the visitor answers: not a refusal (0.5.0; the browser module already read it so).
check( 'evaluate wp consent api, not answered yet, no identity', Consent::evaluate( 'wp_consent_api', array(), 'phc_x', false, static fn( $c ) => false ), Consent::UNKNOWN );
// The API is the authority: an answer that expired (it keeps one 30 days) is no consent, whatever identity cookies remain.
check( 'evaluate wp consent api, not answered (or expired), posthog-js identity: still unknown', Consent::evaluate( 'wp_consent_api', array(), 'phc_x', true, static fn( $c ) => false ), Consent::UNKNOWN );
check( 'a new visitor ID only on consent given or not needed, never on an inferred one', array( Consent::allows_new_id( Consent::GRANTED ), Consent::allows_new_id( Consent::NOT_REQUIRED ), Consent::allows_new_id( Consent::IMPLIED ), Consent::allows_new_id( Consent::UNKNOWN ), Consent::allows_new_id( Consent::DENIED ) ), array( true, true, false, false, false ) );

// --- posthog-js cookie, as posthog-js 1.447.15 writes it (captured on the staging copy; host and path generic).
$now    = 1794494800;
$cookie = '{"$device_id":"01a1d039-5510-7bf3-992e-90516561c753","distinct_id":"01a1d039-5510-7bf3-992e-90516561c753","$sesid":[1794494780694,"01a1d039-5518-747a-b453-919614c51456",1794494780694],"$initial_person_info":{"r":"$direct","u":"http://shop.example/lander/?utm_source=facebook&fbclid=IwZXh0bgNhZW0BMABhZGlkAasT3stCl1cK1D"},"$fbc_persistence":{"value":"fb.1.1794494780698.IwZXh0bgNhZW0BMABhZGlkAasT3stCl1cK1D","delivered":false},"$user_state":"anonymous"}';
$c      = Identity::from_cookie( rawurlencode( $cookie ), $now );
check( 'cookie distinct', $c['distinct_id'], '01a1d039-5510-7bf3-992e-90516561c753' );
check( 'cookie device', $c['device_id'], '01a1d039-5510-7bf3-992e-90516561c753' );
check( 'cookie session fresh', $c['session_id'], '01a1d039-5518-747a-b453-919614c51456' );
check( 'cookie anonymous', $c['user_state'], 'anonymous' );
check( 'cookie person processing unknown', $c['person_processing'], null );
check( 'cookie Meta click ID {value, delivered}', $c['props']['$fbc'] ?? null, 'fb.1.1794494780698.IwZXh0bgNhZW0BMABhZGlkAasT3stCl1cK1D' );
check( 'cookie Meta click ID, older plain string', Identity::from_cookie( json_encode( array( 'distinct_id' => 'A1', '$fbc_persistence' => 'fb.1.1.x' ) ), $now )['props']['$fbc'] ?? null, 'fb.1.1.x' );
check( 'cookie person processing on', Identity::from_cookie( json_encode( array( 'distinct_id' => 'A1', '$epp' => true ) ), $now )['person_processing'], true );
check( 'cookie no session entry props', array_keys( $c['props'] ), array( '$fbc' ) );
$stale = Identity::from_cookie( json_encode( array( 'distinct_id' => 'A1', '$sesid' => array( ( $now - 4000 ) * 1000, 'old', 0 ) ) ), $now );
check( 'cookie session stale', $stale['session_id'], null );
check( 'cookie url-encoded', Identity::from_cookie( rawurlencode( json_encode( array( 'distinct_id' => 'A2' ) ) ), $now )['distinct_id'], 'A2' );
check( 'cookie garbage', Identity::from_cookie( '{not json', $now ), null );
check( 'cookie name', Identity::cookie_name( 'phc_abc' ), 'ph_phc_abc_posthog' );

// --- Browser envelope.
$env = array( 'v' => 1, 'token' => 'phc_x', 'distinct_id' => 'B1', 'device_id' => 'B1', 'session_id' => 'S1', 'user_state' => 'anonymous', 'person_processing' => false, 'capturing' => true, 'consent_status' => 'pending', 'flags' => array( 'exp' => 'test', 'off' => false, 'bad' => array() ), 'props' => array( '$browser' => 'Chrome', '$current_url' => 'https://s/x', 'email' => 'never@copied', '$active_feature_flags' => array( 'exp' ) ), 'captured_at' => '2026-11-12T13:20:16.000Z' );
$b   = Identity::from_browser( json_encode( $env ), 'phc_x' );
check( 'envelope distinct', $b['distinct_id'], 'B1' );
check( 'envelope flags filtered', $b['flags'], array( 'exp' => 'test', 'off' => false ) );
check( 'envelope props allow-listed', array_keys( $b['props'] ), array( '$browser', '$current_url', '$active_feature_flags' ) );
check( 'envelope other project', Identity::from_browser( json_encode( $env ), 'phc_other' ), null );
check( 'envelope wrong version', Identity::from_browser( json_encode( array( 'v' => 2 ) + $env ), 'phc_x' ), null );

// --- Resolution.
$fallback = '99hog:order:x';
$r = Identity::resolve( $b, $c, null, Consent::GRANTED, $fallback );
check( 'resolve browser first', array( $r['source'], $r['distinct_id'], $r['linked'] ), array( 'browser', 'B1', true ) );
check( 'resolve personless anonymous', $r['process_person'], false );
$r = Identity::resolve( null, $c, null, Consent::IMPLIED, $fallback );
check( 'resolve cookie fallback', array( $r['source'], $r['distinct_id'] ), array( 'cookie', '01a1d039-5510-7bf3-992e-90516561c753' ) );
$r = Identity::resolve( $b, $c, null, Consent::DENIED, $fallback );
check( 'resolve consent denied', array( $r['linked'], $r['reason'], $r['distinct_id'] ), array( false, 'consent_denied', $fallback ) );
$r = Identity::resolve( array( 'consent_status' => 'denied' ) + $b, null, null, Consent::GRANTED, $fallback );
check( 'resolve posthog opted out', $r['reason'], 'consent_denied' );
$r = Identity::resolve( array( 'capturing' => false ) + $b, null, null, Consent::GRANTED, $fallback );
check( 'resolve capture disabled', $r['reason'], 'capture_disabled' );
$r = Identity::resolve( null, null, 'wc:shop:7', Consent::NOT_REQUIRED, $fallback );
check( 'resolve customer account', array( $r['source'], $r['distinct_id'], $r['process_person'] ), array( 'customer_account', 'wc:shop:7', true ) );
$r = Identity::resolve( null, null, 'wc:shop:7', Consent::GRANTED, $fallback );
check( 'resolve no identity', array( $r['linked'], $r['reason'] ), array( false, 'no_identity' ) );
$r = Identity::resolve( array( 'user_state' => 'identified' ) + $b, null, null, Consent::GRANTED, $fallback );
check( 'resolve identified processes person', $r['process_person'], true );

// --- Crawlers and scripts get no visitor ID from the server (0.5.0).
foreach ( array(
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 389.0.0.36.83 (iPhone15,3; iOS 18_6; fr_FR; fr; scale=3.00; 1290x2796; 761234567)',
	'Mozilla/5.0 (Linux; Android 15; SM-S921B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.7390.37 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/498.0.0.52.95;]',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
	'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
	'Mozilla/5.0 (Linux; Android 14; 23021RAAEG Build/UKQ1.230917.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0.0.0 Mobile Safari/537.36 trill_340302 BytedanceWebview/d8a21c6',
) as $human ) {
	check( 'not a bot: ' . substr( $human, 0, 60 ), Util::is_bot( $human ), false );
}
foreach ( array(
	'', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
	'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'WhatsApp/2.23.20.0 A', 'curl/8.7.1', 'python-requests/2.32.3',
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/141.0.0.0 Safari/537.36', 'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
	'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
) as $bot ) {
	check( 'bot: ' . substr( $bot, 0, 60 ), Util::is_bot( $bot ), true );
}

// --- One visitor ID for browser and server: the shop's cookie (0.5.0).
$v7 = Uuid::v7( 1794494800123 );
check( 'uuid v7 shape (posthog-js\'s form for anonymous IDs)', (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v7 ), true );
check( 'uuid v7 carries its time', hexdec( substr( str_replace( '-', '', $v7 ), 0, 12 ) ), 1794494800123 );
check( 'uuid v7 random part differs', Uuid::v7( 1794494800123 ) === $v7, false );
$vid = '01a1e202-c74d-7c67-b5e8-665a97760e8b';
check( 'visitor cookie: plain ID (written by the browser)', Visitor::parse( $vid ), array( 'id' => $vid, 'minted' => false ) );
check( 'visitor cookie: ID the server created', Visitor::parse( $vid . '.s' ), array( 'id' => $vid, 'minted' => true ) );
check( 'visitor cookie: upper case read as the same ID', Visitor::parse( strtoupper( $vid ) )['id'] ?? null, $vid );
foreach ( array( '', 'x', $vid . '.x', '99hog:order:abc', '<script>', $vid . $vid, '01a1e202c74d7c67b5e8665a97760e8b' ) as $bad ) {
	check( 'visitor cookie: rejected ' . var_export( $bad, true ), Visitor::parse( $bad ), null );
}
$shop = array( 'distinct_id' => $vid, 'device_id' => $vid, 'user_state' => 'anonymous', 'person_processing' => true, 'captured_at' => '2026-11-16T10:00:00Z' );
$r    = Identity::resolve( null, null, null, Consent::NOT_REQUIRED, $fallback, $shop );
check( 'resolve shop ID, no consent needed (posthog-js blocked)', array( $r['linked'], $r['source'], $r['distinct_id'], $r['device_id'], $r['process_person'] ), array( true, 'shop_cookie', $vid, $vid, true ) );
$r = Identity::resolve( null, null, null, Consent::GRANTED, $fallback, array( 'person_processing' => false ) + $shop );
check( 'resolve shop ID, consent given; personless under "only logged-in customers"', array( $r['linked'], $r['source'], $r['process_person'] ), array( true, 'shop_cookie', false ) );
$r = Identity::resolve( null, null, null, Consent::UNKNOWN, $fallback, $shop );
check( 'resolve shop ID, no answer yet: unlinked', array( $r['linked'], $r['reason'], $r['distinct_id'] ), array( false, 'no_identity_no_consent_record', $fallback ) );
$r = Identity::resolve( null, null, null, Consent::DENIED, $fallback, $shop );
check( 'resolve shop ID, refused: unlinked', array( $r['linked'], $r['reason'] ), array( false, 'consent_denied' ) );
$r = Identity::resolve( null, $c, null, Consent::IMPLIED, $fallback, $shop );
check( 'resolve: posthog-js\'s cookie wins over the shop ID', $r['source'], 'cookie' );
$r = Identity::resolve( $b, null, null, Consent::GRANTED, $fallback, $shop );
check( 'resolve: the checkout envelope wins over the shop ID', $r['source'], 'browser' );
$r = Identity::resolve( null, null, 'wc:shop:7', Consent::NOT_REQUIRED, $fallback, $shop );
check( 'resolve: the account wins over the shop ID', $r['source'], 'customer_account' );
check( 'session start: a session that began after the event (order linked after consent) is not the event\'s', Events::session_still_current( '01a1e202-c74d-7c67-b5e8-665a97760e8b', null, gmdate( 'Y-m-d\TH:i:s\Z', (int) floor( hexdec( '01a1e202c74d' ) / 1000 ) - 120 ) ), false );
check( 'session start: an event a few seconds into the session takes it', Events::session_still_current( '01a1e202-c74d-7c67-b5e8-665a97760e8b', null, gmdate( 'Y-m-d\TH:i:s\Z', (int) floor( hexdec( '01a1e202c74d' ) / 1000 ) + 5 ) ), true );

// --- A later capture never weakens the identity frozen at checkout.
$linked_a  = array( 'linked' => true, 'distinct_id' => 'A', 'user_state' => 'anonymous' );
$linked_b  = array( 'linked' => true, 'distinct_id' => 'B', 'user_state' => 'anonymous' );
$account   = array( 'linked' => true, 'distinct_id' => 'wc:shop:7', 'user_state' => 'identified' );
$unlinked  = array( 'linked' => false, 'distinct_id' => '99hog:order:x' );
check( 'replace: unlinked by linked', Orders::may_replace( $unlinked, $linked_a ), true );
check( 'replace: same visitor refreshed', Orders::may_replace( $linked_a, $linked_a ), true );
check( 'replace: never linked by unlinked (retry without consent)', Orders::may_replace( $linked_a, $unlinked ), false );
check( 'replace: never by another browser', Orders::may_replace( $linked_a, $linked_b ), false );
check( 'replace: sign-in during checkout', Orders::may_replace( $linked_a, $account ), true );
check( 'replace: never an account by an anonymous browser', Orders::may_replace( $account, $linked_a ), false );

// --- Session attachment window.
$sid = '01a1cfe7-d877-74fd-9ec8-fa0af8dfb88d'; // UUIDv7 started 2026-11-12 13:17
check( 'session current', Events::session_still_current( $sid, '2026-11-12T13:20:16Z', '2026-11-12T13:20:44Z' ), true );
check( 'session too late after checkout', Events::session_still_current( $sid, '2026-11-12T13:20:16Z', '2026-11-12T15:20:44Z' ), false );
check( 'session older than 24h', Events::session_still_current( $sid, null, '2026-11-13T14:00:00Z' ), false );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
