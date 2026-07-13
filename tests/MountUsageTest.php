<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\MountUsage;

final class MountUsageTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
		$GLOBALS['upsun_test_cron']    = array();

		$this->dir = sys_get_temp_dir() . '/upsun-mounts-' . getmypid() . '-' . uniqid();
		mkdir( $this->dir . '/uploads/sub', 0777, true );
		mkdir( $this->dir . '/cache' );
	}

	protected function tearDown(): void {
		foreach ( array( '/uploads/sub/b.bin', '/uploads/a.bin', '/cache/c.bin' ) as $file ) {
			if ( file_exists( $this->dir . $file ) ) {
				unlink( $this->dir . $file );
			}
		}

		foreach ( array( '/uploads/sub', '/uploads', '/cache', '' ) as $sub ) {
			if ( is_dir( $this->dir . $sub ) ) {
				rmdir( $this->dir . $sub );
			}
		}

		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
		$GLOBALS['upsun_test_cron']    = array();
	}

	private function fake_mounts(): void {
		putenv( 'PLATFORM_APP_DIR=' . $this->dir );
		putenv(
			'PLATFORM_APPLICATION=' . base64_encode(
				json_encode(
					array(
						'mounts' => array(
							'uploads' => array( 'source' => 'storage', 'source_path' => 'uploads' ),
							'cache'   => array( 'source' => 'storage', 'source_path' => 'cache' ),
						),
					)
				)
			)
		);
		\Upsun\Environment::reset();
	}

	/* Pure verdict and report. */

	public function test_verdict_thresholds(): void {
		$total = 1000;

		$this->assertSame( 'pass', MountUsage::verdict( $total, 500 ) );  // 50% used.
		$this->assertSame( 'warn', MountUsage::verdict( $total, 150 ) );  // 85% used.
		$this->assertSame( 'fail', MountUsage::verdict( $total, 30 ) );   // 97% used.
		$this->assertSame( 'warn', MountUsage::verdict( 0, 0 ) );         // Unreadable.
	}

	public function test_verdict_thresholds_are_filterable(): void {
		add_filter(
			'upsun_disk_usage_thresholds',
			static fn () => array( 40, 60 )
		);

		$this->assertSame( 'warn', MountUsage::verdict( 1000, 500 ) ); // 50% used.
		$this->assertSame( 'fail', MountUsage::verdict( 1000, 300 ) ); // 70% used.
	}

	public function test_report_is_human_readable(): void {
		$report = MountUsage::report( 10 * 1024 * 1024 * 1024, 4 * 1024 * 1024 * 1024 );

		$this->assertSame( 'Disk 60% used (6.0G of 10G, 4.0G free).', $report );
	}

	/* Measurement. */

	public function test_measure_stores_per_mount_sizes(): void {
		$this->fake_mounts();
		file_put_contents( $this->dir . '/uploads/a.bin', str_repeat( 'x', 1000 ) );
		file_put_contents( $this->dir . '/uploads/sub/b.bin', str_repeat( 'x', 500 ) );
		file_put_contents( $this->dir . '/cache/c.bin', str_repeat( 'x', 250 ) );

		( new MountUsage() )->measure();

		$stored = $GLOBALS['upsun_test_options'][ MountUsage::OPTION ];

		$this->assertSame( 1500, $stored['mounts']['uploads'] );
		$this->assertSame( 250, $stored['mounts']['cache'] );
		$this->assertEqualsWithDelta( time(), $stored['time'], 2 );
		$this->assertIsInt( $stored['disk']['total'] );
	}

	public function test_directory_size_of_missing_path_is_zero(): void {
		$this->assertSame( 0, MountUsage::directory_size( $this->dir . '/does-not-exist' ) );
	}

	public function test_disk_space_null_without_mounts(): void {
		$this->assertNull( MountUsage::disk_space() );
	}

	/* Wiring. */

	public function test_schedule_registers_the_daily_event_once(): void {
		$module = new MountUsage();

		$module->schedule();
		$first = $GLOBALS['upsun_test_cron'][ MountUsage::HOOK ] ?? null;
		$this->assertNotNull( $first );

		$module->schedule();
		$this->assertSame( $first, $GLOBALS['upsun_test_cron'][ MountUsage::HOOK ] );
	}

	public function test_schedule_noops_before_wordpress_is_installed(): void {
		$GLOBALS['upsun_test_blog_installed'] = false;

		( new MountUsage() )->schedule();

		$this->assertArrayNotHasKey( MountUsage::HOOK, $GLOBALS['upsun_test_cron'] );
		$GLOBALS['upsun_test_blog_installed'] = true;
	}

	public function test_register_joins_the_panel_registry(): void {
		( new MountUsage() )->register();

		$panels = apply_filters( 'upsun_dashboard_panels', array() );

		$this->assertArrayHasKey( 'mount-usage', $panels );
		$this->assertSame( 'column3', $panels['mount-usage']['context'] );
	}

	public function test_check_returns_a_result_shape(): void {
		$this->fake_mounts();

		$result = MountUsage::check();

		$this->assertContains( $result['status'], array( 'pass', 'warn', 'fail' ) );
		$this->assertStringContainsString( 'Disk', $result['message'] );
	}

	public function test_check_appends_the_cached_breakdown(): void {
		$this->fake_mounts();
		$GLOBALS['upsun_test_options'][ MountUsage::OPTION ] = array(
			'time'   => time() - 3600,
			'disk'   => array( 'total' => 1, 'free' => 1 ),
			'mounts' => array( 'uploads' => 1500 ),
		);

		$result = MountUsage::check();

		$this->assertStringContainsString( 'uploads 1.5K', $result['message'] );
		$this->assertStringContainsString( 'ago', $result['message'] );
	}

	public function test_enabled_filter_gates_should_load(): void {
		$this->assertTrue( ( new MountUsage() )->should_load() );

		add_filter( 'upsun_mount_usage_enabled', '__return_false' );

		$this->assertFalse( ( new MountUsage() )->should_load() );
	}
}
