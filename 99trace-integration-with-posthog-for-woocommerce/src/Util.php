<?php
/**
 * Small pure helpers: UTC time, money, hosts, URLs, IPs, user agents. No WordPress calls
 * except where noted, so they can be tested with plain PHP.
 *
 * @package W99Phwc
 */

namespace W99Phwc;

defined( 'ABSPATH' ) || exit;

final class Util {

	/**
	 * ISO 8601 UTC ("2026-11-12T09:41:07Z") from a WC_DateTime, DateTimeInterface, Unix time or null (= now).
	 *
	 * WC_DateTime::date('c') formats the shop's local time with a +00:00 label (the 0.2.0 bug);
	 * only getTimestamp() is used here.
	 *
	 * @param mixed $when
	 */
	public static function utc( $when = null ): string {
		if ( $when instanceof \DateTimeInterface ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', $when->getTimestamp() );
		}
		if ( is_int( $when ) || ( is_string( $when ) && ctype_digit( $when ) ) ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', (int) $when );
		}
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	public static function money( $amount ): float {
		return round( (float) $amount, 4 );
	}

	public static function normalize_host( string $host ): string {
		$host = trim( $host );
		if ( '' === $host ) {
			return 'https://eu.i.posthog.com';
		}
		if ( ! preg_match( '#^https?://#i', $host ) ) {
			$host = 'https://' . $host;
		}
		return rtrim( $host, '/' );
	}

	/** A public IPv4/IPv6 address, or '' (private, reserved, malformed). */
	public static function public_ip( ?string $ip ): string {
		$ip = trim( (string) $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? $ip : '';
	}

	/**
	 * Remove query parameters that must not reach analytics (order keys, nonces, e-mail addresses).
	 *
	 * @param string[] $params Lower-case parameter names.
	 */
	public static function strip_url( ?string $url, array $params ): ?string {
		if ( null === $url || '' === $url || false === strpos( $url, '?' ) ) {
			return $url;
		}
		$hash = '';
		if ( false !== ( $p = strpos( $url, '#' ) ) ) {
			$hash = substr( $url, $p );
			$url  = substr( $url, 0, $p );
		}
		list( $base, $query ) = array_pad( explode( '?', $url, 2 ), 2, '' );
		$kept = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			$name  = strtolower( rawurldecode( $parts[0] ) );
			// A listed parameter, or any parameter carrying a WooCommerce order key (download links: `order=`).
			if ( ! in_array( $name, $params, true ) && 0 !== stripos( rawurldecode( $parts[1] ?? '' ), 'wc_order_' ) ) {
				$kept[] = $pair;
			}
		}
		return $base . ( $kept ? '?' . implode( '&', $kept ) : '' ) . $hash;
	}

	public static function host_of( ?string $url ): ?string {
		if ( ! $url ) {
			return null;
		}
		$h = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $h ? strtolower( $h ) : null;
	}

	public static function path_of( ?string $url ): ?string {
		if ( ! $url ) {
			return null;
		}
		$p = parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $p ? $p : '/';
	}

	/** Short, safe identifier (distinct IDs, session IDs): 1–200 printable characters. */
	public static function clean_id( $value ): ?string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 200 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return null;
		}
		// An ID that text sanitizing would change (tags, encoded characters, runs of spaces) is no posthog-js ID.
		if ( function_exists( 'sanitize_text_field' ) && sanitize_text_field( $value ) !== $value ) {
			return null;
		}
		// posthog-js cookieless placeholder and PostHog's illegal merge IDs are not identities.
		$illegal = array( '$posthog_cookieless', 'anonymous', 'guest', 'distinctid', 'distinct_id', 'id', 'not_authenticated', 'email', 'undefined', 'true', 'false', 'null', 'none', 'nan', '0', '[object object]' );
		if ( in_array( strtolower( $value ), $illegal, true ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Browser, OS and device type from a user agent, following posthog-js's own rules, so a server
	 * event reports what a browser event from the same buyer would. Also names in-app browsers.
	 *
	 * @return array<string, string|float|null>
	 */
	public static function user_agent( ?string $ua ): array {
		$ua = (string) $ua;
		if ( '' === $ua ) {
			return array();
		}
		$has = static fn( string $needle ): bool => false !== strpos( $ua, $needle );

		// Browser (order as in posthog-js).
		if ( $has( 'GSA/' ) ) {
			$browser = 'Google Search App';
		} elseif ( $has( ' OPR/' ) && $has( 'Mini' ) ) {
			$browser = 'Opera Mini';
		} elseif ( $has( ' OPR/' ) ) {
			$browser = 'Opera';
		} elseif ( preg_match( '/BlackBerry|PlayBook|BB10/i', $ua ) ) {
			$browser = 'BlackBerry';
		} elseif ( $has( 'IEMobile' ) || $has( 'WPDesktop' ) ) {
			$browser = 'Internet Explorer Mobile';
		} elseif ( $has( 'SamsungBrowser' ) ) {
			$browser = 'Samsung Internet';
		} elseif ( $has( 'Edge' ) || $has( 'Edg/' ) ) {
			$browser = 'Microsoft Edge';
		} elseif ( $has( 'FBIOS' ) ) {
			$browser = 'Facebook Mobile';
		} elseif ( $has( 'UCWEB' ) || $has( 'UCBrowser' ) ) {
			$browser = 'UC Browser';
		} elseif ( $has( 'CriOS' ) ) {
			$browser = 'Chrome iOS';
		} elseif ( $has( 'CrMo' ) || $has( 'Chrome' ) ) {
			$browser = 'Chrome';
		} elseif ( $has( 'Android' ) && $has( 'Safari' ) ) {
			$browser = 'Android Mobile';
		} elseif ( $has( 'FxiOS' ) ) {
			$browser = 'Firefox iOS';
		} elseif ( ( $has( 'Safari' ) && ! $has( 'Android' ) ) || ( $has( 'AppleWebKit' ) && preg_match( '/iPhone|iPad|iPod|Macintosh/', $ua ) ) ) {
			// posthog-js also uses navigator.vendor ("Apple…"), which servers lack: WebKit on Apple devices is Safari.
			$browser = $has( 'Mobile' ) ? 'Mobile Safari' : 'Safari';
		} elseif ( $has( 'Firefox' ) ) {
			$browser = 'Firefox';
		} elseif ( $has( 'MSIE' ) || $has( 'Trident/' ) ) {
			$browser = 'Internet Explorer';
		} elseif ( $has( 'Gecko' ) ) {
			$browser = 'Firefox';
		} else {
			$browser = '';
		}

		$version_rx = array(
			'Chrome'           => '/(?:Chrome|CrMo)\/(\d+(?:\.\d+)?)/',
			'Chrome iOS'       => '/CriOS\/(\d+(?:\.\d+)?)/',
			'Microsoft Edge'   => '/Edge?\/(\d+(?:\.\d+)?)/',
			'Safari'           => '/Version\/(\d+(?:\.\d+)?)/',
			'Mobile Safari'    => '/Version\/(\d+(?:\.\d+)?)/',
			'Firefox'          => '/Firefox\/(\d+(?:\.\d+)?)/',
			'Firefox iOS'      => '/FxiOS\/(\d+(?:\.\d+)?)/',
			'Samsung Internet' => '/SamsungBrowser\/(\d+(?:\.\d+)?)/',
			'Opera'            => '/(?:Opera|OPR)\/(\d+(?:\.\d+)?)/',
			'Android Mobile'   => '/android\s(\d+(?:\.\d+)?)/i',
			'Google Search App' => '/GSA\/(\d+(?:\.\d+)?)/',
		);
		$browser_version = null;
		if ( isset( $version_rx[ $browser ] ) && preg_match( $version_rx[ $browser ], $ua, $m ) ) {
			$browser_version = (float) $m[1];
		}

		// OS.
		$os = '';
		$os_version = '';
		if ( preg_match( '/Windows/i', $ua ) ) {
			$os = preg_match( '/Phone|WPDesktop/', $ua ) ? 'Windows Phone' : 'Windows';
			$map = array( '5.1' => 'XP', '6.1' => '7', '6.2' => '8', '6.3' => '8.1', '10.0' => '10' );
			if ( preg_match( '/Windows NT ([0-9.]+)/i', $ua, $m ) ) {
				$os_version = $map[ $m[1] ] ?? '';
			}
		} elseif ( preg_match( '/(iPhone|iPad|iPod).*?OS (\d+)_(\d+)_?(\d+)?/', $ua, $m ) ) {
			$os         = 'iOS';
			$os_version = $m[2] . '.' . $m[3] . '.' . ( $m[4] ?? '' ?: '0' );
		} elseif ( $has( 'iPhone' ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/Android (\d+)\.?(\d+)?\.?(\d+)?/i', $ua, $m ) ) {
			$os         = 'Android';
			$os_version = $m[1] . '.' . ( $m[2] ?? '' ?: '0' ) . '.' . ( $m[3] ?? '' ?: '0' );
		} elseif ( preg_match( '/Android/i', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/Mac OS X (\d+)[_.](\d+)[_.]?(\d+)?/i', $ua, $m ) ) {
			$os         = 'Mac OS X';
			$os_version = $m[1] . '.' . $m[2] . '.' . ( $m[3] ?? '' ?: '0' );
		} elseif ( preg_match( '/Mac/i', $ua ) ) {
			$os = 'Mac OS X';
		} elseif ( $has( 'CrOS' ) ) {
			$os = 'Chrome OS';
		} elseif ( preg_match( '/Linux|debian/i', $ua ) ) {
			$os = 'Linux';
		}

		// Device type (posthog-js: Mobile, Tablet, Desktop).
		if ( preg_match( '/iPad|Tablet|Kindle|Silk|PlayBook/i', $ua ) || ( preg_match( '/Android/i', $ua ) && ! $has( 'Mobile' ) ) ) {
			$device_type = 'Tablet';
		} elseif ( preg_match( '/iPhone|iPod|Mobile|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile/i', $ua ) ) {
			$device_type = 'Mobile';
		} else {
			$device_type = 'Desktop';
		}
		$device = '';
		if ( $has( 'iPad' ) ) {
			$device = 'iPad';
		} elseif ( $has( 'iPhone' ) ) {
			$device = 'iPhone';
		} elseif ( preg_match( '/Android/i', $ua ) ) {
			$device = 'Android';
		}

		return array_filter(
			array(
				'$browser'         => $browser,
				'$browser_version' => $browser_version,
				'$os'              => $os,
				'$os_version'      => $os_version,
				'$device_type'     => $device_type,
				'$device'          => $device,
				'in_app_browser'   => self::in_app_browser( $ua ),
			),
			static fn( $v ) => null !== $v && '' !== $v
		);
	}

	/** Name of the app whose embedded browser sent this user agent, or ''. */
	public static function in_app_browser( string $ua ): string {
		$apps = array(
			'Instagram'  => '/\bInstagram\b/',
			'Facebook'   => '/FBAN|FBAV|FBIOS|FB_IAB|FBLC/',
			'TikTok'     => '/TikTok|musical_ly|BytedanceWebview|trill_/i',
			'Snapchat'   => '/Snapchat/i',
			'Pinterest'  => '/Pinterest/i',
			'LinkedIn'   => '/LinkedInApp/i',
			'Twitter'    => '/Twitter/i',
			'Messenger'  => '/Messenger/i',
			'Google App' => '/GSA\//',
		);
		foreach ( $apps as $name => $rx ) {
			if ( preg_match( $rx, $ua ) ) {
				return $name;
			}
		}
		if ( preg_match( '/Android.*;\s*wv\)/', $ua ) ) {
			return 'Android WebView';
		}
		return '';
	}

	/**
	 * A crawler, link preview, uptime monitor or script, by its user agent (no agent at all counts too).
	 * Specific markers only, as posthog-js's own list: a bare "bot" would also match phone models.
	 */
	public static function is_bot( ?string $ua ): bool {
		$ua = strtolower( trim( (string) $ua ) );
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '~bot[/;]|\(bot|bot\.(?:htm|php)|crawl|spider|slurp|headless|lighthouse|facebookexternal|facebookcatalog|meta-externalagent|prerender|preview|screaming frog|sitebulb|uptime|pingdom|monitor|yandex|baidu|slackbot|whatsapp|telegrambot|discordbot|semrush|ahrefs|gptbot|chatgpt|perplexity|bytespider|curl/|wget/|python-|aiohttp|go-http-client|java/|okhttp|axios/|node-fetch|scrapy|phantomjs|cypress~', $ua );
	}

	/**
	 * This request's cookies, unslashed: the plugin's only read of $_COOKIE.
	 *
	 * The values are not text-sanitized here: posthog-js and Cookiebot keep JSON in their cookies, at
	 * times URL-encoded, and sanitize_text_field() removes every %xx sequence, which would destroy them.
	 * Every value is decoded and then validated or sanitized where it is used: Identity::from_cookie()
	 * (distinct and session IDs through clean_id(), the Meta click ID through sanitize_text_field()),
	 * Consent (only fixed values are accepted) and Visitor::parse() (a UUID pattern).
	 *
	 * @return array<string, string>
	 */
	public static function request_cookies(): array {
		$cookies = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated or sanitized after decoding, where each value is used (see above).
		foreach ( wp_unslash( $_COOKIE ) as $name => $value ) {
			if ( is_string( $value ) ) {
				$cookies[ (string) $name ] = $value;
			}
		}
		return $cookies;
	}

	/** Read a cookie value from request_cookies() (PHP turns dots and spaces in cookie names into underscores). */
	public static function cookie( array $cookies, string $name ): ?string {
		$key = str_replace( array( '.', ' ' ), '_', $name );
		if ( ! isset( $cookies[ $key ] ) || ! is_string( $cookies[ $key ] ) ) {
			return null;
		}
		return $cookies[ $key ];
	}
}
