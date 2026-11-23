/*!
 * 99Trace for WooCommerce: browser module.
 *
 * 1. Waits for analytics (statistics) consent, then loads posthog-js. Before consent nothing is
 *    loaded, stored or sent. Declining or withdrawing consent stops capture and removes PostHog's
 *    storage; consent given again later resumes capture. With the consent switch on, posthog-js
 *    starts at page load switched off (no events, no storage) so experiments get their variant at
 *    once, and is switched on when the visitor consents.
 * 2. Identifies signed-in customers with an opaque account ID, read from a cookie the shop sets at
 *    login (never from the page, which a cache may share); resets after logout.
 * 3. On a thank-you page opened with the order key in another browser, joins this browser to the
 *    identity the order was placed with: a browser without PostHog history continues that identity
 *    (bootstrap, no merge); an anonymous browser with its own history asks PostHog to merge (alias).
 * 4. Sends page events (Product Viewed, View Category, Cart Viewed, Order Confirmation Viewed).
 * 5. Copies the visitor's identity and a snapshot of posthog-js's event properties into the
 *    checkout, so the server's purchase event carries the same identity and context.
 * 5b. Shares one visitor ID with the shop's server, which sends cart, checkout and order events:
 *    posthog-js without an ID of its own starts with the one in the shop's cookie (created by the
 *    server for this browser, or earlier), else with a new one written there; the cookie then
 *    follows posthog-js's ID. An ID the server created while posthog-js already had another is
 *    merged into it once (alias). On a thank-you page, an order placed before the visitor could be
 *    recorded is linked once they accept (the shop checks the order key).
 * 6. Keeps order keys, e-mail addresses and the configured URL parameters out of every event, and
 *    records no element text or attributes, replay or heatmaps on order and account pages.
 * posthog-js is the only writer of PostHog's identity; this module calls its public API only.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.w99phwc;
	if ( ! cfg || ! cfg.token || w.__w99phwcStarted ) {
		return;
	}
	w.__w99phwcStarted = true;

	var FIELD = '_w99_ph_identity';
	var STORE_API_NS = 'w99-phwc';
	// Keep in sync with Identity::SNAPSHOT_KEYS (PHP).
	var SNAPSHOT_KEYS = [
		'$os', '$os_version', '$browser', '$browser_version', '$device', '$device_type', '$device_model',
		'$raw_user_agent', '$screen_height', '$screen_width', '$viewport_height', '$viewport_width',
		'$browser_language', '$browser_language_prefix', '$timezone', '$timezone_offset',
		'$current_url', '$host', '$pathname', '$referrer', '$referring_domain', '$search_engine',
		'$webview_app', '$webview_app_version', '$window_id', '$pageview_id',
		'$session_entry_url', '$session_entry_host', '$session_entry_pathname', '$session_entry_referrer', '$session_entry_referring_domain',
		'$session_entry_utm_source', '$session_entry_utm_medium', '$session_entry_utm_campaign', '$session_entry_utm_content', '$session_entry_utm_term',
		'$session_entry_gclid', '$session_entry_gad_source', '$session_entry_gbraid', '$session_entry_wbraid', '$session_entry_fbclid', '$session_entry_ttclid', '$session_entry_msclkid', '$session_entry__kx', '$session_entry_search_engine',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gad_source', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid', 'igshid',
		'$active_feature_flags', '$fbc', '$fbp', '$config_defaults'
	];
	var EMAIL = /[A-Za-z0-9._%+-]+(?:@|%40)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/g;
	var ORDER_KEY = /wc_order_[A-Za-z0-9]+/g;

	var started = false;
	var claimed = false; // thank-you page: the link request for an order placed before consent was sent
	var queuedOptIn = false; // opt_in_capturing queued on the loading stub
	var instance = null; // posthog-js from its loaded callback: window.posthog is still the stub during its first capture
	var linkMethod = null;
	var paramRe = null;

	// "Record the visit" (before_consent 'hold'): until the visitor answers, posthog-js runs on page memory
	// and every event goes to this list instead of PostHog; on consent the list is sent, on refusal dropped.
	var HOLD_KEY = 'ph_wc_held';
	var HOLD_ID_KEY = 'ph_wc_held_id'; // with the recording kept across pages: one visitor ID for all of them
	var HOLD_MAX = 200;
	// Identity of the page that recorded an event: replaced by the visitor's identity when the list is sent.
	var HELD_IDENTITY_KEYS = [ 'distinct_id', '$device_id', '$session_id', '$window_id', '$anon_distinct_id', '$is_identified',
		'$process_person_profile', '$user_id', 'token', '$time', '$insert_id', '$lib', '$lib_version', '$user_state' ];
	var memoryOnly = false; // posthog-js started on page memory (hold, or switched off with a stored visitor): normal storage on consent
	var keptDevice = null; // the stored visitor's device ID, put back in page memory (bootstrap sets only the ID)
	var holding = false; // recording into `held` until the visitor answers
	var refused = false; // the visitor refused while posthog-js runs (switch or hold): nothing is sent
	var held = [];
	var deferred = null; // identity work (account, thank-you page) that waits for consent while holding

	function log() {
		if ( cfg.debug && w.console ) {
			w.console.info.apply( w.console, [ '[99trace]' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}
	function lsGet( k ) {
		try { return w.localStorage.getItem( k ); } catch ( e ) { return null; }
	}
	function lsSet( k, v ) {
		try { w.localStorage.setItem( k, v ); } catch ( e ) {}
	}
	function cookieValue( name ) {
		var m = d.cookie.match( new RegExp( '(?:^|;\\s*)' + name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '=([^;]*)' ) );
		if ( ! m ) {
			return null;
		}
		try { return decodeURIComponent( m[ 1 ] ); } catch ( e ) { return m[ 1 ]; }
	}
	/* One visitor ID for the browser and the shop's server (cookie written by both; see Visitor.php). */
	var VID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
	function readVid() {
		var raw = cfg.visitorCookie ? cookieValue( cfg.visitorCookie ) : null;
		if ( ! raw ) {
			return null;
		}
		var minted = '.s' === raw.slice( -2 ); // created by the server; posthog-js has not started with it yet
		var id = ( minted ? raw.slice( 0, -2 ) : raw ).toLowerCase();
		return VID_RE.test( id ) ? { id: id, minted: minted } : null;
	}
	/** Written on every page, not only when it changes: Safari keeps cookies set by scripts 7 days from the last write. */
	function writeVid( id ) {
		if ( ! cfg.visitorCookie || ! id ) {
			return;
		}
		d.cookie = cfg.visitorCookie + '=' + encodeURIComponent( id ) + '; path=' + ( cfg.cookiePath || '/' ) +
			( cfg.cookieDomain ? '; domain=' + cfg.cookieDomain : '' ) + '; max-age=31536000; samesite=lax' +
			( 'https:' === ( w.location && w.location.protocol ) ? '; secure' : '' );
	}
	/** The paths the shop's cookies may have: WordPress's cookie path (a site in a subdirectory) and the root. */
	function cookiePaths() {
		var path = cfg.cookiePath || '/';
		return '/' === path ? [ '/' ] : [ '/', path ];
	}
	/** A random, time-ordered ID in posthog-js's own form (UUID v7). */
	function uuidv7() {
		var rnd = null;
		try {
			rnd = w.crypto && w.crypto.getRandomValues ? w.crypto.getRandomValues( new Uint8Array( 16 ) ) : null;
		} catch ( e ) {}
		var b = [];
		for ( var i = 0; i < 16; i++ ) {
			b.push( rnd ? rnd[ i ] : Math.floor( Math.random() * 256 ) );
		}
		var ms = Date.now();
		for ( var j = 5; j >= 0; j-- ) {
			b[ j ] = ms % 256;
			ms = Math.floor( ms / 256 );
		}
		b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x70;
		b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
		var h = b.map( function ( x ) { return ( x < 16 ? '0' : '' ) + x.toString( 16 ); } ).join( '' );
		return h.slice( 0, 8 ) + '-' + h.slice( 8, 12 ) + '-' + h.slice( 12, 16 ) + '-' + h.slice( 16, 20 ) + '-' + h.slice( 20 );
	}
	function capturing( p ) {
		try {
			return typeof p.is_capturing === 'function' ? !! p.is_capturing() : ! p.has_opted_out_capturing();
		} catch ( e ) {
			return false;
		}
	}

	function assign( target ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			var src = arguments[ i ] || {};
			for ( var k in src ) {
				if ( Object.prototype.hasOwnProperty.call( src, k ) ) {
					target[ k ] = src[ k ];
				}
			}
		}
		return target;
	}
	function ph() {
		var p = w.posthog;
		return p && p.__loaded ? p : null;
	}

	/* -------------------------------------------------------------- data hygiene */

	/** A configured parameter, or any parameter whose value is a WooCommerce order key. */
	function sensitiveParams() {
		if ( null === paramRe ) {
			var names = ( cfg.stripParams || [] ).map( function ( n ) {
				return String( n ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
			} );
			var stop = '[^&#\\s"\'<>]*';
			paramRe = new RegExp( '([?&;])(?:(?:' + ( names.length ? names.join( '|' ) : '(?!)' ) + ')=' + stop + '|[^=&#\\s"\'<>]+=wc_order_' + stop + ')', 'gi' );
		}
		return paramRe;
	}

	/** Remove order keys, e-mail addresses and the configured URL parameters from any text. */
	function scrubString( s ) {
		if ( s.length < 6 ) {
			return s;
		}
		if ( s.indexOf( '=' ) !== -1 ) {
			s = s.replace( sensitiveParams(), '$1' )
				.replace( /([?&;])[&;]+/g, '$1' )
				.replace( /[?&;]+(?=$|#|["'\s<>])/g, '' );
		}
		if ( s.indexOf( 'wc_order_' ) !== -1 ) {
			s = s.replace( ORDER_KEY, 'wc_order_[redacted]' ); // also inside encoded URLs
		}
		if ( s.indexOf( '@' ) !== -1 || s.indexOf( '%40' ) !== -1 ) {
			s = s.replace( EMAIL, '[email]' );
		}
		return s;
	}

	/** Every string in an event, at any depth: URLs inside web vitals, heatmap keys, autocapture's element chain. */
	function scrub( v, depth ) {
		if ( typeof v === 'string' ) {
			return scrubString( v );
		}
		if ( ! v || typeof v !== 'object' || depth > 6 ) {
			return v;
		}
		if ( Array.isArray( v ) ) {
			for ( var i = 0; i < v.length; i++ ) {
				v[ i ] = scrub( v[ i ], depth + 1 );
			}
			return v;
		}
		Object.keys( v ).forEach( function ( k ) {
			var val = scrub( v[ k ], depth + 1 );
			var nk = scrubString( k ); // heatmap data is keyed by page URL
			if ( nk !== k ) {
				delete v[ k ];
				if ( Array.isArray( v[ nk ] ) && Array.isArray( val ) ) {
					v[ nk ] = v[ nk ].concat( val );
					return;
				}
			}
			v[ nk ] = val;
		} );
		return v;
	}

	/**
	 * Customer details set on the person on purpose (Visitors & orders → customer details): kept through the
	 * scrubbing, which removes e-mail addresses from everything else. Only these keys, only in $set / $set_once.
	 */
	var PERSON_KEYS = [ 'email', 'first_name', 'last_name', 'name', 'phone' ];
	function personValues( ev ) {
		var kept = [];
		if ( ! cfg.personDetails ) {
			return kept;
		}
		[ ev.$set, ev.$set_once, ev.properties && ev.properties.$set, ev.properties && ev.properties.$set_once ].forEach( function ( o ) {
			if ( o && typeof o === 'object' ) {
				PERSON_KEYS.forEach( function ( k ) {
					if ( typeof o[ k ] === 'string' ) {
						kept.push( [ o, k, o[ k ] ] );
					}
				} );
			}
		} );
		return kept;
	}

	function beforeSend( ev ) {
		if ( ev && cfg.testMode ) {
			log( 'test mode, not sent:', ev.event );
			return null; // Mode Test: nothing reaches PostHog, session replay included
		}
		if ( ! ev || '$snapshot' === ev.event ) {
			return ev; // Replay data: masked by posthog-js, and off on order and account pages.
		}
		var person = personValues( ev );
		try {
			cleanStoredUrls( instance || ph() );
		} catch ( e ) {
			log( 'clean failed', e );
		}
		try {
			[ 'properties', '$set', '$set_once' ].forEach( function ( k ) {
				if ( ev[ k ] ) {
					ev[ k ] = scrub( ev[ k ], 0 );
				}
			} );
		} catch ( e ) {
			log( 'scrub failed', e );
		}
		person.forEach( function ( kv ) {
			kv[ 0 ][ kv[ 1 ] ] = kv[ 2 ];
		} );
		if ( refused ) {
			return null;
		}
		if ( holding ) {
			hold( ev );
			return null;
		}
		return ev;
	}

	/* ------------------------------------------------------------------ consent */

	/**
	 * Cookiebot's own record, readable before Cookiebot's script has loaded: '-1' (no consent needed in
	 * the visitor's region) or the accepted categories. null when the visitor has not answered.
	 */
	function cookiebotCookie() {
		var m = d.cookie.match( /(?:^|;\s*)CookieConsent=([^;]*)/ );
		if ( ! m ) {
			return null;
		}
		var v;
		try { v = decodeURIComponent( m[ 1 ] ); } catch ( e ) { return null; }
		if ( '-1' === v.replace( /\s/g, '' ) ) {
			return true;
		}
		var s = /statistics\s*:\s*(true|false)/i.exec( v );
		return s ? 'true' === s[ 1 ].toLowerCase() : null;
	}

	/** true / false, or null while the consent platform has not answered. */
	function consentNow() {
		switch ( cfg.consent ) {
			case 'none':
				return true;
			case 'posthog_opt_in':
				return true; // posthog-js starts opted out; the site opts the visitor in.
			case 'wp_consent_api':
				if ( typeof w.wp_has_consent !== 'function' ) {
					return null;
				}
				if ( w.wp_has_consent( 'statistics' ) ) {
					return true;
				}
				// In opt-in regions false also means "not answered yet": a refusal only once it is stored.
				var prefix = ( w.consent_api && w.consent_api.cookie_prefix ) || 'wp_consent';
				return 'deny' === cookieValue( prefix + '_statistics' ) ? false : null;
			default: // cookiebot
				var C = w.Cookiebot;
				if ( C && C.consent && C.hasResponse ) {
					return !! C.consent.statistics;
				}
				return cookiebotCookie(); // Cookiebot's script not there yet: its cookie says the same
		}
	}

	/** Before an answer: posthog-js starts at page load, switched off ('switch') or recording into memory ('hold'). */
	function earlyStart() {
		return !! cfg.earlyStart && ( 'cookiebot' === cfg.consent || 'wp_consent_api' === cfg.consent );
	}
	function holdMode() {
		return earlyStart() && 'hold' === cfg.beforeConsent;
	}

	function saveHeld() {
		if ( ! cfg.holdAcrossPages ) {
			return;
		}
		try { w.sessionStorage.setItem( HOLD_KEY, JSON.stringify( held ) ); } catch ( e ) {}
	}
	function loadHeld() {
		if ( ! cfg.holdAcrossPages ) {
			clearHeld();
			return;
		}
		try {
			var list = JSON.parse( w.sessionStorage.getItem( HOLD_KEY ) || '[]' );
			held = Array.isArray( list ) ? list.slice( -HOLD_MAX ) : [];
		} catch ( e ) {
			held = [];
		}
	}
	function clearHeld() {
		try {
			w.sessionStorage.removeItem( HOLD_KEY );
			w.sessionStorage.removeItem( HOLD_ID_KEY );
		} catch ( e ) {}
	}

	/** The visitor ID posthog-js stored at an earlier consent, if any (read only). */
	/**
	 * The visitor posthog-js stored at an earlier consent (read only): ID, whether it is an identified
	 * person (signed in, or identified by an earlier plugin), and the device ID. null without one.
	 */
	function storedVisitor() {
		var name = storagePrefix() + 'posthog';
		var raw = null;
		var m = d.cookie.match( new RegExp( '(?:^|;\\s*)' + name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '=([^;]*)' ) );
		try {
			raw = m ? decodeURIComponent( m[ 1 ] ) : lsGet( name );
			var v = raw ? JSON.parse( raw ) : null;
			if ( ! v || typeof v.distinct_id !== 'string' || ! v.distinct_id ) {
				return null;
			}
			return { id: v.distinct_id, identified: 'identified' === v.$user_state, device: typeof v.$device_id === 'string' ? v.$device_id : null };
		} catch ( e ) {
			return null;
		}
	}
	function storedVisitorId() {
		var v = storedVisitor();
		return v ? v.id : null;
	}

	/**
	 * One visitor ID while recording, so experiment variants stay the same from page to page and after consent:
	 * the ID stored at an earlier consent, else (recording kept across pages) one kept in session storage.
	 */
	function holdVisitorId() {
		var id = storedVisitorId();
		if ( id || ! cfg.holdAcrossPages ) {
			return id;
		}
		try {
			id = w.sessionStorage.getItem( HOLD_ID_KEY );
			if ( ! id ) {
				id = w.crypto && typeof w.crypto.randomUUID === 'function'
					? w.crypto.randomUUID()
					: 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
						var r = Math.random() * 16 | 0;
						return ( 'x' === c ? r : ( r & 3 | 8 ) ).toString( 16 );
					} );
				w.sessionStorage.setItem( HOLD_ID_KEY, id );
			}
			return id;
		} catch ( e ) {
			return null;
		}
	}

	/** Keep a scrubbed event until the visitor answers. */
	function hold( ev ) {
		var props = assign( {}, ev.properties || {} );
		HELD_IDENTITY_KEYS.forEach( function ( k ) {
			delete props[ k ];
		} );
		held.push( {
			e: ev.event,
			p: props,
			s: ev.$set || null,
			o: ev.$set_once || null,
			t: ( ev.timestamp ? new Date( ev.timestamp ) : new Date() ).toISOString()
		} );
		if ( held.length > HOLD_MAX ) {
			held.shift();
		}
		saveHeld();
	}

	/** Consent given while holding: store the visitor, then send what was recorded, at its own time. */
	function flushHeld( p ) {
		holding = false;
		refused = false;
		if ( memoryOnly ) {
			// Also after an earlier refusal on this page: from consent on, the visitor is stored as usual.
			memoryOnly = false;
			try { p.set_config( { persistence: 'localStorage+cookie' } ); } catch ( e ) { log( 'persistence switch failed', e ); }
		}
		var list = held;
		held = [];
		clearHeld();
		list.forEach( function ( h ) {
			try {
				// Not counted against posthog-js's client rate limit: these events were limited when recorded.
				var opts = { timestamp: new Date( h.t ), skip_client_rate_limiting: true };
				if ( h.s ) {
					opts.$set = h.s;
				}
				if ( h.o ) {
					opts.$set_once = h.o;
				}
				p.capture( h.e, h.p, opts );
			} catch ( e ) {
				log( 'held event not sent', e );
			}
		} );
		if ( list.length ) {
			log( 'consent given: sent ' + list.length + ' events recorded before it' );
		}
		runDeferred();
	}
	function runDeferred() {
		if ( deferred ) {
			var f = deferred;
			deferred = null;
			f();
		}
	}

	function storagePrefix() {
		return 'ph_' + cfg.token.replace( /\+|\//g, '_' ) + '_';
	}

	/**
	 * Delete PostHog's identity cookies (every domain level posthog-js may have used) and storage, and the
	 * account cookie. posthog-js's opt-out marker stays: it holds no identifier, and without it posthog-js
	 * would resume capturing on the page where consent was just withdrawn.
	 */
	function purgeStorage() {
		var prefix = storagePrefix();
		// The shop's visitor cookie too when "one visitor ID" was switched off since it was written.
		var names = [ cfg.customerCookie || 'ph_wc_customer', cfg.visitorCookie || 'ph_wc_vid' ];
		var found = false;
		d.cookie.split( /;\s*/ ).forEach( function ( c ) {
			var n = c.split( '=' )[ 0 ];
			if ( n.indexOf( prefix ) === 0 || names.indexOf( n ) !== -1 ) {
				found = true;
				if ( names.indexOf( n ) === -1 ) {
					names.push( n );
				}
			}
		} );
		[ w.localStorage, w.sessionStorage ].forEach( function ( st ) {
			try {
				for ( var i = st.length - 1; i >= 0; i-- ) {
					var key = st.key( i );
					if ( key && key.indexOf( prefix ) === 0 ) {
						st.removeItem( key );
						found = true;
					}
				}
			} catch ( e ) {}
		} );
		if ( ! found ) {
			return;
		}
		var labels = w.location.hostname.split( '.' );
		var domains = [ '' ];
		for ( var j = 0; j < labels.length - 1; j++ ) {
			domains.push( '; domain=.' + labels.slice( j ).join( '.' ) );
		}
		names.forEach( function ( n ) {
			domains.forEach( function ( dm ) {
				cookiePaths().forEach( function ( path ) {
					d.cookie = n + '=; path=' + path + '; max-age=0' + dm;
				} );
			} );
		} );
		log( 'consent refused: PostHog storage removed' );
	}

	function onConsentChange() {
		var c = consentNow();
		log( 'consent', c );
		if ( true === c && holdMode() && started && ! ph() ) {
			return; // Still loading: onLoaded sends the recording once posthog-js is there.
		}
		if ( false === c && holdMode() && ( holding || refused || ! started ) ) {
			// Refused before ever accepting: drop the recording; posthog-js keeps serving variants, sends nothing.
			holding = false;
			refused = true;
			held = [];
			deferred = null;
			clearHeld();
			purgeStorage();
			return;
		}
		if ( true === c ) {
			if ( ! started ) {
				start( false );
			} else if ( ph() ) {
				optIn( ph() );
				// Consent while posthog-js runs (switched off before): the shared ID and an order waiting on this thank-you page.
				syncVid( ph() );
				claimOrder( ph() );
				sendDetails( ph() ); // checkout fields typed before the Accept
			} else if ( w.posthog && typeof w.posthog.opt_in_capturing === 'function' ) {
				w.posthog.opt_in_capturing( { captureEventName: false } ); // still loading: queued
				queuedOptIn = true;
			}
		} else if ( false === c ) {
			var p = ph();
			if ( started && p && ( typeof p.is_capturing !== 'function' || p.is_capturing() ) ) {
				// Withdrawn: stop capturing and delete PostHog's cookie and storage. Switched off
				// already (consent switch, never accepted): nothing to stop, and no opt-out mark stored.
				p.opt_out_capturing();
				try { p.set_config( { disable_persistence: true } ); } catch ( e ) {}
			} else if ( started && ! p && queuedOptIn && w.posthog && typeof w.posthog.opt_out_capturing === 'function' ) {
				w.posthog.opt_out_capturing(); // accepted, then refused before posthog-js loaded
			}
			queuedOptIn = false;
			purgeStorage();
		}
	}

	/** Consent given (again): posthog-js may still hold an opt-out from an earlier withdrawal. */
	function optIn( p ) {
		if ( 'posthog_opt_in' === cfg.consent ) {
			return; // The site decides.
		}
		if ( holding || refused || held.length ) {
			flushHeld( p ); // "Record the visit": send it now, then run normally.
			return;
		}
		if ( memoryOnly ) {
			// Switched off with a stored visitor: store them again, under the same ID, from page memory.
			memoryOnly = false;
			try { p.set_config( { persistence: 'localStorage+cookie' } ); } catch ( e ) { log( 'persistence switch failed', e ); }
		}
		try { p.set_config( { disable_persistence: false } ); } catch ( e ) {}
		if ( ( typeof p.is_capturing === 'function' && ! p.is_capturing() ) || ( typeof p.has_opted_out_capturing === 'function' && p.has_opted_out_capturing() ) ) {
			// Sends the page view (and exposures) held back while switched off; no extra $opt_in event.
			p.opt_in_capturing( { captureEventName: false } );
			log( 'switched on: consent given' );
		}
	}

	/* --------------------------------------------------------------- posthog-js */

	function hasPostHogHistory() {
		var name = storagePrefix() + 'posthog';
		if ( d.cookie.indexOf( name + '=' ) !== -1 ) {
			return true;
		}
		return !! lsGet( name );
	}

	function snippet() {
		// posthog-js's official loader, written out: queue calls until array.js arrives.
		var posthog = ( w.posthog = w.posthog || [] );
		posthog._i = [];
		posthog.init = function ( token, config, name ) {
			var s = d.createElement( 'script' );
			s.type = 'text/javascript';
			s.crossOrigin = 'anonymous';
			s.async = true;
			// This module handles consent (loaded after it, or switched off or recording until it); with "Track
			// every visitor" the shop owner decided no banner gates PostHog. Cookiebot must not block either way.
			s.setAttribute( 'data-cookieconsent', 'ignore' );
			s.src = config.api_host.replace( '.i.posthog.com', '-assets.i.posthog.com' ) + '/static/array.js';
			var first = d.getElementsByTagName( 'script' )[ 0 ];
			first.parentNode.insertBefore( s, first );
			var target = posthog;
			if ( name !== undefined ) {
				target = posthog[ name ] = [];
			} else {
				name = 'posthog';
			}
			target.people = target.people || [];
			target.toString = function ( noStub ) {
				return ( name === 'posthog' ? 'posthog' : 'posthog.' + name ) + ( noStub ? '' : ' (stub)' );
			};
			target.people.toString = function () {
				return target.toString( 1 ) + '.people (stub)';
			};
			( 'init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags onFeatureFlags onSessionId identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags reset alias set_config startSessionRecording stopSessionRecording captureException loadToolbar get_property getSessionProperty get_distinct_id get_session_id createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing get_explicit_consent_status is_capturing clear_opt_in_out_capturing calculateEventProperties debug on' ).split( ' ' ).forEach( function ( m ) {
				target[ m ] = function () {
					target.push( [ m ].concat( Array.prototype.slice.call( arguments, 0 ) ) );
				};
			} );
			posthog._i.push( [ token, config, name ] );
		};
		posthog.__SV = 1;
	}

	/** @param {boolean} switchedOff consent switch: start without recording or storing anything until consent */
	function start( switchedOff ) {
		if ( started ) {
			return;
		}
		if ( w.posthog && ( w.posthog.__SV || w.posthog.__loaded ) ) {
			// Another PostHog snippet already owns window.posthog: two instances would split the visitor.
			log( 'another posthog-js instance is on the page; 99Trace browser module stays off' );
			return;
		}
		started = true;
		var link = cfg.orderLink;
		var config = {
			api_host: cfg.apiHost,
			ui_host: cfg.uiHost,
			defaults: cfg.defaults,
			person_profiles: cfg.personProfiles === 'identified_only' ? 'identified_only' : 'always',
			autocapture: !! cfg.autocapture,
			capture_pageleave: !! cfg.pageleave,
			// The store's server reads identity and consent from the cookie.
			opt_out_capturing_persistence_type: 'cookie',
			// Write the cookie at once. The 2026-05-30 defaults delay writes by 250 ms and flush them
			// on pagehide, which loses the race with the next request: a click on "buy" right after
			// posthog-js loaded reached the server without any PostHog identity.
			persistence_save_debounce_ms: 0,
			before_send: beforeSend,
			loaded: onLoaded
		};
		var holdNow = switchedOff && holdMode();
		var kept = null; // switched off: the visitor stored at an earlier consent, kept in page memory
		if ( holdNow ) {
			// Recording before an answer: nothing stored in the browser, nothing sent (before_send keeps it).
			holding = ! refused;
			loadHeld();
		} else if ( switchedOff && 'posthog_opt_in' !== cfg.consent && ( kept = storedVisitor() ) ) {
			// A returning visitor asked again (their answer expired, or the banner changed): "store nothing"
			// would make posthog-js delete their cookie, and the next page before the answer would start a new
			// visitor. Keep the stored visitor in the page's memory instead, without touching the cookie; on
			// Accept it is stored again under the same ID, on a refusal the cookie is deleted as before.
			config.opt_out_capturing_by_default = true;
			config.persistence = 'memory';
			config.bootstrap = { distinctID: kept.id, isIdentifiedID: kept.identified };
			memoryOnly = true;
		} else if ( cfg.consent === 'posthog_opt_in' || switchedOff ) {
			config.opt_out_capturing_by_default = true;
			config.opt_out_persistence_by_default = true;
		} else if ( 'hold' === cfg.beforeConsent ) {
			loadHeld(); // consent already given (another tab): earlier pages' recording is sent on load
		}
		// Return in a browser with no PostHog history (the bank app opened the default browser):
		// continue the buyer's identity instead of starting a new one. No merge is needed.
		if ( link && link.linkable && link.distinct_id && cfg.linkReturn && ! hasPostHogHistory() ) {
			config.bootstrap = { distinctID: link.distinct_id, isIdentifiedID: false };
			if ( link.session_id ) {
				config.bootstrap.sessionID = link.session_id;
			}
			linkMethod = 'bootstrap';
		}
		// One visitor ID for browser and server: posthog-js without an ID of its own starts with the shop's (one the
		// server created for this browser, or kept from earlier), else with a new one, written for the server at once,
		// so a click on "add to cart" while posthog-js is still loading already carries it. Only where the visitor may
		// be recorded now (not switched off or recording before an answer, not waiting for the site's own opt-in).
		if ( ! config.bootstrap && cfg.visitorCookie && ! switchedOff && 'posthog_opt_in' !== cfg.consent && ! hasPostHogHistory() ) {
			var vid = readVid();
			var vidId = vid ? vid.id : uuidv7();
			config.bootstrap = { distinctID: vidId, isIdentifiedID: false };
			writeVid( vidId );
			log( vid ? 'starting with the shop\'s visitor ID' : 'new visitor ID for browser and shop', vidId );
		}
		assign( config, cfg.posthogConfig || {} );
		// Events switched off in the Events settings stay off, whatever the further options say.
		if ( false === cfg.pageview ) {
			config.capture_pageview = false;
		}
		[ [ 'autocapture', 'autocapture' ], [ 'pageleave', 'capture_pageleave' ] ].forEach( function ( pair ) {
			if ( ! cfg[ pair[ 0 ] ] ) {
				config[ pair[ 1 ] ] = false;
			}
		} );
		// Dead clicks: unset (null) leaves them to the PostHog project's setting; true / false override it.
		if ( true === cfg.deadClicks || false === cfg.deadClicks ) {
			config.capture_dead_clicks = cfg.deadClicks;
		}
		if ( cfg.sensitive ) {
			// Order and account pages show the buyer's name, address and e-mail: clicks are still recorded,
			// without element text or attributes; no replay, no heatmaps. After the shop's own options, so
			// no setting can switch these back on here.
			config.mask_all_text = true;
			config.mask_all_element_attributes = true;
			config.disable_session_recording = true;
			config.capture_heatmaps = false;
		}
		if ( holdNow ) {
			memoryOnly = true;
			var stored = config.bootstrap ? null : storedVisitor();
			var holdId = config.bootstrap || stored ? null : holdVisitorId();
			if ( stored ) {
				config.bootstrap = { distinctID: stored.id, isIdentifiedID: stored.identified };
				kept = stored;
			} else if ( holdId ) {
				config.bootstrap = { distinctID: holdId, isIdentifiedID: false };
			}
			config.persistence = 'memory';
			config.disable_session_recording = true; // a replay cannot be held back
			config.disable_surveys = true; // surveys store what was shown
		}
		keptDevice = kept && kept.device ? kept.device : null;
		snippet();
		w.posthog.init( cfg.token, config );
		if ( switchedOff ) {
			// Forget an "opted in" mark stored at an earlier consent, which would otherwise switch capture on
			// although the consent platform has no consent on record now.
			w.posthog.clear_opt_in_out_capturing();
		}
		log( 'posthog-js initialising' + ( switchedOff ? ', switched off until consent' : '' ), config );
	}

	/**
	 * Checkout fields the buyer filled in, as person properties, as soon as they are there:
	 * PostHog's destinations hash them for the ad platforms. Classic, block and FunnelKit checkouts. Only with
	 * consent and while capturing; each value once per page.
	 */
	var DETAIL_FIELDS = {
		email: [ 'billing_email', 'email' ],
		first_name: [ 'billing_first_name', 'billing-first_name', 'shipping-first_name' ],
		last_name: [ 'billing_last_name', 'billing-last_name', 'shipping-last_name' ],
		phone: [ 'billing_phone', 'billing-phone', 'shipping-phone' ]
	};
	// Only fields of a checkout (classic, block, FunnelKit): a newsletter or contact form's e-mail is not the buyer's.
	var CHECKOUT_FORMS = 'form.checkout, form.woocommerce-checkout, .wp-block-woocommerce-checkout, .wc-block-checkout, #wfacp_checkout_form';
	function inCheckout( el ) {
		try {
			return !! ( el && typeof el.closest === 'function' && el.closest( CHECKOUT_FORMS ) );
		} catch ( e ) {
			return false;
		}
	}
	var sentDetails = {};
	function detailField( key ) {
		var names = DETAIL_FIELDS[ key ];
		for ( var i = 0; i < names.length; i++ ) {
			var el = ( d.querySelector && d.querySelector( '[name="' + names[ i ] + '"]' ) ) || ( d.getElementById && d.getElementById( names[ i ] ) );
			if ( el && inCheckout( el ) && typeof el.value === 'string' && el.value.trim() ) {
				return el.value.trim();
			}
		}
		return '';
	}
	function sendDetails( p ) {
		if ( ! cfg.personDetails || ! p || typeof p.setPersonProperties !== 'function' || holding || refused || true !== consentNow() ) {
			return;
		}
		if ( typeof p.is_capturing === 'function' && ! p.is_capturing() ) {
			return;
		}
		if ( 'identified_only' === cfg.personProfiles && 'identified' !== p.get_property( '$user_state' ) ) {
			return; // Persons only for signed-in customers: setting properties would make one for this visitor.
		}
		var props = {};
		Object.keys( DETAIL_FIELDS ).forEach( function ( k ) {
			var v = detailField( k );
			if ( 'email' === k ) {
				v = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v ) ? v.toLowerCase() : '';
			}
			if ( v ) {
				props[ k ] = v;
			}
		} );
		if ( props.first_name || props.last_name ) {
			props.name = ( ( props.first_name || '' ) + ' ' + ( props.last_name || '' ) ).trim();
		}
		Object.keys( props ).forEach( function ( k ) {
			if ( sentDetails[ k ] === props[ k ] ) {
				delete props[ k ];
			}
		} );
		if ( ! Object.keys( props ).length ) {
			return;
		}
		assign( sentDetails, props );
		p.setPersonProperties( props );
		log( 'customer details set on the person', Object.keys( props ) );
	}
	d.addEventListener( 'change', function ( e ) {
		var t = e && e.target;
		var n = t && ( t.name || t.id );
		if ( n && /^((billing|shipping)[_-])?(email|first_name|last_name|phone)$/.test( n ) && inCheckout( t ) ) {
			sendDetails( instance || ph() );
		}
	}, true );

	/* ------------------------------------------------------------------ identity */

	/** @param {object} [given] posthog-js itself: inside its loaded callback window.posthog is still the stub. */
	function readEnvelope( given ) {
		var p = given || instance || ph();
		if ( ! p || holding || refused ) {
			return null; // no visitor before consent
		}
		var id = p.get_distinct_id();
		if ( ! id || id === '$posthog_cookieless' ) {
			return null;
		}
		var flags = {};
		try {
			// Stored variants: reading them sends no $feature_flag_called, unlike getFeatureFlag().
			var stored = p.get_property( '$enabled_feature_flags' ) || {};
			Object.keys( stored ).forEach( function ( k ) {
				if ( stored[ k ] !== false && stored[ k ] !== null && stored[ k ] !== undefined ) {
					flags[ k ] = stored[ k ];
				}
			} );
		} catch ( e ) {}
		var props = {};
		try {
			var all = p.calculateEventProperties( 'Order Completed', {}, new Date(), undefined, true ) || {};
			SNAPSHOT_KEYS.forEach( function ( k ) {
				if ( all[ k ] !== undefined && all[ k ] !== null && all[ k ] !== '' ) {
					props[ k ] = typeof all[ k ] === 'string' ? scrubString( all[ k ] ) : all[ k ];
				}
			} );
		} catch ( e ) {
			log( 'snapshot failed', e );
		}
		return {
			v: 1,
			token: cfg.token,
			distinct_id: id,
			device_id: p.get_property( '$device_id' ) || null,
			session_id: typeof p.get_session_id === 'function' ? p.get_session_id() || null : null,
			user_state: p.get_property( '$user_state' ) || null,
			person_processing: ( p.config && p.config.person_profiles === 'always' ) || p.get_property( '$epp' ) === true,
			capturing: typeof p.is_capturing === 'function' ? p.is_capturing() : ! p.has_opted_out_capturing(),
			consent_status: typeof p.get_explicit_consent_status === 'function' ? p.get_explicit_consent_status() : null,
			flags: flags,
			props: props,
			captured_at: new Date().toISOString()
		};
	}

	function envelopeJson() {
		var env = readEnvelope();
		return env ? JSON.stringify( env ) : '';
	}

	function stampForms() {
		var forms = d.querySelectorAll( 'form.checkout, form.woocommerce-checkout, form#order_review' );
		Array.prototype.forEach.call( forms, function ( form ) {
			var input = form.querySelector( 'input[name="' + FIELD + '"]' );
			if ( ! input ) {
				input = d.createElement( 'input' );
				input.type = 'hidden';
				input.name = FIELD;
				form.appendChild( input );
			}
			input.value = envelopeJson();
		} );
		return forms.length;
	}

	function watchCheckout() {
		if ( stampForms() && w.jQuery ) {
			w.jQuery( d.body ).on( 'updated_checkout', stampForms );
			w.jQuery( 'form.checkout' ).on( 'checkout_place_order', function () {
				stampForms();
				return true;
			} );
		}
		d.addEventListener( 'submit', stampForms, true );
		// Block checkout: Store API extension data.
		var wp = w.wp;
		if ( wp && wp.data && wp.data.dispatch && d.querySelector( '.wp-block-woocommerce-checkout' ) ) {
			var key = ( w.wc && w.wc.wcBlocksData && w.wc.wcBlocksData.CHECKOUT_STORE_KEY ) || 'wc/store/checkout';
			var push = function () {
				var dispatch = wp.data.dispatch( key );
				if ( dispatch && typeof dispatch.setExtensionData === 'function' ) {
					dispatch.setExtensionData( STORE_API_NS, { identity: envelopeJson() }, true );
				}
			};
			push();
			var before = false;
			wp.data.subscribe( function () {
				var sel = wp.data.select( key );
				var now = sel && typeof sel.isBeforeProcessing === 'function' && sel.isBeforeProcessing();
				if ( now && ! before ) {
					push();
				}
				before = now;
			}, key );
		}
	}

	/** Signed in: identify with the account ID from the login cookie. Signed out: never keep an account's ID. */
	function syncCustomer( p ) {
		var current = p.get_distinct_id();
		var customer = cfg.identifyCustomers ? cookieValue( cfg.customerCookie || 'ph_wc_customer' ) : null;
		if ( customer ) {
			if ( current !== customer ) {
				p.identify( customer );
				log( 'identified customer', customer );
			}
		} else if ( cfg.customerPrefix && typeof current === 'string' && current.indexOf( cfg.customerPrefix ) === 0 ) {
			p.reset();
			log( 'signed out: reset' );
		}
	}

	/**
	 * Keep the shop's visitor-ID cookie on posthog-js's ID (the server sends cart, checkout and order events
	 * under it). An ID the server created for this browser while posthog-js already had another is merged
	 * into posthog-js's ID once (alias), so what the server sent under it joins the visitor.
	 */
	function syncVid( p ) {
		if ( ! cfg.visitorCookie || holding || refused || ! capturing( p ) ) {
			return;
		}
		var id = p.get_distinct_id();
		if ( ! id || '$posthog_cookieless' === id ) {
			return;
		}
		var vid = readVid();
		if ( vid && vid.minted && vid.id !== id ) {
			p.alias( vid.id );
			log( 'joined the visitor ID the shop created before PostHog had one', vid.id );
		}
		if ( 'identified' !== p.get_property( '$user_state' ) ) {
			writeVid( id );
		} else if ( vid && vid.minted ) {
			writeVid( vid.id ); // signed in: keep the anonymous ID (cleared at logout), without the server's mark
		}
	}

	/** Thank-you page of an order placed before the visitor could be recorded: link it now (the shop checks the order key). */
	function claimOrder( p ) {
		var link = cfg.orderLink;
		if ( claimed || ! link || ! link.claimable || ! cfg.linkUrl || typeof w.fetch !== 'function' || holding || refused || ! capturing( p ) ) {
			return;
		}
		var m = /[?&]key=(wc_order_[A-Za-z0-9]+)/.exec( ( w.location && w.location.search ) || '' );
		var env = readEnvelope( p );
		if ( ! m || ! env ) {
			return;
		}
		claimed = true;
		try {
			w.fetch( cfg.linkUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'order_id=' + encodeURIComponent( link.order_id ) + '&key=' + encodeURIComponent( m[ 1 ] ) + '&identity=' + encodeURIComponent( JSON.stringify( env ) )
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				log( 'order placed before consent linked', !! ( j && j.linked ) );
			} ).catch( function () {} );
		} catch ( e ) {
			log( 'order link failed', e );
		}
	}

	/* --------------------------------------------------------------- page events */

	function orderConfirmation( p ) {
		var link = cfg.orderLink;
		if ( ! link ) {
			return;
		}
		var key = 'ph_wc_ty_' + link.order_id;
		var seen = lsGet( key );
		var current = p.get_distinct_id();
		var device = p.get_property( '$device_id' );
		var context;
		if ( 'bootstrap' === linkMethod ) {
			context = 'new_browser_continued';
		} else if ( link.claimable ) {
			context = 'placed_before_consent'; // linked by claimOrder() once the visitor accepts
		} else if ( link.distinct_id && ( current === link.distinct_id || ( link.device_id && device === link.device_id ) ) ) {
			context = 'same_browser'; // also after an account was created at checkout (new distinct ID, same device)
		} else if ( link.linkable && cfg.linkReturn && link.distinct_id ) {
			if ( 'anonymous' === p.get_property( '$user_state' ) || ! p.get_property( '$user_state' ) ) {
				if ( ! seen ) {
					// This browser has its own anonymous history: ask PostHog to merge it into the buyer.
					// PostHog refuses when the buyer's person is already identified (e.g. joined from a
					// third browser); the name says what was sent, not what PostHog decided.
					p.alias( link.distinct_id );
				}
				context = 'known_browser_alias_sent';
			} else {
				context = 'identified_browser_not_joined'; // A signed-in account here: do not merge another identity into it.
			}
		} else {
			context = 'not_linked';
		}
		if ( ! seen ) {
			if ( false !== ( cfg.events || {} ).confirmationViewed ) {
				p.capture( eventName( 'Order Confirmation Viewed' ), { order_id: link.order_id, return_context: context, link_reason: link.reason || null, currency: cfg.currency } );
			}
			lsSet( key, context );
		}
		log( 'order confirmation', context );
	}

	/** The name an event is sent under: the shop's own name for it (Events settings), else the standard one. */
	function eventName( standard ) {
		var names = cfg.eventNames || {};
		return typeof names[ standard ] === 'string' && names[ standard ] ? names[ standard ] : standard;
	}

	function pageEvents( p ) {
		var page = cfg.page || {};
		var ev = cfg.events || {};
		if ( 'product' === page.type && ev.productViewed ) {
			p.capture( eventName( 'Product Viewed' ), assign( { currency: cfg.currency }, page.product ) );
		} else if ( 'category' === page.type && ev.categoryViewed ) {
			p.capture( eventName( 'View Category' ), assign( { currency: cfg.currency }, page.category ) );
		} else if ( 'cart' === page.type && ev.cartViewed ) {
			p.capture( eventName( 'Cart Viewed' ), assign( { currency: cfg.currency }, page.cart ) );
		}
	}

	/**
	 * posthog-js stores the visitor's first URL and referrer ($initial_person_info, set inside its first
	 * capture, just before before_send) and sends them as person properties with every flag request, a
	 * request before_send never sees. A browser whose first page is a thank-you page would send its order
	 * key with every later flag request, so these stored values are cleaned (posthog-js 1.447.15: the flag
	 * request reads them from storage each time). posthog-js's own masking cannot be limited to these
	 * parameters: it masks the ad click IDs too.
	 */
	var PERSON_SOURCES = [ '$initial_person_info', '$initial_campaign_params', '$initial_referrer_info', '$stored_person_properties' ];
	function cleanStoredUrls( p ) {
		var store = p && p.persistence;
		if ( ! store || ! store.props || typeof store.props !== 'object' ) {
			return;
		}
		var changed = false;
		PERSON_SOURCES.forEach( function ( k ) {
			var v = store.props[ k ];
			if ( ! v || typeof v !== 'object' ) {
				return;
			}
			var before = JSON.stringify( v );
			var after = scrub( JSON.parse( before ), 0 );
			if ( JSON.stringify( after ) !== before ) {
				store.props[ k ] = after;
				changed = true;
			}
		} );
		if ( changed && typeof store.save === 'function' ) {
			store.save();
			log( 'removed an order key or listed parameter from the URLs posthog-js stored' );
		}
	}

	function onLoaded( p ) {
		instance = p;
		try {
			cleanStoredUrls( p );
		} catch ( e ) {
			log( 'clean failed', e );
		}
		if ( keptDevice ) {
			// The stored visitor's device continues too (bootstrap restores only the ID); page memory only.
			try { p.register( { $device_id: keptDevice } ); } catch ( e ) {}
			keptDevice = null;
		}
		try {
			if ( true === consentNow() ) {
				optIn( p );
			}
			if ( /(?:^|;\s*)ph_wc_logout=1(?:;|$)/.test( d.cookie ) ) {
				cookiePaths().forEach( function ( path ) {
					d.cookie = 'ph_wc_logout=; path=' + path + '; max-age=0; samesite=lax';
				} );
				p.reset();
				log( 'reset after logout' );
			}
			var identityWork = function () {
				syncCustomer( p );
				orderConfirmation( p );
				syncVid( p );
				claimOrder( p );
				sendDetails( p );
			};
			if ( holding || refused ) {
				deferred = identityWork; // linking a browser or an account waits for consent
			} else {
				identityWork();
			}
			pageEvents( p );
			if ( d.readyState === 'loading' ) {
				d.addEventListener( 'DOMContentLoaded', watchCheckout );
			} else {
				watchCheckout();
			}
		} catch ( e ) {
			log( 'error', e );
		}
	}

	/* -------------------------------------------------------------------- wiring */

	[ 'CookiebotOnConsentReady', 'CookiebotOnAccept', 'CookiebotOnDecline', 'CookiebotOnLoad' ].forEach( function ( name ) {
		w.addEventListener( name, onConsentChange );
	} );
	d.addEventListener( 'wp_listen_for_consent_change', onConsentChange );

	var initial = consentNow();
	if ( true === initial ) {
		start( false );
	} else {
		if ( false === initial ) {
			purgeStorage();
			if ( holdMode() ) {
				refused = true;
				clearHeld();
			}
		}
		if ( earlyStart() ) {
			start( true );
		}
		if ( 'cookiebot' === cfg.consent || 'wp_consent_api' === cfg.consent ) {
			// The consent platform may load after us, and consent may come later (by click, by continued
			// browsing, in another tab): check every 200 ms for 10 s, then, while switched off, every second.
			var tries = 0;
			var watch = function () {
				tries++;
				var c = consentNow();
				if ( true === c ) {
					onConsentChange();
					return;
				}
				if ( false === c && ! earlyStart() ) {
					purgeStorage();
					return;
				}
				if ( false === c && holding ) {
					onConsentChange(); // refused without an event: drop the recording, keep watching for a later yes
				}
				if ( tries <= 50 ) {
					w.setTimeout( watch, 200 );
				} else if ( earlyStart() ) {
					w.setTimeout( watch, 1000 );
				}
			};
			w.setTimeout( watch, 200 );
		}
	}

	/* ----------------------------------------------------- pages loaded in advance */

	// A page the browser fetched before the visitor opened it (WordPress's speculative loading, on a press
	// or a touch): the shop holds its cart and checkout events until the page reports itself shown, so a
	// link touched but never opened counts nothing. Not a PostHog call: the shop recorded those events
	// under this visitor's consent at the time. A prefetched page runs this module only once it is shown;
	// a prerendered one runs it early, so it waits for the switch.
	var shownSent = false;
	function reportShown() {
		var token = cfg.shown;
		if ( shownSent || ! token || ! cfg.shownUrl ) {
			return;
		}
		if ( d.prerendering ) {
			d.addEventListener( 'prerenderingchange', reportShown, { once: true } );
			return;
		}
		shownSent = true;
		var body = 't=' + encodeURIComponent( token );
		try {
			var nav = w.navigator;
			if ( nav && typeof nav.sendBeacon === 'function' && typeof Blob === 'function' && nav.sendBeacon( cfg.shownUrl, new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } ) ) ) {
				log( 'page loaded in advance reported shown' );
				return;
			}
			if ( typeof w.fetch === 'function' ) {
				w.fetch( cfg.shownUrl, {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body
				} ).catch( function () {} );
			}
		} catch ( e ) {
			log( 'reporting the page shown failed', e );
		}
	}
	// The token can be printed at the end of the page (print_shown()), after this module started.
	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', reportShown );
	} else {
		reportShown();
	}

	// For tests and for sites that manage consent themselves.
	cfg.reportShown = reportShown;
	cfg.identity = readEnvelope;
	cfg.start = function () { start( false ); };
	cfg.scrub = function ( v ) { return scrub( v, 0 ); };
	cfg.cleanStored = cleanStoredUrls; // for the unit checks
} )( window, document );
