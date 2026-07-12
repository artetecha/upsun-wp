<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\PageCache;

final class PageCacheTest extends TestCase {

	/**
	 * Core defaults plus the WooCommerce integration's contribution — the
	 * effective pattern set on a booted site, matching the pre-0.3.0
	 * built-in defaults.
	 */
	private function effective_patterns(): array {
		return array_merge(
			PageCache::DEFAULT_COOKIE_PATTERNS,
			\Upsun\Integrations\WooCommerce::COOKIE_PATTERNS
		);
	}

	private function cacheable( array $overrides = array(), ?array $patterns = null ): bool {
		$context = array_merge(
			array(
				'method'       => 'GET',
				'flags'        => false,
				'cookies'      => array(),
				'sent_headers' => array(),
			),
			$overrides
		);

		return PageCache::is_cacheable_request( $context, $patterns ?? $this->effective_patterns() );
	}

	/**
	 * 0.3.0 split the single built-in regex into core defaults plus the
	 * WooCommerce integration's patterns; the combined coverage must equal
	 * the pre-split regex exactly.
	 */
	public function test_pattern_split_matches_the_pre_integration_defaults(): void {
		$pre_split_oracle = '/^(wordpress_|wp-postpass|wp_woocommerce_session_|woocommerce_|PHPSESSID|comment_author_)/';

		$samples = array(
			'wordpress_logged_in_abc',
			'wordpress_sec_abc',
			'wp-postpass_abc',
			'wp_woocommerce_session_abc',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'PHPSESSID',
			'comment_author_abc',
			'_ga',
			'consent_choice',
			'wp_settings_time',
			'harmless',
		);

		foreach ( $samples as $cookie ) {
			$matches_new = false;

			foreach ( $this->effective_patterns() as $pattern ) {
				if ( preg_match( $pattern, $cookie ) ) {
					$matches_new = true;
					break;
				}
			}

			$this->assertSame(
				1 === preg_match( $pre_split_oracle, $cookie ),
				$matches_new,
				"coverage parity for {$cookie}"
			);
		}
	}

	public function test_plain_anonymous_get_is_cacheable(): void {
		$this->assertTrue( $this->cacheable() );
	}

	public function test_non_get_methods_are_not_cacheable(): void {
		$this->assertFalse( $this->cacheable( array( 'method' => 'POST' ) ) );
		$this->assertFalse( $this->cacheable( array( 'method' => 'HEAD' ) ) );
		$this->assertFalse( $this->cacheable( array( 'method' => '' ) ) );
	}

	public function test_request_flags_block_caching(): void {
		$this->assertFalse( $this->cacheable( array( 'flags' => true ) ) );
	}

	/**
	 * @dataProvider personalisedCookieProvider
	 */
	public function test_personalised_cookies_block_caching( string $cookie ): void {
		$this->assertFalse( $this->cacheable( array( 'cookies' => array( $cookie ) ) ) );
	}

	public static function personalisedCookieProvider(): array {
		return array(
			'auth'          => array( 'wordpress_logged_in_abc123' ),
			'post password' => array( 'wp-postpass_abc' ),
			'woo session'   => array( 'wp_woocommerce_session_abc' ),
			'woo misc'      => array( 'woocommerce_items_in_cart' ),
			'php session'   => array( 'PHPSESSID' ),
			'commenter'     => array( 'comment_author_abc' ),
		);
	}

	public function test_benign_cookies_do_not_block_caching(): void {
		$this->assertTrue( $this->cacheable( array( 'cookies' => array( '_ga', 'consent_choice' ) ) ) );
	}

	public function test_custom_patterns_are_respected(): void {
		$this->assertFalse(
			$this->cacheable(
				array( 'cookies' => array( 'my_custom_session' ) ),
				array( '/^my_custom_/' )
			)
		);
	}

	public function test_prior_set_cookie_header_blocks_caching(): void {
		$this->assertFalse(
			$this->cacheable( array( 'sent_headers' => array( 'Set-Cookie: foo=bar; path=/' ) ) )
		);
	}

	public function test_prior_uncacheable_cache_control_blocks_caching(): void {
		foreach ( array( 'no-cache', 'no-store', 'private' ) as $directive ) {
			$this->assertFalse(
				$this->cacheable( array( 'sent_headers' => array( "Cache-Control: {$directive}, must-revalidate" ) ) ),
				$directive
			);
		}
	}

	public function test_prior_public_cache_control_does_not_block(): void {
		$this->assertTrue(
			$this->cacheable( array( 'sent_headers' => array( 'Cache-Control: public, max-age=300' ) ) )
		);
	}

	public function test_unrelated_headers_do_not_block(): void {
		$this->assertTrue(
			$this->cacheable( array( 'sent_headers' => array( 'X-Frame-Options: SAMEORIGIN', 'Content-Type: text/html' ) ) )
		);
	}
}
