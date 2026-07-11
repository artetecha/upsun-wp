<?php

use PHPUnit\Framework\TestCase;
use Upsun\CacheCheck;

final class CacheCheckTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	private function fake_routes( array $routes ): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		putenv( 'PLATFORM_ROUTES=' . base64_encode( json_encode( $routes ) ) );
		\Upsun\Environment::reset();
	}

	private function analyze( array $overrides ): array {
		return CacheCheck::analyze(
			$overrides + array(
				'url'             => 'https://example.com/',
				'status'          => 200,
				'headers'         => array(),
				'cookie_header'   => '',
				'route_cache'     => array( 'enabled' => true, 'default_ttl' => 0, 'cookies' => array( '*' ), 'known' => true ),
				'bypass_patterns' => \Upsun\Modules\PageCache::DEFAULT_COOKIE_PATTERNS,
			)
		);
	}

	/* Verdicts. */

	public function test_s_maxage_is_cacheable(): void {
		$report = $this->analyze( array( 'headers' => array( 'cache-control' => 'public, max-age=0, s-maxage=600' ) ) );

		$this->assertTrue( $report['cacheable'] );
		$this->assertSame( 600, $report['ttl'] );
		$this->assertSame( 'cacheable for 600s (s-maxage)', $report['summary'] );
	}

	public function test_max_age_is_the_fallback_ttl(): void {
		$report = $this->analyze( array( 'headers' => array( 'cache-control' => 'public, max-age=300' ) ) );

		$this->assertTrue( $report['cacheable'] );
		$this->assertSame( 'cacheable for 300s (max-age)', $report['summary'] );
	}

	public function test_set_cookie_refuses_caching(): void {
		$report = $this->analyze(
			array(
				'headers' => array(
					'cache-control' => 'public, s-maxage=600',
					'set-cookie'    => array( 'lp_session_guest=abc; Path=/', 'foo=1' ),
				),
			)
		);

		$this->assertFalse( $report['cacheable'] );
		$this->assertSame( 'uncacheable: Set-Cookie lp_session_guest, foo', $report['summary'] );
		$this->assertStringContainsString( 'upsun_page_cache_strip_cookies', implode( ' ', $report['notes'] ) );
	}

	public function test_private_cache_control_refuses_caching(): void {
		$report = $this->analyze( array( 'headers' => array( 'cache-control' => 'no-cache, must-revalidate' ) ) );

		$this->assertFalse( $report['cacheable'] );
		$this->assertSame( 'uncacheable: Cache-Control contains "no-cache"', $report['summary'] );
	}

	public function test_zero_s_maxage_is_uncacheable(): void {
		$report = $this->analyze( array( 'headers' => array( 'cache-control' => 'public, s-maxage=0' ) ) );

		$this->assertFalse( $report['cacheable'] );
		$this->assertSame( 'uncacheable: s-maxage=0', $report['summary'] );
	}

	public function test_missing_cache_control_falls_back_to_route_default_ttl(): void {
		$report = $this->analyze(
			array( 'route_cache' => array( 'enabled' => true, 'default_ttl' => 120, 'cookies' => array( '*' ), 'known' => true ) )
		);

		$this->assertTrue( $report['cacheable'] );
		$this->assertSame( 120, $report['ttl'] );
		$this->assertStringContainsString( 'route default_ttl', $report['summary'] );
	}

	public function test_missing_cache_control_and_zero_default_ttl_is_uncacheable(): void {
		$report = $this->analyze( array() );

		$this->assertFalse( $report['cacheable'] );
		$this->assertStringContainsString( 'default_ttl is 0', $report['summary'] );
	}

	public function test_disabled_route_cache_wins_over_everything(): void {
		$report = $this->analyze(
			array(
				'headers'     => array( 'cache-control' => 'public, s-maxage=600' ),
				'route_cache' => array( 'enabled' => false, 'default_ttl' => 0, 'cookies' => array( '*' ), 'known' => true ),
			)
		);

		$this->assertFalse( $report['cacheable'] );
		$this->assertStringContainsString( 'router cache is disabled', $report['summary'] );
	}

	/* Notes. */

	public function test_bypass_pattern_match_is_named(): void {
		$report = $this->analyze( array( 'cookie_header' => 'wordpress_logged_in_abc=1' ) );

		$notes = implode( ' ', $report['notes'] );
		$this->assertStringContainsString( 'wordpress_logged_in_abc', $notes );
		$this->assertStringContainsString( 'bypass pattern', $notes );
	}

	public function test_unmatched_cookies_point_at_other_suppressors(): void {
		$report = $this->analyze( array( 'cookie_header' => 'harmless=1' ) );

		$this->assertStringContainsString( 'DONOTCACHEPAGE', implode( ' ', $report['notes'] ) );
	}

	public function test_any_cookie_busts_the_cache_with_star_allowlist(): void {
		$report = $this->analyze(
			array(
				'headers'       => array( 'cache-control' => 'public, s-maxage=600' ),
				'cookie_header' => 'harmless=1',
			)
		);

		$this->assertTrue( $report['cacheable'] ); // Storable for anonymous traffic...
		$this->assertStringContainsString( 'bypasses the shared cache', implode( ' ', $report['notes'] ) ); // ...but this request is not.
	}

	public function test_unknown_route_config_is_flagged_as_assumed(): void {
		$report = $this->analyze( array( 'route_cache' => array( 'known' => false ) ) );

		$this->assertStringContainsString( 'documented defaults assumed', implode( ' ', $report['notes'] ) );
	}

	public function test_redirects_are_annotated(): void {
		$report = $this->analyze( array( 'status' => 301, 'headers' => array( 'location' => 'https://example.com/x' ) ) );

		$this->assertStringContainsString( 'redirect', implode( ' ', $report['notes'] ) );
	}

	public function test_platform_cache_header_is_reported(): void {
		$report = $this->analyze(
			array( 'headers' => array( 'cache-control' => 'public, s-maxage=600', 'x-platform-cache' => 'HIT', 'age' => '42' ) )
		);

		$fetch = null;
		foreach ( $report['rows'] as $row ) {
			if ( 'this fetch' === $row['field'] ) {
				$fetch = $row['value'];
			}
		}

		$this->assertSame( 'HIT, age 42', $fetch );
	}

	public function test_401_names_access_control_not_the_page(): void {
		$report = $this->analyze( array( 'status' => 401, 'headers' => array( 'www-authenticate' => 'Basic realm="preview"' ) ) );

		$this->assertFalse( $report['cacheable'] );
		$this->assertStringContainsString( 'HTTP 401', $report['summary'] );
		$this->assertStringContainsString( '--auth', implode( ' ', $report['notes'] ) );
		// The misleading "check DONOTCACHEPAGE" hint is suppressed on 401s.
		$this->assertStringNotContainsString( 'DONOTCACHEPAGE', implode( ' ', $report['notes'] ) );
	}

	public function test_regex_entries_in_the_route_cookie_list_are_honored(): void {
		$route = array(
			'enabled'     => true,
			'default_ttl' => 0,
			'cookies'     => array( '/^wordpress_logged_in_/', 'PHPSESSID' ),
			'known'       => true,
		);

		$keyed = $this->analyze(
			array(
				'headers'       => array( 'cache-control' => 'public, s-maxage=600' ),
				'cookie_header' => 'wordpress_logged_in_abc=1',
				'route_cache'   => $route,
			)
		);
		$this->assertStringContainsString( 'part of the route cache key', implode( ' ', $keyed['notes'] ) );

		$harmless = $this->analyze(
			array(
				'headers'       => array( 'cache-control' => 'public, s-maxage=600' ),
				'cookie_header' => 'harmless=1',
				'route_cache'   => $route,
			)
		);
		$this->assertStringContainsString( 'do not affect the cache key', implode( ' ', $harmless['notes'] ) );
	}

	public function test_route_cache_filter_lets_consumers_declare_config(): void {
		$this->fake_routes(
			array( 'https://example.com/' => array( 'type' => 'upstream', 'primary' => true ) )
		);

		add_filter(
			'upsun_cache_check_route_cache',
			function ( array $config ) {
				return array(
					'enabled'     => true,
					'default_ttl' => 0,
					'cookies'     => array( '/^wordpress_logged_in_/' ),
					'known'       => true,
				);
			}
		);

		$config = CacheCheck::route_cache_config( 'https://example.com/page' );

		$this->assertTrue( $config['known'] );
		$this->assertSame( array( '/^wordpress_logged_in_/' ), $config['cookies'] );
	}

	/* URL resolution and route config. */

	public function test_paths_resolve_against_the_primary_route(): void {
		$this->fake_routes(
			array(
				'https://example.com/' => array( 'type' => 'upstream', 'primary' => true ),
			)
		);

		$this->assertSame( 'https://example.com/courses/', CacheCheck::resolve_url( '/courses/' ) );
		$this->assertSame( 'https://other.test/x', CacheCheck::resolve_url( 'https://other.test/x' ) );
		$this->assertNull( CacheCheck::resolve_url( 'example.com/no-scheme' ) );
	}

	public function test_route_cache_config_matches_the_longest_route(): void {
		$this->fake_routes(
			array(
				'https://example.com/'     => array(
					'type'  => 'upstream',
					'cache' => array( 'enabled' => true, 'default_ttl' => 0, 'cookies' => array( '*' ) ),
				),
				'https://example.com/api/' => array(
					'type'  => 'upstream',
					'cache' => array( 'enabled' => false, 'default_ttl' => 0, 'cookies' => array() ),
				),
			)
		);

		$root = CacheCheck::route_cache_config( 'https://example.com/page' );
		$this->assertTrue( $root['enabled'] );
		$this->assertTrue( $root['known'] );

		$api = CacheCheck::route_cache_config( 'https://example.com/api/v1' );
		$this->assertFalse( $api['enabled'] );
	}

	public function test_route_without_cache_block_falls_back_to_assumed_defaults(): void {
		$this->fake_routes(
			array( 'https://example.com/' => array( 'type' => 'upstream' ) )
		);

		$config = CacheCheck::route_cache_config( 'https://example.com/page' );

		$this->assertFalse( $config['known'] );
		$this->assertSame( array( '*' ), $config['cookies'] );
	}

	public function test_is_environment_url_checks_route_hosts(): void {
		$this->fake_routes(
			array( 'https://example.com/' => array( 'type' => 'upstream', 'primary' => true ) )
		);

		$this->assertTrue( CacheCheck::is_environment_url( 'https://example.com/deep/page?x=1' ) );
		$this->assertFalse( CacheCheck::is_environment_url( 'https://evil.test/' ) );
	}
}
