<?php
/**
 * Ordered, once-per-database deploy migrations.
 *
 * Consumers point UPSUN_MIGRATIONS_DIR (or the upsun_migrations_dir filter)
 * at a directory of PHP files named YYYYMMDD_NNNN_short_name.php; each file
 * returns a callable that performs one migration. `wp upsun migrate` runs
 * the pending ones in filename order from the deploy hook, records each
 * success in a non-autoloaded option, and exits non-zero on the first
 * failure so the deploy aborts before traffic.
 *
 * Completion tracking lives in the database on purpose: a preview cloned
 * from production carries the applied markers together with the already-
 * migrated data, so nothing re-runs; an environment holding older data
 * runs exactly what that data is missing.
 */

namespace Upsun;

final class Migrations {

	public const OPTION_PREFIX = 'upsun_migration_';

	/** Filenames must sort chronologically and unambiguously. */
	private const ID_PATTERN = '/^[0-9]{8}_[0-9]{4}_[a-z0-9_]+$/';

	/**
	 * The migrations directory, or null when the feature is not configured.
	 */
	public static function directory(): ?string {
		$default = defined( 'UPSUN_MIGRATIONS_DIR' ) ? (string) UPSUN_MIGRATIONS_DIR : null;

		/**
		 * Filters the migrations directory.
		 *
		 * @param string|null $dir Default: the UPSUN_MIGRATIONS_DIR
		 *                         constant, or null (feature idle).
		 */
		$dir = apply_filters( 'upsun_migrations_dir', $default );

		return is_string( $dir ) && '' !== $dir ? $dir : null;
	}

	/**
	 * Every migration in the directory, in execution order.
	 *
	 * @return array<int, array{id: string, file: string, state: string, applied_at: string}>
	 *               state: applied | pending | invalid.
	 */
	public static function status(): array {
		$dir = self::directory();

		if ( null === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( rtrim( $dir, '/' ) . '/*.php' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		sort( $files, SORT_STRING );

		$rows = array();

		foreach ( $files as $file ) {
			$id = basename( $file, '.php' );

			if ( ! preg_match( self::ID_PATTERN, $id ) ) {
				$rows[] = array(
					'id'         => $id,
					'file'       => $file,
					'state'      => 'invalid',
					'applied_at' => '',
				);
				continue;
			}

			$applied_at = get_option( self::OPTION_PREFIX . $id, null );

			$rows[] = array(
				'id'         => $id,
				'file'       => $file,
				'state'      => is_string( $applied_at ) ? 'applied' : 'pending',
				'applied_at' => is_string( $applied_at ) ? $applied_at : '',
			);
		}

		return $rows;
	}

	/**
	 * @return string[] Ids of migrations with invalid filenames.
	 */
	public static function invalid(): array {
		return array_column(
			array_filter( self::status(), static fn ( array $row ) => 'invalid' === $row['state'] ),
			'id'
		);
	}

	/**
	 * @return array<int, array{id: string, file: string}> Pending migrations in order.
	 */
	public static function pending(): array {
		return array_values(
			array_filter( self::status(), static fn ( array $row ) => 'pending' === $row['state'] )
		);
	}

	/**
	 * Apply pending migrations in order, stopping at the first failure.
	 *
	 * Each file must return a callable; a throwable or a strict false
	 * return marks the migration failed, nothing is recorded for it, and
	 * later migrations do not run.
	 *
	 * @return array{applied: string[], error: ?string}
	 */
	public static function run(): array {
		$applied = array();

		foreach ( self::pending() as $migration ) {
			$callable = include $migration['file'];

			if ( ! is_callable( $callable ) ) {
				return array(
					'applied' => $applied,
					'error'   => sprintf( 'Migration %s did not return a callable.', $migration['id'] ),
				);
			}

			try {
				$result = call_user_func( $callable );
			} catch ( \Throwable $exception ) {
				return array(
					'applied' => $applied,
					'error'   => sprintf( 'Migration %s failed: %s', $migration['id'], $exception->getMessage() ),
				);
			}

			if ( false === $result ) {
				return array(
					'applied' => $applied,
					'error'   => sprintf( 'Migration %s failed (returned false).', $migration['id'] ),
				);
			}

			update_option( self::OPTION_PREFIX . $migration['id'], gmdate( 'c' ), false );
			$applied[] = $migration['id'];
		}

		return array(
			'applied' => $applied,
			'error'   => null,
		);
	}

	/**
	 * Shared health check: pending or misnamed migrations are a deploy
	 * concern worth surfacing everywhere.
	 *
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		if ( null === self::directory() ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'No migrations directory configured (UPSUN_MIGRATIONS_DIR / upsun_migrations_dir).', 'upsun-mu-plugin' ),
			);
		}

		$invalid = self::invalid();

		if ( array() !== $invalid ) {
			return array(
				'status'  => 'fail',
				'message' => sprintf(
					/* translators: %s: list of filenames. */
					__( 'Migration filename(s) not matching YYYYMMDD_NNNN_short_name.php: %s.', 'upsun-mu-plugin' ),
					implode( ', ', $invalid )
				),
			);
		}

		$pending = self::pending();

		if ( array() !== $pending ) {
			return array(
				'status'  => 'warn',
				'message' => sprintf(
					/* translators: 1: number of migrations, 2: list of ids. */
					__( '%1$d migration(s) pending: %2$s. Run "wp upsun migrate" (normally the deploy hook does).', 'upsun-mu-plugin' ),
					count( $pending ),
					implode( ', ', array_column( $pending, 'id' ) )
				),
			);
		}

		return array(
			'status'  => 'pass',
			'message' => sprintf(
				/* translators: %d: number of migrations. */
				__( 'All %d migration(s) applied.', 'upsun-mu-plugin' ),
				count( self::status() )
			),
		);
	}
}
