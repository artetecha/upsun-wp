<?php

use PHPUnit\Framework\TestCase;
use Upsun\Migrations;

final class MigrationsTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
		$GLOBALS['upsun_migration_log'] = array();

		$this->dir = sys_get_temp_dir() . '/upsun-migrations-' . getmypid() . '-' . uniqid();
		mkdir( $this->dir );

		add_filter( 'upsun_migrations_dir', fn () => $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}

		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}

		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
		unset( $GLOBALS['upsun_migration_log'] );
	}

	private function write_migration( string $id, string $body ): void {
		file_put_contents( $this->dir . '/' . $id . '.php', "<?php\nreturn {$body};\n" );
	}

	private function write_logger( string $id, string $marker ): void {
		$this->write_migration(
			$id,
			"static function () { \$GLOBALS['upsun_migration_log'][] = '{$marker}'; }"
		);
	}

	public function test_unconfigured_directory_means_idle(): void {
		upsun_test_reset_hooks(); // Drop the setUp filter.

		$this->assertNull( Migrations::directory() );
		$this->assertSame( array(), Migrations::status() );
		$this->assertSame( 'pass', Migrations::check()['status'] );
	}

	public function test_migrations_apply_in_filename_order_and_are_recorded(): void {
		$this->write_logger( '20260102_0001_second', 'second' );
		$this->write_logger( '20260101_0001_first', 'first' );

		$result = Migrations::run();

		$this->assertNull( $result['error'] );
		$this->assertSame( array( '20260101_0001_first', '20260102_0001_second' ), $result['applied'] );
		$this->assertSame( array( 'first', 'second' ), $GLOBALS['upsun_migration_log'] );
		$this->assertArrayHasKey( Migrations::OPTION_PREFIX . '20260101_0001_first', $GLOBALS['upsun_test_options'] );
	}

	public function test_applied_migrations_never_rerun(): void {
		$this->write_logger( '20260101_0001_once', 'ran' );
		$GLOBALS['upsun_test_options'][ Migrations::OPTION_PREFIX . '20260101_0001_once' ] = '2026-07-01T00:00:00+00:00';

		$result = Migrations::run();

		$this->assertSame( array(), $result['applied'] );
		$this->assertSame( array(), $GLOBALS['upsun_migration_log'] );
		$this->assertSame( 'applied', Migrations::status()[0]['state'] );
	}

	public function test_failure_stops_the_run_and_records_nothing_for_it(): void {
		$this->write_migration( '20260101_0001_boom', "static function () { throw new RuntimeException( 'db exploded' ); }" );
		$this->write_logger( '20260102_0001_after', 'must-not-run' );

		$result = Migrations::run();

		$this->assertSame( array(), $result['applied'] );
		$this->assertStringContainsString( '20260101_0001_boom failed: db exploded', $result['error'] );
		$this->assertSame( array(), $GLOBALS['upsun_migration_log'] );
		$this->assertArrayNotHasKey( Migrations::OPTION_PREFIX . '20260101_0001_boom', $GLOBALS['upsun_test_options'] );
	}

	public function test_returning_false_is_a_failure(): void {
		$this->write_migration( '20260101_0001_nope', 'static function () { return false; }' );

		$result = Migrations::run();

		$this->assertStringContainsString( 'returned false', $result['error'] );
	}

	public function test_not_returning_a_callable_is_a_failure(): void {
		file_put_contents( $this->dir . '/20260101_0001_plain.php', "<?php // side effects only\n" );

		$result = Migrations::run();

		$this->assertStringContainsString( 'did not return a callable', $result['error'] );
	}

	public function test_invalid_filenames_are_flagged(): void {
		$this->write_logger( 'not-a-valid-name', 'nope' );
		$this->write_logger( '20260101_0001_fine', 'fine' );

		$this->assertSame( array( 'not-a-valid-name' ), Migrations::invalid() );
		$this->assertSame( 'fail', Migrations::check()['status'] );
	}

	public function test_check_warns_on_pending_and_passes_when_applied(): void {
		$this->write_logger( '20260101_0001_thing', 'x' );

		$check = Migrations::check();
		$this->assertSame( 'warn', $check['status'] );
		$this->assertStringContainsString( '20260101_0001_thing', $check['message'] );

		Migrations::run();

		$check = Migrations::check();
		$this->assertSame( 'pass', $check['status'] );
		$this->assertStringContainsString( 'All 1 migration(s) applied', $check['message'] );
	}

	public function test_check_joins_the_shared_registry(): void {
		$this->assertArrayHasKey( 'migrations', \Upsun\Modules\SiteHealth::checks() );
	}
}
