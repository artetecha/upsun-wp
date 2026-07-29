<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\Cloudflare;

/**
 * Upsun\purge_paths() is the facade the roadmap held open until 1.0: consumer
 * code asks for paths to be invalidated without knowing which cache is in
 * front. The contract worth pinning is that it never claims success it did not
 * have — the Upsun router has no purge API, so "nothing available" is a normal,
 * reportable outcome rather than an error.
 */
final class PurgeTest extends TestCase {

	protected function setUp(): void {
		upsun_test_reset_hooks();
		upsun_test_clear_env();
	}

	protected function tearDown(): void {
		upsun_test_reset_hooks();
		upsun_test_clear_env();
	}

	private function on_platform(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		putenv(
			'PLATFORM_ROUTES=' . base64_encode(
				wp_json_encode(
					array(
						'https://site.test/' => array( 'primary' => true, 'type' => 'upstream' ),
					)
				)
			)
		);
		\Upsun\Environment::reset();
	}

	/* ---- No backend: honest, not silent ------------------------------- */

	/**
	 * An absolute URL, so resolution succeeds and the answer is genuinely
	 * about there being no backend rather than nothing to purge.
	 */
	public function test_without_a_backend_it_reports_the_platform_limitation(): void {
		$result = Upsun\purge_paths( array( 'https://site.test/about/' ) );

		$this->assertFalse( $result['purged'] );
		$this->assertNull( $result['backend'] );
		$this->assertStringContainsString( 'no purge API', $result['message'] );
	}

	public function test_a_declining_backend_is_not_reported_as_success(): void {
		add_filter(
			'upsun_purge_backends',
			static fn ( array $b ) => $b + array( 'nope' => static fn ( array $urls ) => false )
		);

		$result = Upsun\purge_paths();

		$this->assertFalse( $result['purged'] );
		$this->assertStringContainsString( 'declined', $result['message'] );
	}

	/* ---- Dispatch ------------------------------------------------------ */

	public function test_a_backend_receives_resolved_urls_and_wins(): void {
		$this->on_platform();

		$seen = null;

		add_filter(
			'upsun_purge_backends',
			static function ( array $b ) use ( &$seen ) {
				return $b + array(
					'fixture' => static function ( array $urls ) use ( &$seen ) {
						$seen = $urls;
						return true;
					},
				);
			}
		);

		$result = Upsun\purge_paths( array( '/about/', 'contact/' ) );

		$this->assertTrue( $result['purged'] );
		$this->assertSame( 'fixture', $result['backend'] );
		$this->assertSame( array( 'https://site.test/about/', 'https://site.test/contact/' ), $seen );
		$this->assertStringContainsString( '2 URL(s)', $result['message'] );
	}

	public function test_absolute_urls_pass_through_untouched(): void {
		$seen = null;

		add_filter(
			'upsun_purge_backends',
			static function ( array $b ) use ( &$seen ) {
				return $b + array(
					'fixture' => static function ( array $urls ) use ( &$seen ) {
						$seen = $urls;
						return true;
					},
				);
			}
		);

		Upsun\purge_paths( array( 'https://cdn.test/x.css' ) );

		$this->assertSame( array( 'https://cdn.test/x.css' ), $seen );
	}

	public function test_an_empty_list_means_purge_everything(): void {
		$seen = 'unset';

		add_filter(
			'upsun_purge_backends',
			static function ( array $b ) use ( &$seen ) {
				return $b + array(
					'fixture' => static function ( array $urls ) use ( &$seen ) {
						$seen = $urls;
						return true;
					},
				);
			}
		);

		$result = Upsun\purge_paths();

		$this->assertSame( array(), $seen );
		$this->assertTrue( $result['purged'] );
		$this->assertStringContainsString( 'everything', $result['message'] );
	}

	/**
	 * Declining passes the request along, so a consumer can register a fallback
	 * behind Cloudflare.
	 */
	public function test_the_first_backend_to_accept_wins_and_later_ones_do_not_run(): void {
		$ran = array();

		add_filter(
			'upsun_purge_backends',
			static function ( array $b ) use ( &$ran ) {
				return $b + array(
					'declines' => static function ( array $urls ) use ( &$ran ) {
						$ran[] = 'declines';
						return false;
					},
					'accepts'  => static function ( array $urls ) use ( &$ran ) {
						$ran[] = 'accepts';
						return true;
					},
					'never'    => static function ( array $urls ) use ( &$ran ) {
						$ran[] = 'never';
						return true;
					},
				);
			}
		);

		$result = Upsun\purge_paths();

		$this->assertSame( array( 'declines', 'accepts' ), $ran );
		$this->assertSame( 'accepts', $result['backend'] );
	}

	public function test_a_backend_error_is_surfaced_not_swallowed(): void {
		add_filter(
			'upsun_purge_backends',
			static fn ( array $b ) => $b + array(
				'broken' => static fn ( array $urls ) => new UpsunTestWpError( 'zone is wrong' ),
			)
		);

		$result = Upsun\purge_paths();

		$this->assertFalse( $result['purged'] );
		$this->assertSame( 'broken', $result['backend'] );
		$this->assertSame( 'zone is wrong', $result['message'] );
	}

	/* ---- A throwing backend ------------------------------------------- */

