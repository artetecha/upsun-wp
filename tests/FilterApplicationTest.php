<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\PageCache;
use Upsun\Modules\PreviewProtection;

/**
 * The settings filters that reporting surfaces also read are applied in
 * exactly one place, behind an @internal accessor. Before 0.7 they were
 * re-applied at each call site (page-cache patterns in three), so a consumer
 * callback ran up to three times per request and each site restated the
 * default.
 */
final class FilterApplicationTest extends TestCase {

	/**
	 * Filter name => the accessor that owns its single application point.
	 */
	private const CENTRALIZED = array(
		'upsun_page_cache_ttl'                    => 'PageCache::ttl',
		'upsun_page_cache_bypass_cookie_patterns' => 'PageCache::bypass_patterns',
		'upsun_page_cache_strip_cookies'          => 'PageCache::stripped_cookies',
		'upsun_preview_noindex'                   => 'PreviewProtection::noindex',
	);

	protected function setUp(): void {
		upsun_test_reset_hooks();
	}

	protected function tearDown(): void {
		upsun_test_reset_hooks();
	}

	/**
	 * Structural guard: re-introducing a second apply_filters() call for any
	 * of these names fails here, even if behaviour looks unchanged.
	 */
	public function test_each_centralized_filter_has_one_application_point(): void {
		$sources = '';

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' )
		);

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$sources .= file_get_contents( $file->getPathname() );
			}
		}

		foreach ( array_keys( self::CENTRALIZED ) as $filter ) {
			// Matches the multi-line apply_filters( \n 'name', ... ) form too.
			$found = preg_match_all(
				'/apply_filters(?:_ref_array|_deprecated)?\s*\(\s*\'' . preg_quote( $filter, '/' ) . '\'/',
				$sources
			);

			$this->assertSame(
				1,
				$found,
				sprintf(
					'%s must be applied exactly once (by %s); found %d applications in src/.',
					$filter,
					self::CENTRALIZED[ $filter ],
					$found
				)
			);
		}
	}

	public function test_accessors_return_the_documented_defaults(): void {
		$this->assertSame( PageCache::DEFAULT_TTL, PageCache::ttl() );
		$this->assertSame( PageCache::DEFAULT_COOKIE_PATTERNS, PageCache::bypass_patterns() );
		$this->assertSame( array(), PageCache::stripped_cookies() );
		$this->assertTrue( PreviewProtection::noindex() );
	}

	public function test_accessors_reflect_consumer_overrides(): void {
		add_filter( 'upsun_page_cache_ttl', static fn () => 120 );
		add_filter( 'upsun_page_cache_bypass_cookie_patterns', static fn ( $p ) => array_merge( $p, array( '/^lms_/' ) ) );
		add_filter( 'upsun_page_cache_strip_cookies', static fn () => array( 'guest_session_' ) );
		add_filter( 'upsun_preview_noindex', '__return_false' );

		$this->assertSame( 120, PageCache::ttl() );
		$this->assertContains( '/^lms_/', PageCache::bypass_patterns() );
		$this->assertSame( array( 'guest_session_' ), PageCache::stripped_cookies() );
		$this->assertFalse( PreviewProtection::noindex() );
	}

	/**
	 * The dashboard's caching panel and `wp upsun cache-check` report what
	 * PageCache itself would use, because they call the same accessor.
	 */
	public function test_a_consumer_callback_runs_once_per_read(): void {
		$calls = 0;

		add_filter(
			'upsun_page_cache_ttl',
			static function ( $ttl ) use ( &$calls ) {
				++$calls;
				return $ttl;
			}
		);

		PageCache::ttl();

		$this->assertSame( 1, $calls );
	}

	/**
	 * stripped_cookies() is the one accessor that normalizes: prefixes are
	 * cast to string, empties dropped, keys reindexed. Reporting surfaces
	 * used to apply their own (differing) normalization.
	 */
	public function test_stripped_cookies_normalizes_consumer_input(): void {
		add_filter(
			'upsun_page_cache_strip_cookies',
			static fn () => array( 5 => 'keep_', 'drop' => '', 9 => 'also_keep_' )
		);

		$this->assertSame( array( 'keep_', 'also_keep_' ), PageCache::stripped_cookies() );
	}

	/**
	 * '0' is a legal cookie prefix. The old dashboard normalization used a
	 * callback-less array_filter(), which would have dropped it.
	 */
	public function test_stripped_cookies_keeps_a_zero_string_prefix(): void {
		add_filter( 'upsun_page_cache_strip_cookies', static fn () => array( '0' ) );

		$this->assertSame( array( '0' ), PageCache::stripped_cookies() );
	}
}
