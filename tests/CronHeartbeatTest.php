<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\CronHeartbeat;

final class CronHeartbeatTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
		$GLOBALS['upsun_test_cron']    = array();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
		$GLOBALS['upsun_test_cron']    = array();
	}

	public function test_schedule_registers_the_event_once(): void {
		$module = new CronHeartbeat();

		$module->schedule();
		$first = $GLOBALS['upsun_test_cron'][ CronHeartbeat::HOOK ] ?? null;
		$this->assertNotNull( $first );

		$module->schedule(); // Already scheduled: no reschedule.
		$this->assertSame( $first, $GLOBALS['upsun_test_cron'][ CronHeartbeat::HOOK ] );
	}

	public function test_beat_stamps_the_option(): void {
		( new CronHeartbeat() )->beat();

		$stamp = $GLOBALS['upsun_test_options'][ CronHeartbeat::OPTION ] ?? 0;
		$this->assertEqualsWithDelta( time(), $stamp, 2 );
	}

	public function test_check_warns_before_first_heartbeat(): void {
		$GLOBALS['upsun_test_options'][ CronHeartbeat::OPTION ] = 0;

		$result = CronHeartbeat::check();

		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'No cron heartbeat recorded yet', $result['message'] );
	}

	public function test_check_passes_on_fresh_heartbeat(): void {
		$GLOBALS['upsun_test_options'][ CronHeartbeat::OPTION ] = time() - 60;

		$result = CronHeartbeat::check();

		$this->assertSame( 'pass', $result['status'] );
	}

	public function test_check_warns_when_stale(): void {
		// Older than 2x but under 4x the hourly interval.
		$GLOBALS['upsun_test_options'][ CronHeartbeat::OPTION ] = time() - 3 * 3600;

		$result = CronHeartbeat::check();

		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_check_fails_when_very_stale(): void {
		$GLOBALS['upsun_test_options'][ CronHeartbeat::OPTION ] = time() - 5 * 3600;

		$result = CronHeartbeat::check();

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'wp cron event run --due-now', $result['message'] );
	}

	public function test_interval_follows_the_schedule_filter(): void {
		add_filter(
			'upsun_cron_heartbeat_schedule',
			function () {
				return 'daily';
			}
		);

		$this->assertSame( 86400, CronHeartbeat::interval() );
	}

	public function test_unknown_schedule_falls_back_to_hourly(): void {
		add_filter(
			'upsun_cron_heartbeat_schedule',
			function () {
				return 'every_nanosecond';
			}
		);

		$this->assertSame( 'hourly', CronHeartbeat::schedule_name() );
		$this->assertSame( 3600, CronHeartbeat::interval() );
	}

	public function test_check_joins_the_shared_registry(): void {
		( new CronHeartbeat() )->register();

		$checks = \Upsun\Modules\SiteHealth::checks();

		$this->assertArrayHasKey( 'cron_heartbeat', $checks );
		$this->assertIsCallable( $checks['cron_heartbeat']['callback'] );
	}

	public function test_enabled_filter_gates_should_load(): void {
		$this->assertTrue( ( new CronHeartbeat() )->should_load() );

		add_filter( 'upsun_cron_heartbeat_enabled', '__return_false' );

		$this->assertFalse( ( new CronHeartbeat() )->should_load() );
	}
}
