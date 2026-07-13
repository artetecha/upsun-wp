<?php
/**
 * Disk and mount usage visibility: full mounts are a rude way to discover
 * a quota.
 *
 * Two costs, two cadences: the shared disk's total/free comes from
 * statvfs (disk_total_space/disk_free_space on a mount path — effectively
 * free), so the health check reads it live on every run. The per-mount
 * breakdown needs a directory walk (expensive on big uploads trees), so a
 * daily WP-Cron event computes it into an option and every surface shows
 * the cached figures with their age. Mounts share one disk on Upsun, so
 * the breakdown explains the headline number rather than adding to it.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;
use Upsun\RelationshipHealth;

class MountUsage implements Module {

	/** Cached per-mount measurements: [ time, disk: {total, free}, mounts: {mount => bytes} ]. */
	public const OPTION = 'upsun_mount_usage';

	public const HOOK = 'upsun_mount_usage_measure';

	public function should_load(): bool {
		/**
		 * Filters whether mount-usage measurement and reporting is active.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_mount_usage_enabled', true );
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'measure' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_filter( 'upsun_dashboard_panels', array( $this, 'add_panel' ) );
	}

	public function schedule(): void {
		// A CLI boot against a fresh database (e.g. `wp core is-installed`
		// during the first deploy) fires init before any tables exist;
		// writing the cron array there only produces DB-error noise.
		if ( function_exists( 'is_blog_installed' ) && ! is_blog_installed() ) {
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Walk each declared mount and store its size (cron only — expensive).
	 */
	public function measure(): void {
		$mounts = array();

		foreach ( self::mount_paths() as $mount => $path ) {
			$mounts[ $mount ] = self::directory_size( $path );
		}

		update_option(
			self::OPTION,
			array(
				'time'   => time(),
				'disk'   => self::disk_space(),
				'mounts' => $mounts,
			),
			false
		);
	}

	/**
	 * Total/free bytes of the shared disk backing the mounts, from statvfs
	 * on the first mount that exists. Null when unreadable (no mounts, or
	 * off-platform layouts).
	 *
	 * @return array{total: int, free: int}|null
	 */
	public static function disk_space(): ?array {
		foreach ( self::mount_paths() as $path ) {
			$total = @disk_total_space( $path );
			$free  = @disk_free_space( $path );

			if ( false !== $total && false !== $free && $total > 0 ) {
				return array(
					'total' => (int) $total,
					'free'  => (int) $free,
				);
			}
		}

		return null;
	}

	/**
	 * The shared health check: live statvfs verdict plus the cached
	 * per-mount breakdown when available.
	 *
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		$space = self::disk_space();

		if ( null === $space ) {
			return array(
				'status'  => 'warn',
				'message' => __( 'Could not read disk usage (no readable mounts found).', 'upsun-mu-plugin' ),
			);
		}

		$verdict = self::verdict( $space['total'], $space['free'] );
		$message = self::report( $space['total'], $space['free'] );

		$cached = get_option( self::OPTION, null );

		if ( is_array( $cached ) && ! empty( $cached['mounts'] ) && is_array( $cached['mounts'] ) ) {
			$parts = array();

			foreach ( $cached['mounts'] as $mount => $bytes ) {
				$parts[] = sprintf( '%s %s', (string) $mount, RelationshipHealth::human_bytes( (int) $bytes ) );
			}

			$message .= sprintf(
				/* translators: 1: per-mount sizes, 2: human-readable age. */
				__( ' Per-mount: %1$s (measured %2$s ago).', 'upsun-mu-plugin' ),
				implode( ', ', $parts ),
				human_time_diff( (int) ( $cached['time'] ?? 0 ), time() )
			);
		}

		return array(
			'status'  => $verdict,
			'message' => $message,
		);
	}

	/**
	 * Pure verdict from disk numbers: warn at 80% used, fail at 95%
	 * (thresholds filterable).
	 */
	public static function verdict( int $total, int $free ): string {
		if ( $total <= 0 ) {
			return 'warn';
		}

		/**
		 * Filters the disk-usage thresholds as used-percent integers.
		 *
		 * @param array{0: int, 1: int} $thresholds Default [ 80, 95 ] (warn, fail).
		 */
		$thresholds = (array) apply_filters( 'upsun_disk_usage_thresholds', array( 80, 95 ) );
		$warn       = (int) ( $thresholds[0] ?? 80 );
		$fail       = (int) ( $thresholds[1] ?? 95 );
		$used_pct   = 100 * ( $total - $free ) / $total;

		if ( $used_pct >= $fail ) {
			return 'fail';
		}

		return $used_pct >= $warn ? 'warn' : 'pass';
	}

	/**
	 * Pure headline message from disk numbers.
	 */
	public static function report( int $total, int $free ): string {
		$used = max( 0, $total - $free );

		return sprintf(
			/* translators: 1: used percent, 2: used bytes, 3: total bytes, 4: free bytes. */
			__( 'Disk %1$d%% used (%2$s of %3$s, %4$s free).', 'upsun-mu-plugin' ),
			$total > 0 ? (int) round( 100 * $used / $total ) : 0,
			RelationshipHealth::human_bytes( $used ),
			RelationshipHealth::human_bytes( $total ),
			RelationshipHealth::human_bytes( $free )
		);
	}

	/* ---------------------------------------------------------------------
	 * Dashboard panel.
	 * ------------------------------------------------------------------- */

	public function add_panel( array $panels ): array {
		$panels['mount-usage'] = array(
			'title'   => __( 'Disk & mounts', 'upsun-mu-plugin' ),
			'render'  => array( $this, 'render_panel' ),
			'context' => 'column3',
		);

		return $panels;
	}

	public function render_panel(): void {
		$space = self::disk_space();

		if ( null === $space ) {
			echo '<p>' . esc_html__( 'Could not read disk usage (no readable mounts found).', 'upsun-mu-plugin' ) . '</p>';

			return;
		}

		printf( '<p>%s</p>', esc_html( self::report( $space['total'], $space['free'] ) ) );

		$cached = get_option( self::OPTION, null );

		if ( ! is_array( $cached ) || empty( $cached['mounts'] ) || ! is_array( $cached['mounts'] ) ) {
			echo '<p>' . esc_html__( 'Per-mount breakdown not measured yet (computed daily via WP-Cron).', 'upsun-mu-plugin' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><tbody>';

		foreach ( $cached['mounts'] as $mount => $bytes ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( (string) $mount ),
				esc_html( RelationshipHealth::human_bytes( (int) $bytes ) )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: human-readable time difference. */
					__( 'Measured %s ago. Mounts share one disk, so these explain the headline number.', 'upsun-mu-plugin' ),
					human_time_diff( (int) ( $cached['time'] ?? 0 ), time() )
				)
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals.
	 * ------------------------------------------------------------------- */

	/**
	 * Declared mounts resolved to existing absolute directories.
	 *
	 * @return array<string, string> mount key => absolute path.
	 */
	public static function mount_paths(): array {
		$app_dir = Environment::app_dir();

		if ( null === $app_dir || '' === $app_dir ) {
			return array();
		}

		$paths = array();

		foreach ( array_keys( Environment::mounts() ) as $mount ) {
			$path = rtrim( $app_dir, '/' ) . '/' . trim( (string) $mount, '/' );

			if ( is_dir( $path ) ) {
				$paths[ (string) $mount ] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Recursive file-size sum. Cron-only: linear in file count.
	 */
	public static function directory_size( string $path ): int {
		$bytes = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += (int) $file->getSize();
				}
			}
		} catch ( \Throwable $exception ) {
			// Unreadable subtree: report what we could sum.
		}

		return $bytes;
	}
}
