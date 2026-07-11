<?php
/**
 * Explains whether the Upsun router would cache a URL, and why.
 *
 * Router facts this encodes (developer.upsun.com/docs/routes/cache):
 * responses carrying Set-Cookie are never cached; Cache-Control
 * private/no-cache/no-store refuses caching; with the default cookie list
 * ["*"] any request cookie bypasses the cache; responses without
 * Cache-Control fall back to the route's default_ttl (default 0); the
 * X-Platform-Cache response header reports HIT/MISS/BYPASS per fetch.
 * The per-route cache block is read from PLATFORM_ROUTES when present,
 * otherwise the documented defaults are assumed and flagged as such.
 */

namespace Upsun;

use Upsun\Modules\PageCache;

final class CacheCheck {

	private const DOCUMENTED_DEFAULTS = array(
		'enabled'     => true,
		'default_ttl' => 0,
		'cookies'     => array( '*' ),
	);

	/**
	 * Fetch a URL once (redirects not followed) and analyze the response.
	 *
	 * @param string $url           Absolute URL, or a path resolved against
	 *                              the primary route.
	 * @param string $cookie_header Optional Cookie header to send.
	 * @param string $auth          Optional "user:pass" for environments
	 *                              behind HTTP access control.
	 * @return array The analyze() report, or [ 'error' => string ].
	 */
	public static function run( string $url, string $cookie_header = '', string $auth = '' ): array {
		$resolved = self::resolve_url( $url );

		if ( null === $resolved ) {
			return array( 'error' => 'Invalid URL: pass an absolute http(s) URL, or a path starting with "/" to resolve against the primary route.' );
		}

		$args = array(
			'timeout'     => 15,
			'redirection' => 0,
			'user-agent'  => 'upsun-mu-plugin/cache-check ' . version(),
		);

		$request_headers = array();

		if ( '' !== $cookie_header ) {
			$request_headers['Cookie'] = $cookie_header;
		}

		if ( '' !== $auth ) {
			$request_headers['Authorization'] = 'Basic ' . base64_encode( $auth );
		}

		if ( array() !== $request_headers ) {
			$args['headers'] = $request_headers;
		}

		$response = wp_remote_get( $resolved, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$raw     = wp_remote_retrieve_headers( $response );
		$raw     = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		$headers = array();

		foreach ( $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = $value;
		}

		return self::analyze(
			array(
				'url'             => $resolved,
				'status'          => (int) wp_remote_retrieve_response_code( $response ),
				'headers'         => $headers,
				'cookie_header'   => $cookie_header,
				'route_cache'     => self::route_cache_config( $resolved ),
				'bypass_patterns' => (array) apply_filters( 'upsun_page_cache_bypass_cookie_patterns', PageCache::DEFAULT_COOKIE_PATTERNS ),
			)
		);
	}

	/**
	 * Pure analysis of a fetched response — separated from run() so the
	 * verdict logic is unit-testable without HTTP.
	 *
	 * @param array $input {url, status, headers (lower-cased name =>
	 *                     string|array), cookie_header, route_cache,
	 *                     bypass_patterns}.
	 * @return array {cacheable: bool, ttl: ?int, summary: string,
	 *                rows: array<array{field,value}>, notes: string[]}
	 */
	public static function analyze( array $input ): array {
		$headers  = (array) ( $input['headers'] ?? array() );
		$route    = (array) ( $input['route_cache'] ?? array() ) + self::DOCUMENTED_DEFAULTS + array( 'known' => false );
		$status   = (int) ( $input['status'] ?? 0 );
		$notes    = array();

		$cache_control    = self::header_string( $headers, 'cache-control' );
		$set_cookie_names = self::set_cookie_names( $headers );
		$request_cookies  = self::cookie_names( (string) ( $input['cookie_header'] ?? '' ) );

		$s_maxage = self::directive_seconds( $cache_control, 's-maxage' );
		$max_age  = self::directive_seconds( $cache_control, 'max-age' );
		$ttl      = $s_maxage ?? $max_age;

		preg_match( '/\b(private|no-cache|no-store)\b/i', $cache_control, $refusal );

		// Storability verdict, in the router's order of refusal.
		if ( 401 === $status ) {
			$cacheable = false;
			$final_ttl = null;
			$summary   = 'uncacheable: HTTP 401 — the fetch was rejected by access control before reaching WordPress';
			$notes[]   = 'This environment appears to be protected by HTTP auth, so the verdict describes the auth challenge, not your page. Retry with --auth=<user:pass>, or check from an environment without access control.';
		} elseif ( false === (bool) $route['enabled'] ) {
			$cacheable = false;
			$final_ttl = null;
			$summary   = 'uncacheable: the router cache is disabled for this route';
		} elseif ( array() !== $set_cookie_names ) {
			$cacheable = false;
			$final_ttl = null;
			$summary   = 'uncacheable: Set-Cookie ' . implode( ', ', $set_cookie_names );
			$notes[]   = 'The router never caches responses carrying Set-Cookie. If this cookie is expendable for anonymous traffic, add its prefix to the upsun_page_cache_strip_cookies filter.';
		} elseif ( array() !== $refusal ) {
			$cacheable = false;
			$final_ttl = null;
			$summary   = sprintf( 'uncacheable: Cache-Control contains "%s"', strtolower( $refusal[1] ) );
		} elseif ( null !== $ttl ) {
			$source    = null !== $s_maxage ? 's-maxage' : 'max-age';
			$cacheable = $ttl > 0;
			$final_ttl = $cacheable ? $ttl : null;
			$summary   = $cacheable
				? sprintf( 'cacheable for %ds (%s)', $ttl, $source )
				: sprintf( 'uncacheable: %s=0', $source );
		} elseif ( (int) $route['default_ttl'] > 0 ) {
			$cacheable = true;
			$final_ttl = (int) $route['default_ttl'];
			$summary   = sprintf( 'cacheable for %ds (route default_ttl; the app sent no Cache-Control)', $final_ttl );
		} else {
			$cacheable = false;
			$final_ttl = null;
			$summary   = 'uncacheable: no s-maxage/max-age from the app and the route default_ttl is 0';
		}

		// When the app sent no caching header, explain the likely reason.
		if ( null === $ttl && '' === $cache_control && 401 !== $status ) {
			$matches = self::bypass_matches( $request_cookies, (array) ( $input['bypass_patterns'] ?? array() ) );

			if ( array() !== $matches ) {
				foreach ( $matches as $name => $pattern ) {
					$notes[] = sprintf( 'Request cookie "%s" matches the page-cache bypass pattern %s — the module deliberately skips the caching header for personalized requests.', $name, $pattern );
				}
			} else {
				$notes[] = 'No request cookie matched a bypass pattern. If you expected a caching header, check DONOTCACHEPAGE, the upsun_page_cache_skip filter, and whether the page-cache module is enabled.';
			}
		}

		// Request-side bypass is independent of whether the response is storable.
		if ( array() !== $request_cookies ) {
			if ( in_array( '*', (array) $route['cookies'], true ) ) {
				$notes[] = sprintf( 'This request itself bypasses the shared cache: cookie(s) %s sent and the route cookie list is ["*"] (any request cookie busts the cache).', implode( ', ', $request_cookies ) );
			} else {
				$keyed = array();

				foreach ( $request_cookies as $name ) {
					if ( self::cookie_in_list( $name, (array) $route['cookies'] ) ) {
						$keyed[] = $name;
					}
				}

				$notes[] = array() !== $keyed
					? sprintf( 'Cookie(s) %s are part of the route cache key — each value gets its own cache entry.', implode( ', ', $keyed ) )
					: 'The cookies sent are not in the route cookie list, so they do not affect the cache key.';
			}
		}

		if ( $status >= 300 && $status < 400 ) {
			$notes[] = 'This is a redirect response (redirects were not followed); the verdict applies to the redirect itself, not its target.';
		}

		if ( empty( $route['known'] ) ) {
			$notes[] = 'Upsun does not expose route cache settings at runtime — documented defaults assumed (enabled, default_ttl 0, cookies ["*"]). Declare your route\'s real settings via the upsun_cache_check_route_cache filter to make cookie notes exact.';
		}

		$platform_cache = self::header_string( $headers, 'x-platform-cache' );
		$age            = self::header_string( $headers, 'age' );

		$rows = array(
			array( 'field' => 'url', 'value' => (string) ( $input['url'] ?? '' ) ),
			array( 'field' => 'status', 'value' => (string) $status ),
			array( 'field' => 'cache-control', 'value' => '' !== $cache_control ? $cache_control : '(none)' ),
			array( 'field' => 'set-cookie', 'value' => array() !== $set_cookie_names ? implode( ', ', $set_cookie_names ) : '(none)' ),
			array( 'field' => 'request cookies', 'value' => array() !== $request_cookies ? implode( ', ', $request_cookies ) : '(none)' ),
			array(
				'field' => 'route cache',
				'value' => sprintf(
					'%s, default_ttl=%d, cookies=[%s]%s',
					$route['enabled'] ? 'enabled' : 'disabled',
					(int) $route['default_ttl'],
					implode( ', ', array_map( 'strval', (array) $route['cookies'] ) ),
					empty( $route['known'] ) ? ' (assumed)' : ''
				),
			),
			array( 'field' => 'this fetch', 'value' => '' !== $platform_cache ? $platform_cache . ( '' !== $age ? ', age ' . $age : '' ) : '(no X-Platform-Cache header)' ),
		);

		return array(
			'cacheable' => $cacheable,
			'ttl'       => $final_ttl,
			'summary'   => $summary,
			'rows'      => $rows,
			'notes'     => $notes,
		);
	}

	/**
	 * Resolve a path against the primary route; pass absolute URLs through.
	 * Returns null for anything else (scheme-less hosts, other protocols).
	 */
	public static function resolve_url( string $url ): ?string {
		$url = trim( $url );

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		if ( '' !== $url && '/' === $url[0] ) {
			$primary = Environment::primary_route();

			return null === $primary ? null : rtrim( $primary, '/' ) . $url;
		}

		return null;
	}

	/**
	 * Whether the URL's host belongs to one of this environment's routes.
	 * The dashboard form only checks the environment's own URLs.
	 */
	public static function is_environment_url( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		foreach ( array_keys( Environment::routes() ) as $route_url ) {
			if ( strtolower( (string) parse_url( (string) $route_url, PHP_URL_HOST ) ) === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The cache config of the longest route matching the URL, from
	 * PLATFORM_ROUTES. Falls back to documented defaults (known=false)
	 * when the route or its cache block is not visible.
	 *
	 * @return array{enabled: bool, default_ttl: int, cookies: array, known: bool}
	 */
	public static function route_cache_config( string $url ): array {
		$best       = null;
		$best_length = -1;

		foreach ( Environment::routes() as $route_url => $route ) {
			if ( ! is_array( $route ) || 'upstream' !== ( $route['type'] ?? '' ) ) {
				continue;
			}

			$prefix = rtrim( (string) $route_url, '/' );

			if ( ( $url === $prefix || 0 === strpos( $url, $prefix . '/' ) || 0 === strpos( $url, $prefix . '?' ) )
				&& strlen( $prefix ) > $best_length ) {
				$best        = $route;
				$best_length = strlen( $prefix );
			}
		}

		if ( null === $best || ! is_array( $best['cache'] ?? null ) ) {
			$config = self::DOCUMENTED_DEFAULTS + array( 'known' => false );
		} else {
			$cache  = $best['cache'];
			$config = array(
				'enabled'     => (bool) ( $cache['enabled'] ?? true ),
				'default_ttl' => (int) ( $cache['default_ttl'] ?? 0 ),
				'cookies'     => (array) ( $cache['cookies'] ?? array( '*' ) ),
				'known'       => true,
			);
		}

		/**
		 * Filters the route cache config used by cache-check. Upsun does not
		 * expose the routes' cache blocks at runtime, so consumers can
		 * mirror their .upsun/config.yaml here (set known=true) to make the
		 * cookie-allowlist notes exact.
		 *
		 * @param array  $config {enabled, default_ttl, cookies, known}.
		 * @param string $url    The URL being checked.
		 */
		$config = (array) apply_filters( 'upsun_cache_check_route_cache', $config, $url );

		return $config + self::DOCUMENTED_DEFAULTS + array( 'known' => false );
	}

	/**
	 * Whether a cookie name is covered by a route cookie list, which mixes
	 * literal names and slash-delimited regexes (plus the "*" wildcard).
	 */
	private static function cookie_in_list( string $name, array $entries ): bool {
		foreach ( $entries as $entry ) {
			$entry = (string) $entry;

			if ( '*' === $entry || $entry === $name ) {
				return true;
			}

			if ( '' !== $entry && '/' === $entry[0] && 1 === @preg_match( $entry, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array $cookies  Request cookie names.
	 * @param array $patterns Bypass regex patterns.
	 * @return array<string, string> cookie name => pattern that matched.
	 */
	private static function bypass_matches( array $cookies, array $patterns ): array {
		$matches = array();

		foreach ( $cookies as $name ) {
			foreach ( $patterns as $pattern ) {
				if ( is_string( $pattern ) && 1 === @preg_match( $pattern, $name ) ) {
					$matches[ $name ] = $pattern;
					break;
				}
			}
		}

		return $matches;
	}

	/**
	 * Seconds of a Cache-Control directive, or null when absent. The
	 * leading-boundary guard keeps "max-age" from matching inside
	 * "s-maxage".
	 */
	private static function directive_seconds( string $cache_control, string $directive ): ?int {
		if ( preg_match( '/(?:^|[,\s])' . preg_quote( $directive, '/' ) . '\s*=\s*(\d+)/i', $cache_control, $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	private static function header_string( array $headers, string $name ): string {
		$value = $headers[ $name ] ?? '';

		return trim( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value );
	}

	/**
	 * Cookie names from Set-Cookie header value(s).
	 *
	 * @return string[]
	 */
	private static function set_cookie_names( array $headers ): array {
		$value = $headers['set-cookie'] ?? array();
		$names = array();

		foreach ( is_array( $value ) ? $value : array( $value ) as $cookie ) {
			$name = trim( strtok( (string) $cookie, '=' ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Cookie names from a Cookie request header ("a=1; b=2").
	 *
	 * @return string[]
	 */
	private static function cookie_names( string $cookie_header ): array {
		$names = array();

		foreach ( explode( ';', $cookie_header ) as $pair ) {
			$name = trim( strtok( trim( $pair ), '=' ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}
}
