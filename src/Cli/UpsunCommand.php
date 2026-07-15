<?php

namespace Upsun\Cli;

use Upsun\CacheCheck;
use Upsun\Environment;
use Upsun\Migrations;
use Upsun\Modules\Cloudflare;
use Upsun\Modules\SafePreviews;
use Upsun\Modules\SiteHealth;
use Upsun\Modules\WritablePaths;
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
	 * [--health]
	 * : Probe each relationship live — MySQL/MariaDB ping and server info,
	 * Redis INFO memory/hit-rate/evictions, HTTP status with cluster-status
	 * sniffing for search services. Unknown schemes are skipped.
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
	 *     wp upsun relationships --health
	 */
	public function relationships( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		if ( ! empty( $assoc_args['health'] ) ) {
			$rows   = \Upsun\RelationshipHealth::probe_all();
			$failed = array_filter( $rows, static fn ( array $row ) => 'fail' === $row['status'] );

			\WP_CLI\Utils\format_items(
				$assoc_args['format'] ?? 'table',
				$rows,
				array( 'relationship', 'scheme', 'host', 'status', 'detail' )
			);

			if ( array() !== $failed ) {
				WP_CLI::error( sprintf( '%d relationship(s) failed their health probe.', count( $failed ) ) );
			}

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
	 * Explains whether the Upsun router would cache a URL, and why.
	 *
	 * Fetches the URL once (redirects not followed) and reports the
	 * verdict: the emitted Cache-Control and effective TTL, Set-Cookie
	 * headers (which make the router refuse to cache), request cookies
	 * matching the page-cache bypass patterns, the route's cache settings
	 * from PLATFORM_ROUTES, and whether this fetch was a router HIT, MISS,
	 * or BYPASS.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL to check. A path like /courses/ is resolved against the
	 * environment's primary route.
	 *
	 * [--cookie=<header>]
	 * : Send a Cookie header, e.g. --cookie="lp_session_guest=x; foo=1".
	 *
	 * [--auth=<credentials>]
	 * : HTTP basic auth credentials as "user:pass", for environments behind
	 * access control (without them the verdict describes the 401 challenge,
	 * not the page). Note: WP-CLI synopsis tokens cannot contain a colon,
	 * hence the generic placeholder.
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
	 *     wp upsun cache-check /
	 *     wp upsun cache-check /courses/ --cookie="wordpress_logged_in_x=1"
	 *     wp upsun cache-check / --auth=preview:secret
	 *
	 * @subcommand cache-check
	 */
	public function cache_check( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		$report = CacheCheck::run(
			(string) ( $args[0] ?? '' ),
			(string) ( $assoc_args['cookie'] ?? '' ),
			(string) ( $assoc_args['auth'] ?? '' )
		);

		if ( isset( $report['error'] ) ) {
			WP_CLI::error( $report['error'] );
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $report['rows'], array( 'field', 'value' ) );

		foreach ( $report['notes'] as $note ) {
			WP_CLI::log( '- ' . $note );
		}

		if ( $report['cacheable'] ) {
			WP_CLI::success( $report['summary'] );
		} else {
			WP_CLI::warning( $report['summary'] );
		}
	}

	/**
	 * Runs the preview sanitize actions (fires upsun_preview_sanitize).
	 *
	 * Reports the state of the runtime preview protections and the opt-in
	 * sanitizers, then runs the enabled sanitizers, fires the consumer
	 * sanitize hook, and refreshes the environment stamp. Refuses to run on
	 * production unless --if-needed is set.
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
	 * [--enable=<sanitizers>]
	 * : Force sanitizers on for this run (project-level policy — put this
	 * in the post_deploy hook). Comma-separated ids, each optionally taking
	 * a value after a colon for the built-ins that support one (a password
	 * template for anonymize-user-passwords, pipe-separated plugin basenames
	 * for deactivate-plugins). Nothing is persisted; filters remain the
	 * code-based alternative. Note: WP-CLI treats docblock lines starting
	 * with a colon as description markers, so never begin a line here with
	 * one.
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
	 *     wp upsun sanitize --if-needed --enable="anonymize-user-emails,anonymize-user-passwords:password-{ID}"
	 *     wp upsun sanitize --dry-run --enable="deactivate-plugins:updraftplus/updraftplus.php|wordfence/wordfence.php"
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

		if ( ! empty( $assoc_args['enable'] ) ) {
			$forced = array();

			foreach ( explode( ',', (string) $assoc_args['enable'] ) as $item ) {
				$item = trim( $item );

				if ( '' === $item ) {
					continue;
				}

				$parts                       = explode( ':', $item, 2 );
				$forced[ trim( $parts[0] ) ] = isset( $parts[1] ) && '' !== trim( $parts[1] ) ? trim( $parts[1] ) : true;
			}

			foreach ( array_keys( array_diff_key( $forced, \Upsun\Sanitizers::registry() ) ) as $unknown ) {
				WP_CLI::warning( "Unknown sanitizer '{$unknown}' in --enable." );
			}

			\Upsun\Sanitizers::force( $forced );
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

		$sanitizer_rows = array();

		foreach ( \Upsun\Sanitizers::registry() as $id => $sanitizer ) {
			$sanitizer_rows[] = array(
				'sanitizer' => (string) $id,
				'enabled'   => \Upsun\Sanitizers::is_enabled( (string) $id, $sanitizer ) ? 'yes' : 'no',
			);
		}

		if ( array() !== $sanitizer_rows ) {
			\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $sanitizer_rows, array( 'sanitizer', 'enabled' ) );
		}

		$listeners = has_action( SafePreviews::SANITIZE_HOOK )
			? 'consumer callbacks are hooked'
			: 'no consumer callbacks hooked';

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			foreach ( \Upsun\Sanitizers::run( true ) as $line ) {
				WP_CLI::log( '- ' . $line );
			}

			WP_CLI::log( "Dry run: would fire upsun_preview_sanitize ({$listeners}) and refresh the environment stamp." );
			return;
		}

		$stored = get_option( SafePreviews::STAMP_OPTION );
		$result = $module->run_sanitize( is_string( $stored ) ? $stored : null );

		foreach ( $result['reports'] as $line ) {
			WP_CLI::log( '- ' . $line );
		}

		WP_CLI::success(
			sprintf(
				'Ran %d sanitizer(s), fired upsun_preview_sanitize (%s); environment stamp set to "%s".',
				count( $result['reports'] ),
				$listeners,
				$result['environment']
			)
		);
	}

	/**
	 * Applies pending deploy migrations in order.
	 *
	 * Migrations are PHP files named YYYYMMDD_NNNN_short_name.php in the
	 * directory set by UPSUN_MIGRATIONS_DIR (or the upsun_migrations_dir
	 * filter); each returns a callable performing one once-per-database
	 * change. Successful migrations are recorded in non-autoloaded options
	 * and never re-run — clones carry the markers with the migrated data.
	 * Exits non-zero on the first failure so a deploy hook aborts before
	 * traffic. Run it from the deploy hook, before caches warm.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the migration status without applying anything.
	 *
	 * [--format=<format>]
	 * : Render the status table in a particular format.
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
	 *     wp upsun migrate --dry-run
	 *     wp upsun migrate
	 */
	public function migrate( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		if ( null === Migrations::directory() ) {
			WP_CLI::log( 'No migrations directory configured (UPSUN_MIGRATIONS_DIR / upsun_migrations_dir); nothing to do.' );
			return;
		}

		$rows = array_map(
			static fn ( array $row ) => array(
				'migration'  => $row['id'],
				'state'      => $row['state'],
				'applied_at' => $row['applied_at'],
			),
			Migrations::status()
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'migration', 'state', 'applied_at' ) );

		$invalid = Migrations::invalid();

		if ( array() !== $invalid ) {
			WP_CLI::error( 'Invalid migration filename(s): ' . implode( ', ', $invalid ) . '. Expected YYYYMMDD_NNNN_short_name.php.' );
		}

		$pending = Migrations::pending();

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::log(
				array() === $pending
					? 'Nothing pending.'
					: sprintf( 'Would apply %d migration(s) in order: %s.', count( $pending ), implode( ', ', array_column( $pending, 'id' ) ) )
			);
			return;
		}

		if ( array() === $pending ) {
			WP_CLI::success( 'Nothing to apply.' );
			return;
		}

		$result = Migrations::run();

		foreach ( $result['applied'] as $id ) {
			WP_CLI::log( "Applied {$id}." );
		}

		if ( null !== $result['error'] ) {
			WP_CLI::error( $result['error'] ); // Non-zero exit: the deploy hook aborts.
		}

		WP_CLI::success( sprintf( 'Applied %d migration(s).', count( $result['applied'] ) ) );
	}

	/**
	 * Shows declared writable mounts and suggests missing ones.
	 *
	 * Lists the mounts declared in the Upsun configuration (read from
	 * PLATFORM_APPLICATION), then checks the writable-path requirements of
	 * known active plugins (the upsun_writable_path_requirements registry)
	 * and prints ready-to-paste mount YAML for anything not covered.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the mounts table in a particular format.
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
	 *     wp upsun mounts
	 */
	public function mounts( $args, $assoc_args ) {
		if ( ! Environment::is_upsun() ) {
			WP_CLI::log( 'Not running on Upsun.' );
			return;
		}

		$rows = array();

		foreach ( Environment::mounts() as $path => $mount ) {
			$rows[] = array(
				'mount'       => (string) $path,
				'source'      => (string) ( is_array( $mount ) ? ( $mount['source'] ?? '' ) : '' ),
				'source_path' => (string) ( is_array( $mount ) ? ( $mount['source_path'] ?? '' ) : '' ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'mount', 'source', 'source_path' ) );

		$suggestions = WritablePaths::suggestions();

		if ( array() === $suggestions ) {
			WP_CLI::success( 'All writable paths required by known active plugins are covered by mounts.' );
			return;
		}

		foreach ( $suggestions as $suggestion ) {
			WP_CLI::warning( sprintf( '%s writes to %s, which is not a mount.', $suggestion['label'], $suggestion['path'] ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Add to your application config (mount paths are relative to the app root):' );
		WP_CLI::log( '' );
		WP_CLI::log( WritablePaths::suggestions_yaml( $suggestions ) );
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

	/**
	 * Cloudflare edge operations.
	 *
	 * Reports whether Cloudflare is fronting this environment and whether
	 * purge credentials are configured, or purges the Cloudflare edge cache
	 * (the invalidation the Upsun router cache never had). Credentials come
	 * from CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN (use a token scoped
	 * to Zone -> Cache Purge only).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : The action to perform.
	 * ---
	 * options:
	 *   - status
	 *   - purge
	 * ---
	 *
	 * [--url=<url>]
	 * : With "purge", purge only these URLs. Repeat for several, or pass a
	 * comma-separated list. Omit to purge everything in the zone.
	 *
	 * [--all]
	 * : With "purge", purge the entire zone (the default when no --url is
	 * given; accepted explicitly for clarity in scripts).
	 *
	 * ## EXAMPLES
	 *
	 *     wp upsun cloudflare status
	 *     wp upsun cloudflare purge --all
	 *     wp upsun cloudflare purge --url=https://example.com/ --url=https://example.com/courses/
	 */
	public function cloudflare( $args, $assoc_args ) {
		$action      = $args[0] ?? '';
		$credentials = Cloudflare::credentials();
		$purge_ready = '' !== $credentials['zone'] && '' !== $credentials['token'];

		if ( 'status' === $action ) {
			// Fronting is a per-web-request signal (CF-Ray header); over the
			// CLI there is no HTTP request, so this always reads "no" — that is
			// expected, not a failure. Verify live fronting from a browser
			// (Site Health) instead. purge_credentials is meaningful from CLI.
			$fronted = Cloudflare::is_fronted( $_SERVER );

			\WP_CLI\Utils\format_items(
				$assoc_args['format'] ?? 'table',
				array(
					array(
						'field' => 'fronting_this_request',
						'value' => $fronted ? 'yes' : 'no (expected over CLI — no HTTP request)',
					),
					array(
						'field' => 'purge_credentials',
						'value' => $purge_ready ? 'configured' : 'not set',
					),
				),
				array( 'field', 'value' )
			);

			return;
		}

		if ( 'purge' !== $action ) {
			WP_CLI::error( "Unknown action '{$action}'. Supported: status, purge." );
		}

		$urls = array();

		if ( isset( $assoc_args['url'] ) ) {
			foreach ( (array) $assoc_args['url'] as $value ) {
				foreach ( explode( ',', (string) $value ) as $url ) {
					$url = trim( $url );

					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}
			}
		}

		$result = ( new Cloudflare() )->purge( $urls );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			array() === $urls
				? 'Purged the entire Cloudflare zone.'
				: sprintf( 'Purged %d URL(s) from Cloudflare.', count( $urls ) )
		);
	}
}
