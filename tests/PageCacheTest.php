<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\PageCache;

final class PageCacheTest extends TestCase {

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

		return PageCache::is_cacheable_request( $context, $patterns ?? PageCache::DEFAULT_COOKIE_PATTERNS );
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
