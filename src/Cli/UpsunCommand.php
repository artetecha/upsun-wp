<?php

namespace Upsun\Cli;

use Upsun\Environment;
use Upsun\Modules\SafePreviews;
use Upsun\Modules\SiteHealth;
use WP_CLI;

/**
 * Inspect the Upsun environment WordPress is running on.
 */
class UpsunCommand {

	/**
	 * Shows Upsun environment information.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp upsun info
	 *     wp upsun info --format=json
	 */
	public function info( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		$fields = array(
			'project'        => Environment::project(),
			'environment'    => Environment::name(),
			'type'           => Environment::type(),
			'branch'         => Environment::branch(),
			'application'    => Environment::application_name(),
			'primary_route'  => Environment::primary_route(),
			'plugin_version' => \Upsun\version(),
			'php_version'    => PHP_VERSION,
		);

		$rows = array();

		foreach ( $fields as $field => $value ) {
			$rows[] = array(
				'field' => $field,
				'value' => $value ?? '',
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Runs the Upsun health checks (same registry as Site Health).
	 *
	 * Exits non-zero if any check fails, so it can gate deploy hooks.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp upsun doctor
	 */
	public function doctor( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		$rows   = array();
		$failed = false;

		foreach ( SiteHealth::checks() as $id => $check ) {
			$result = call_user_func( $check['callback'] );
			$rows[] = array(
				'check'   => $id,
				'status'  => $result['status'],
				'message' => $result['message'],
			);

			if ( 'fail' === $result['status'] ) {
				$failed = true;
			}
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'check', 'status', 'message' ) );

		if ( $failed ) {
			WP_CLI::error( 'One or more Upsun checks failed.' );
		}
	}

	/**
	 * Lists Upsun relationships (credentials are never printed).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp upsun relationships
	 */
	public function relationships( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		$rows = array();

		foreach ( Environment::relationships() as $name => $instances ) {
			if ( ! is_array( $instances ) ) {
				continue;
			}

			foreach ( $instances as $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}

				$rows[] = array(
					'relationship' => (string) $name,
					'scheme'       => (string) ( $instance['scheme'] ?? '' ),
					'host'         => (string) ( $instance['host'] ?? '' ),
					'port'         => (string) ( $instance['port'] ?? '' ),
					'path'         => (string) ( $instance['path'] ?? '' ),
				);
			}
		}

		\WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'relationship', 'scheme', 'host', 'port', 'path' )
		);
	}

	/**
	 * Runs the preview sanitize actions (fires upsun_preview_sanitize).
	 *
	 * Reports the state of the runtime preview protections, then fires the
	 * consumer sanitize hook and refreshes the environment stamp. Refuses to
	 * run on production unless --if-needed is set.
	 *
	 * The intended wiring is `wp upsun sanitize --if-needed` in the
	 * post_deploy hook: post_deploy is the only hook that runs on every
	 * redeploy, including data syncs, so freshly synced data is sanitized
	 * without any per-request checks. In that mode the command is safe on
	 * every environment: production just refreshes the stamp that makes its
	 * clones detectable, and previews whose stamp already matches skip.
	 *
	 * ## OPTIONS
	 *
	 * [--if-needed]
	 * : Deploy-hook mode. On production, refresh the environment stamp and
	 * exit; on previews, run only when the stamp shows freshly cloned or
	 * synced data.
	 *
	 * [--dry-run]
	 * : Report the protections and hooked callbacks without firing anything.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp upsun sanitize
	 *     wp upsun sanitize --if-needed   # post_deploy hook, all environments
	 *     wp upsun sanitize --dry-run
	 */
	public function sanitize( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		$module    = new SafePreviews();
		$if_needed = ! empty( $assoc_args['if-needed'] );

		if ( Environment::is_production() ) {
			if ( ! $if_needed ) {
				WP_CLI::error( 'This is the production environment; sanitize actions are preview-only. (In a shared post_deploy hook, use --if-needed.)' );
			}

			$module->refresh_stamp();
			WP_CLI::log( 'Production environment: stamp refreshed so clones stay detectable; nothing to sanitize.' );
			return;
		}

		if ( $if_needed && $module->is_sanitized() ) {
			WP_CLI::log( sprintf( 'Environment stamp matches "%s"; data already sanitized. Nothing to do.', Environment::name() ) );
			return;
		}

		$state = \Upsun\ModuleRegistry::status()['safe-previews']['state'] ?? 'not booted';

		if ( 'loaded' !== $state ) {
			WP_CLI::warning( "The safe-previews module is not loaded (state: {$state}); protections below are not hooked, but sanitize can still run." );
		}

		$rows = array();

		foreach ( $module->protections() as $id => $protection ) {
			if ( ! is_callable( $protection['status'] ?? null ) ) {
				continue;
			}

			$status = call_user_func( $protection['status'] );
			$rows[] = array(
				'protection' => (string) $id,
				'state'      => (string) ( $status['state'] ?? '' ),
				'detail'     => (string) ( $status['detail'] ?? '' ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'protection', 'state', 'detail' ) );

		$listeners = has_action( SafePreviews::SANITIZE_HOOK )
			? 'consumer callbacks are hooked'
			: 'no consumer callbacks hooked';

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::log( "Dry run: would fire upsun_preview_sanitize ({$listeners}) and refresh the environment stamp." );
			return;
		}

		$stored = get_option( SafePreviews::STAMP_OPTION );
		$result = $module->run_sanitize( is_string( $stored ) ? $stored : null );

		WP_CLI::success(
			sprintf(
				'Fired upsun_preview_sanitize (%s); environment stamp set to "%s".',
				$listeners,
				$result['environment']
			)
		);
	}

	/**
	 * Cache operations.
	 *
	 * The Upsun router cache exposes no purge API: cached pages expire by
	 * TTL or on redeploy. This flushes the persistent object cache only.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : The action to perform.
	 * ---
	 * options:
	 *   - flush
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp upsun cache flush
	 */
	public function cache( $args, $assoc_args ) {
		$action = $args[0] ?? '';

		if ( 'flush' !== $action ) {
			WP_CLI::error( "Unknown cache action '{$action}'. Supported: flush." );
		}

		if ( ! wp_cache_flush() ) {
			WP_CLI::error( 'Object cache flush failed.' );
		}

		WP_CLI::success( 'Object cache flushed. Note: the Upsun router cache has no purge API; cached pages expire by TTL or on redeploy.' );
	}
}
