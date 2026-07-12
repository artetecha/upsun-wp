<?php

use PHPUnit\Framework\TestCase;
use Upsun\Integrations\UpdraftPlus;
use Upsun\Integrations\WpRocket;
use Upsun\Integrations\Wordfence;
use Upsun\Modules\WritablePaths;

final class WritablePathsTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	/**
	 * Declare mounts as the platform would: PLATFORM_APP_DIR set so that
	 * mount keys resolve inside WP_CONTENT_DIR (which the test bootstrap
	 * points at the system temp dir).
	 */
	private function fake_mounts( array $mount_keys ): void {
		$mounts = array();

		foreach ( $mount_keys as $key ) {
			$mounts[ $key ] = array(
				'source'      => 'storage',
				'source_path' => basename( $key ),
			);
		}

		putenv( 'PLATFORM_APP_DIR=' . rtrim( WP_CONTENT_DIR, '/' ) );
		putenv( 'PLATFORM_APPLICATION=' . base64_encode( json_encode( array( 'mounts' => $mounts ) ) ) );
		\Upsun\Environment::reset();
	}

	private function add_requirement( string $id, array $paths, $active = '__return_true', ?string $note = null ): void {
		add_filter(
			'upsun_writable_path_requirements',
			function ( array $requirements ) use ( $id, $paths, $active, $note ) {
				$requirements[ $id ] = array_filter(
					array(
						'label'  => ucfirst( $id ),
						'active' => $active,
						'paths'  => $paths,
						'note'   => $note,
					)
				);

				return $requirements;
			}
		);
	}

	/* Mount matching. */

	public function test_path_covered_by_a_declared_mount_is_writable(): void {
		$this->fake_mounts( array( 'upsun-test-wflogs' ) );

		$this->assertTrue( WritablePaths::is_path_writable( 'upsun-test-wflogs' ) );
		// Subdirectories of a mount are writable too.
		$this->assertTrue( WritablePaths::is_path_writable( 'upsun-test-wflogs/sub' ) );
	}

	public function test_unmounted_missing_path_is_not_writable(): void {
		$this->fake_mounts( array( 'upsun-test-other' ) );

		$this->assertFalse( WritablePaths::is_path_writable( 'upsun-test-does-not-exist-anywhere' ) );
	}

	public function test_existing_writable_directory_is_a_fallback(): void {
		// No mounts declared at all, but the directory exists and is
		// writable (exotic layouts, UPSUN_MU_FORCE testing).
		$dir = 'upsun-test-writable-' . getmypid();
		mkdir( rtrim( WP_CONTENT_DIR, '/' ) . '/' . $dir );

		try {
			$this->assertTrue( WritablePaths::is_path_writable( $dir ) );
		} finally {
			rmdir( rtrim( WP_CONTENT_DIR, '/' ) . '/' . $dir );
		}
	}

	/* Check verdicts. */

	public function test_check_passes_with_no_active_requirements(): void {
		$this->add_requirement( 'dormant', array( 'upsun-test-x' ), '__return_false' );

		$result = WritablePaths::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'No known plugins', $result['message'] );
	}

	public function test_check_passes_when_paths_are_mounted(): void {
		$this->fake_mounts( array( 'upsun-test-wflogs' ) );
		$this->add_requirement( 'wordfence', array( 'upsun-test-wflogs' ) );

		$result = WritablePaths::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'covered by mounts', $result['message'] );
	}

	public function test_check_warns_and_names_the_missing_path(): void {
		$this->fake_mounts( array() );
		$this->add_requirement( 'wordfence', array( 'upsun-test-wflogs' ) );

		$result = WritablePaths::check();

		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'Wordfence: wp-content/upsun-test-wflogs', $result['message'] );
		$this->assertStringContainsString( 'wp upsun mounts', $result['message'] );
	}

	public function test_notes_are_appended(): void {
		$this->fake_mounts( array( 'upsun-test-cache' ) );
		$this->add_requirement( 'rocket', array( 'upsun-test-cache' ), '__return_true', 'Drop-in note.' );

		$result = WritablePaths::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'Drop-in note.', $result['message'] );
	}

	/* Suggestions. */

	public function test_suggestions_produce_pasteable_yaml(): void {
		$this->fake_mounts( array() );
		$this->add_requirement( 'wordfence', array( 'upsun-test-wflogs' ) );

		$suggestions = WritablePaths::suggestions();

		$this->assertCount( 1, $suggestions );
		$this->assertSame( 'wp-content/upsun-test-wflogs', $suggestions[0]['path'] );
		// WP_CONTENT_DIR == app dir in this harness, so the mount key is
		// the bare relative path.
		$this->assertSame( 'upsun-test-wflogs', $suggestions[0]['mount'] );

		$yaml = WritablePaths::suggestions_yaml( $suggestions );

		$this->assertStringContainsString( '"upsun-test-wflogs":', $yaml );
		$this->assertStringContainsString( 'source: storage', $yaml );
		$this->assertStringContainsString( 'source_path: "upsun-test-wflogs"', $yaml );
	}

	public function test_unresolvable_mount_key_degrades_to_a_comment(): void {
		// No PLATFORM_APP_DIR: the mount key cannot be derived.
		$this->add_requirement( 'wordfence', array( 'upsun-test-wflogs' ) );

		$suggestions = WritablePaths::suggestions();

		$this->assertNull( $suggestions[0]['mount'] );
		$this->assertStringContainsString( '#', WritablePaths::suggestions_yaml( $suggestions ) );
	}

	/* Module wiring and the advisory integrations. */

	public function test_check_joins_the_shared_registry(): void {
		( new WritablePaths() )->register();

		$checks = \Upsun\Modules\SiteHealth::checks();

		$this->assertArrayHasKey( 'writable_paths', $checks );
	}

	public function test_enabled_filter_gates_should_load(): void {
		$this->assertTrue( ( new WritablePaths() )->should_load() );

		add_filter( 'upsun_writable_paths_enabled', '__return_false' );

		$this->assertFalse( ( new WritablePaths() )->should_load() );
	}

	public function test_advisory_integrations_contribute_requirements(): void {
		( new Wordfence() )->register();
		( new UpdraftPlus() )->register();
		( new WpRocket() )->register();

		$requirements = WritablePaths::requirements();

		$this->assertSame( array( 'wordfence', 'updraftplus', 'wp-rocket' ), array_keys( $requirements ) );
		$this->assertSame( array( 'wflogs' ), $requirements['wordfence']['paths'] );
		$this->assertSame( array( 'updraft' ), $requirements['updraftplus']['paths'] );
		$this->assertSame( array( 'cache', 'wp-rocket-config' ), $requirements['wp-rocket']['paths'] );
		$this->assertStringContainsString( 'advanced-cache.php', $requirements['wp-rocket']['note'] );

		// None of the target plugins exist in the test environment, so the
		// check reports nothing to evaluate.
		$result = WritablePaths::check();
		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'No known plugins', $result['message'] );
	}
}
