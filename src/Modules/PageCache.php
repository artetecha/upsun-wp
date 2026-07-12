<?php
/**
 * Router-cache friendliness for anonymous traffic.
 *
 * The Upsun router only caches responses without Set-Cookie, keyed on the
 * cookie allowlist in the route configuration. This module:
 *
 * 1. Emits `Cache-Control: public, max-age=0, s-maxage={ttl}` on anonymous,
 *    session-free page views so the router can serve repeat hits without PHP.
 *    max-age=0 keeps browsers revalidating; only the shared cache holds on.
 * 2. Optionally strips configured Set-Cookie headers (e.g. an LMS guest
 *    session cookie) just before sending, so those responses stay cacheable.
 */

namespace Upsun\Modules;

use Upsun\Module;

class PageCache implements Module {

	public const DEFAULT_TTL = 600;

	/**
	 * Core/session patterns only. Third-party patterns (e.g. WooCommerce's
	 * session/cart cookies) are contributed by their Integrations classes
	 * through the upsun_page_cache_bypass_cookie_patterns filter.
	 */
	public const DEFAULT_COOKIE_PATTERNS = array(
		'/^(wordpress_|wp-postpass|PHPSESSID|comment_author_)/',
	);

	public function should_load(): bool {
		return true;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_send_cache_headers' ), 99 );
		add_action( 'send_headers', array( $this, 'expire_stripped_cookies' ), 999 );

		if ( function_exists( 'header_register_callback' ) ) {
			header_register_callback( array( $this, 'strip_cookie_headers' ) );
		}
	}

	/**
	 * Pure cacheability decision over request/response state. Split out so
	 * it is unit-testable without WordPress.
	 *
	 * @param array $context {
	 *     @type string   $method       HTTP request method.
	 *     @type bool     $flags        Any of AJAX/CRON/REST/DONOTCACHEPAGE set.
	 *     @type string[] $cookies      Request cookie names.
	 *     @type string[] $sent_headers Headers already queued for the response.
	 * }
	 * @param string[] $cookie_patterns Regexes matched against cookie names.
	 */
	public static function is_cacheable_request( array $context, array $cookie_patterns ): bool {
		if ( 'GET' !== ( $context['method'] ?? '' ) ) {
			return false;
		}

		if ( ! empty( $context['flags'] ) ) {
			return false;
		}

		// Any auth/session/commerce cookie means a personalised response.
		foreach ( $context['cookies'] ?? array() as $name ) {
			foreach ( $cookie_patterns as $pattern ) {
				if ( preg_match( $pattern, (string) $name ) ) {
					return false;
				}
			}
		}

		// If another component already declared this response uncacheable or
		// is establishing a session, respect that instead of overriding it.
		foreach ( $context['sent_headers'] ?? array() as $sent ) {
			if ( 0 === stripos( $sent, 'set-cookie:' ) ) {
				return false;
			}

			if ( 0 === stripos( $sent, 'cache-control:' ) && preg_match( '/no-cache|no-store|private/i', $sent ) ) {
				return false;
			}
		}

		return true;
	}

	public function maybe_send_cache_headers(): void {
		if ( headers_sent() || is_admin() || is_user_logged_in() ) {
			return;
		}

		if ( is_preview() || is_customize_preview() || post_password_required() ) {
			return;
		}

		$flags = ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			// Set by e.g. WooCommerce on cart, checkout, account pages.
			|| ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );

		/**
		 * Filters the cookie-name regexes that mark a request personalised.
		 *
		 * @param string[] $patterns
		 */
		$patterns = (array) apply_filters( 'upsun_page_cache_bypass_cookie_patterns', self::DEFAULT_COOKIE_PATTERNS );

		$cacheable = self::is_cacheable_request(
			array(
				'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
				'flags'        => $flags,
				'cookies'      => array_keys( $_COOKIE ),
				'sent_headers' => headers_list(),
			),
			$patterns
		);

		if ( ! $cacheable ) {
			return;
		}

		/**
		 * Filters whether to skip cache headers for this request. Use for
		 * plugin-specific dynamic pages (LMS checkout/profile, etc.);
		 * built-in Integrations contribute here too (e.g. WooCommerce
		 * cart/checkout/account pages).
		 *
		 * @param bool $skip Default false.
		 */
		if ( apply_filters( 'upsun_page_cache_skip', false ) ) {
			return;
		}

		/**
		 * Filters the shared-cache TTL in seconds. Zero or less disables
		 * the Cache-Control header entirely.
		 *
		 * @param int $ttl Default 600.
		 */
		$ttl = (int) apply_filters( 'upsun_page_cache_ttl', self::DEFAULT_TTL );

		if ( $ttl <= 0 ) {
			return;
		}

		header( sprintf( 'Cache-Control: public, max-age=0, s-maxage=%d', $ttl ) );

		if ( apply_filters( 'upsun_page_cache_debug_headers', false ) ) {
			header( 'X-Upsun-MU: page-cache' );
		}
	}

	/**
	 * Best-effort expiry of configured cookies already held by the client,
	 * and removal from $_COOKIE so later code doesn't treat the request as
	 * personalised. Runs late on send_headers, after the cookie was set.
	 */
	public function expire_stripped_cookies(): void {
		if ( $this->is_personalised_context() ) {
			return;
		}

		$prefixes = $this->strip_prefixes();

		if ( ! $prefixes ) {
			return;
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 !== strpos( (string) $name, $prefix ) ) {
					continue;
				}

				$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
				$domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

				setcookie( (string) $name, '', time() - YEAR_IN_SECONDS, $path, $domain, is_ssl(), true );
				unset( $_COOKIE[ $name ] );
				break;
			}
		}
	}

	/**
	 * Strip configured Set-Cookie headers just before they are sent, so the
	 * response stays cacheable at the router. Registered via
	 * header_register_callback; preserves duplicate headers.
	 */
	public function strip_cookie_headers(): void {
		if ( $this->is_personalised_context() ) {
			return;
		}

		$prefixes = $this->strip_prefixes();

		if ( ! $prefixes ) {
			return;
		}

		$headers = headers_list();
		$cleaned = array();

		foreach ( $headers as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) ) {
				$cookie_name = trim( strtok( substr( $header, strlen( 'Set-Cookie:' ) ), '=' ) );

				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $cookie_name, $prefix ) ) {
						continue 2; // Drop this header.
					}
				}
			}

			$cleaned[] = $header;
		}

		if ( count( $cleaned ) < count( $headers ) ) {
			header_remove();

			foreach ( $cleaned as $header ) {
				header( $header, false );
			}
		}
	}

	private function is_personalised_context(): bool {
		return is_admin() || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() );
	}

	/**
	 * @return string[] Cookie-name prefixes to strip from responses.
	 */
	private function strip_prefixes(): array {
		/**
		 * Filters the cookie-name prefixes whose Set-Cookie headers are
		 * stripped from anonymous responses. Empty by default.
		 *
		 * @param string[] $prefixes
		 */
		$prefixes = (array) apply_filters( 'upsun_page_cache_strip_cookies', array() );

		return array_values( array_filter( array_map( 'strval', $prefixes ), 'strlen' ) );
	}
}