	/**
	 * A backend is consumer code talking to a third-party API, so it may throw
	 * where its author meant to return false. That must not take down the
	 * caller — typically a post-publish hook — nor skip the fallback the
	 * dispatch chain exists to allow.
	 */
	public function test_a_throwing_backend_does_not_escape_and_the_chain_continues(): void {
		add_filter(
			'upsun_purge_backends',
			static fn ( array $b ) => $b + array(
				'throws'   => static function ( array $urls ) {
					throw new RuntimeException( 'connection reset' );
				},
				'fallback' => static fn ( array $urls ) => true,
			)
		);

		$result = Upsun\purge_paths();

		$this->assertTrue( $result['purged'] );
		$this->assertSame( 'fallback', $result['backend'] );
	}

	public function test_a_throwing_backend_is_reported_when_nothing_else_succeeds(): void {
		add_filter(
			'upsun_purge_backends',
			static fn ( array $b ) => $b + array(
				'throws' => static function ( array $urls ) {
					throw new RuntimeException( 'connection reset' );
				},
			)
		);

		$result = Upsun\purge_paths();

		$this->assertFalse( $result['purged'] );
		$this->assertStringContainsString( 'throws purge backend errored', $result['message'] );
		$this->assertStringContainsString( 'connection reset', $result['message'] );
	}

	/* ---- The message names what is actually there ---------------------- */

	/**
	 * The facade is backend-agnostic, so a consumer running only Fastly must
	 * not be told to configure a CDN they do not use.
	 */
	public function test_the_message_does_not_mention_cloudflare_for_other_backends(): void {
		add_filter(
			'upsun_purge_backends',
			static fn ( array $b ) => $b + array( 'fastly' => static fn ( array $urls ) => false )
		);

		$message = Upsun\purge_paths()['message'];

		$this->assertStringContainsString( 'fastly', $message );
		$this->assertStringNotContainsString( 'CLOUDFLARE', $message );
		$this->assertStringContainsString( 'no purge API', $message );
	}

	public function test_the_message_names_cloudflare_credentials_when_it_declined(): void {
		( new Cloudflare() )->register();

		$message = Upsun\purge_paths()['message'];

		$this->assertStringContainsString( 'cloudflare', $message );
		$this->assertStringContainsString( 'CLOUDFLARE_ZONE_ID', $message );
	}

	public function test_non_callable_entries_are_ignored(): void {
		add_filter( 'upsun_purge_backends', static fn ( array $b ) => $b + array( 'junk' => 'not-callable' ) );

		$this->assertFalse( Upsun\purge_paths()['purged'] );
	}

	/* ---- Path resolution ---------------------------------------------- */

	public function test_paths_are_unresolvable_without_a_primary_route(): void {
		add_filter(
			'upsun_purge_backends',
			static fn ( array $b ) => $b + array( 'fixture' => static fn ( array $urls ) => true )
		);

		$result = Upsun\purge_paths( array( '/about/' ) );

		$this->assertFalse( $result['purged'], 'a relative path off-platform has nothing to resolve against' );
		$this->assertStringContainsString( 'No purgeable URL', $result['message'] );
	}

	public function test_duplicate_and_empty_paths_are_dropped(): void {
		$this->on_platform();

		$seen = null;

		add_filter(
			'upsun_purge_backends',
			static function ( array $b ) use ( &$seen ) {
				return $b + array(
					'fixture' => static function ( array $urls ) use ( &$seen ) {
						$seen = $urls;
						return true;
					},
				);
			}
		);

		Upsun\purge_paths( array( '/a/', '', '  ', '/a/' ) );

		$this->assertSame( array( 'https://site.test/a/' ), $seen );
	}

	/* ---- The Cloudflare backend --------------------------------------- */

	public function test_cloudflare_registers_a_backend_when_the_module_boots(): void {
		( new Cloudflare() )->register();

		$this->assertArrayHasKey( 'cloudflare', Upsun\Purge::backends() );
	}

	/**
	 * Without credentials it must decline rather than fail, so a site that is
	 * not fronted by Cloudflare gets "nothing available" instead of a
	 * misleading "Cloudflare failed".
	 */
	public function test_the_cloudflare_backend_declines_without_credentials(): void {
		( new Cloudflare() )->register();

		$result = Upsun\purge_paths( array( 'https://site.test/a/' ) );

		$this->assertFalse( $result['purged'] );
		$this->assertNull( $result['backend'], 'declining must not name Cloudflare as the failing backend' );
		$this->assertStringContainsString( 'declined', $result['message'] );
	}

	public function test_the_cloudflare_backend_purges_when_configured(): void {
		add_filter( 'upsun_cloudflare_zone_id', static fn () => 'zone123' );
		add_filter( 'upsun_cloudflare_api_token', static fn () => 'token123' );

		upsun_test_http_reset( array( array( 'code' => 200, 'body' => wp_json_encode( array( 'success' => true ) ) ) ) );

		( new Cloudflare() )->register();

		$result = Upsun\purge_paths( array( 'https://site.test/a/' ) );

		$this->assertTrue( $result['purged'] );
		$this->assertSame( 'cloudflare', $result['backend'] );

		$request = $GLOBALS['upsun_test_http']['requests'][0];

		$this->assertStringContainsString( 'zones/zone123/purge_cache', $request['url'] );
		$this->assertSame( 'Bearer token123', $request['args']['headers']['Authorization'] );
		// Decoded rather than string-matched: json_encode escapes the slashes.
		$this->assertSame(
			array( 'files' => array( 'https://site.test/a/' ) ),
			json_decode( $request['args']['body'], true )
		);
	}
}
