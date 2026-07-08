<?php

namespace Upsun\Cli;

use Upsun\Environment;
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
