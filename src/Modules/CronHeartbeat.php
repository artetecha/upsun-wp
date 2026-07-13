<?php
/**
 * Proof that WP-Cron actually executes, not just that it is configured.
 *
 * Schedules a recurring `upsun_cron_heartbeat` event whose only job is to
 * stamp a timestamp option. If the platform cron (wp cron event run
 * --due-now) is running, the stamp stays fresh; a stale stamp means cron is
 * silently broken. The check is injected into the shared registry, so Site
 * Health, `wp upsun doctor`, and the dashboard Health panel all report it.
 */

namespace Upsun\Modules;

use Upsun\Module;

class CronHeartbeat implements Module {

	public const OPTION = 'upsun_cron_heartbeat';

	public const HOOK = 'upsun_cron_heartbeat';

	private const DEFAULT_INTERVAL = 3600; // The 'hourly' schedule.

	public function should_load(): bool {
		/**
		 * Filters whether the cron heartbeat is scheduled and checked.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_cron_heartbeat_enabled', true );
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'beat' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_filter( 'upsun_site_health_tests', array( $this, 'add_check' ) );
	}

	public function schedule(): void {
		// A CLI boot against a fresh database (e.g. `wp core is-installed`
		// during the first deploy) fires init before any tables exist;
		// writing the cron array there only produces DB-error noise.
		if ( function_exists( 'is_blog_installed' ) && ! is_blog_installed() ) {
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::schedule_name(), self::HOOK );
		}
	}

	public function beat(): void {
		update_option( self::OPTION, time(), false );
	}

	public function add_check( array $checks ): array {
		$checks['cron_heartbeat'] = array(
			'label'    => __( 'Cron execution', 'upsun-mu-plugin' ),
			'callback' => array( self::class, 'check' ),
		);

		return $checks;
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		$interval = self::interval();
		$last     = (int) get_option( self::OPTION, 0 );

		if ( 0 === $last ) {
			return array(
				'status'  => 'warn',
				'message' => __( 'No cron heartbeat recorded yet — expected within the first schedule interval after deploy. If this persists, cron events are not being executed.', 'upsun-mu-plugin' ),
			);
		}

		$age = max( 0, time() - $last );

		if ( $age > 4 * $interval ) {
			return array(
				'status'  => 'fail',
				'message' => sprintf(
					/* translators: %s: human-readable time difference. */
					__( 'The last cron heartbeat was %s ago — cron events are not being executed. Check the Upsun cron running "wp cron event run --due-now".', 'upsun-mu-plugin' ),
					self::age_label( $last )
				),
			);
		}

		if ( $age > 2 * $interval ) {
			return array(
				'status'  => 'warn',
				'message' => sprintf(
					/* translators: %s: human-readable time difference. */
					__( 'The last cron heartbeat was %s ago — cron may be running late or intermittently.', 'upsun-mu-plugin' ),
					self::age_label( $last )
				),
			);
		}

		$message = sprintf(
			/* translators: %s: human-readable time difference. */
			__( 'Cron is executing; last heartbeat %s ago.', 'upsun-mu-plugin' ),
			self::age_label( $last )
		);

		$overdue = self::overdue_event_count();

		if ( $overdue > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of events. */
				__( 'Note: %d event(s) are overdue by more than 15 minutes.', 'upsun-mu-plugin' ),
				$overdue
			);

			return array(
				'status'  => 'warn',
				'message' => $message,
			);
		}

		return array(
			'status'  => 'pass',
			'message' => $message,
		);
	}

	/**
	 * The heartbeat schedule, validated against registered schedules.
	 */
	public static function schedule_name(): string {
		/**
		 * Filters the WP-Cron schedule used for the heartbeat event.
		 *
		 * @param string $schedule Default 'hourly'.
		 */
		$schedule = (string) apply_filters( 'upsun_cron_heartbeat_schedule', 'hourly' );

		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();

		return isset( $schedules[ $schedule ] ) ? $schedule : 'hourly';
	}

	public static function interval(): int {
		$schedules = function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array();
		$interval  = (int) ( $schedules[ self::schedule_name() ]['interval'] ?? self::DEFAULT_INTERVAL );

		return $interval > 0 ? $interval : self::DEFAULT_INTERVAL;
	}

	/**
	 * Events past due by more than 15 minutes — a secondary signal that the
	 * cron cadence is not keeping up with the workload.
	 */
	private static function overdue_event_count(): int {
		if ( ! function_exists( 'wp_get_ready_cron_jobs' ) ) {
			return 0;
		}

		$threshold = time() - 15 * 60;
		$overdue   = 0;

		foreach ( (array) wp_get_ready_cron_jobs() as $timestamp => $hooks ) {
			if ( (int) $timestamp > $threshold ) {
				continue;
			}

			foreach ( (array) $hooks as $events ) {
				$overdue += is_array( $events ) ? count( $events ) : 1;
			}
		}

		return $overdue;
	}

	private static function age_label( int $timestamp ): string {
		if ( function_exists( 'human_time_diff' ) ) {
			return human_time_diff( $timestamp, time() );
		}

		return sprintf( '%ds', max( 0, time() - $timestamp ) );
	}
}
