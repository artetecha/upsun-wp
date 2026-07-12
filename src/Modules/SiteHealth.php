<?php
/**
 * Upsun-specific Site Health tests and debug information. The check
 * registry is shared with `wp upsun doctor`.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class SiteHealth implements Module {

	public function should_load(): bool {
		return true;
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_info' ) );
	}

	/**
	 * The check registry: id => [ label, callback ]. Callbacks return
	 * [ 'status' => pass|warn|fail, 'message' => string ].
	 *
	 * @return array<string, array{label: string, callback: callable}>
	 */
	public static function checks(): array {
		$checks = array(
			'object_cache'      => array(
				'label'    => __( 'Persistent object cache', 'upsun-mu-plugin' ),
				'callback' => array( self::class, 'check_object_cache' ),
			),
			'cron'              => array(
				'label'    => __( 'Cron configuration', 'upsun-mu-plugin' ),
				'callback' => array( self::class, 'check_cron' ),
			),
			'writable_mounts'   => array(
				'label'    => __( 'Writable mounts', 'upsun-mu-plugin' ),
				'callback' => array( self::class, 'check_writable_mounts' ),
			),
			'search_visibility' => array(
				'label'    => __( 'Search engine visibility', 'upsun-mu-plugin' ),
				'callback' => array( self::class, 'check_search_visibility' ),
			),
			'migrations'        => array(
				'label'    => __( 'Deploy migrations', 'upsun-mu-plugin' ),
				'callback' => array( \Upsun\Migrations::class, 'check' ),
			),
		);

		/**
		 * Filters the Upsun check registry (Site Health + wp upsun doctor).
		 *
		 * @param array $checks id => [ 'label' => string, 'callback' => callable ].
		 */
		return (array) apply_filters( 'upsun_site_health_tests', $checks );
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check_object_cache(): array {
		$has_redis = null !== self::redis_relationship();

		if ( ! wp_using_ext_object_cache() ) {
			if ( $has_redis ) {
				return array(
					'status'  => 'fail',
					'message' => __( 'A Redis relationship is configured but WordPress is not using a persistent object cache — is the object-cache.php drop-in installed?', 'upsun-mu-plugin' ),
				);
			}

			return array(
				'status'  => 'warn',
				'message' => __( 'No persistent object cache. Add a Redis service and relationship, and install an object-cache.php drop-in.', 'upsun-mu-plugin' ),
			);
		}

		$key = 'upsun_site_health_probe';

		wp_cache_set( $key, 'ok', 'upsun', 30 );

		if ( 'ok' !== wp_cache_get( $key, 'upsun' ) ) {
			return array(
				'status'  => 'fail',
				'message' => __( 'The object cache is active but a set/get round-trip failed — the cache service may be down.', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'status'  => 'pass',
			'message' => __( 'A persistent object cache is active and responding.', 'upsun-mu-plugin' ),
		);
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check_cron(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'Loopback WP-Cron is disabled; ensure an Upsun cron runs "wp cron event run --due-now".', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'status'  => 'warn',
			'message' => __( 'WP-Cron runs via loopback requests. Define DISABLE_WP_CRON and add an Upsun cron running "wp cron event run --due-now".', 'upsun-mu-plugin' ),
		);
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check_writable_mounts(): array {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || ! wp_is_writable( $uploads['basedir'] ) ) {
			return array(
				'status'  => 'fail',
				'message' => __( 'The uploads directory is not writable — check the wp-content/uploads mount in your Upsun configuration.', 'upsun-mu-plugin' ),
			);
		}

		$cache_dir = WP_CONTENT_DIR . '/cache';

		if ( is_dir( $cache_dir ) && ! wp_is_writable( $cache_dir ) ) {
			return array(
				'status'  => 'warn',
				'message' => __( 'wp-content/cache exists but is not writable — plugins that bundle assets there will fail; check the mount.', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'status'  => 'pass',
			'message' => __( 'Writable mounts are in place.', 'upsun-mu-plugin' ),
		);
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check_search_visibility(): array {
		if ( Environment::is_production() ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'Production environment; search engine visibility follows site settings.', 'upsun-mu-plugin' ),
			);
		}

		if ( ! get_option( 'blog_public' ) ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'Search engines are discouraged by the site settings.', 'upsun-mu-plugin' ),
			);
		}

		$protected = ! ( defined( 'UPSUN_DISABLE_PREVIEW_PROTECTION' ) && UPSUN_DISABLE_PREVIEW_PROTECTION )
			&& apply_filters( 'upsun_preview_noindex', true );

		if ( $protected ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'Non-production environment; noindex headers are applied by the Upsun plugin.', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'status'  => 'warn',
			'message' => __( 'This non-production environment is indexable: preview protection is disabled and the site is public.', 'upsun-mu-plugin' ),
		);
	}

	public function add_tests( array $tests ): array {
		foreach ( self::checks() as $id => $check ) {
			$tests['direct'][ 'upsun_' . $id ] = array(
				'label' => $check['label'],
				'test'  => static function () use ( $id, $check ) {
					$result = call_user_func( $check['callback'] );
					$map    = array(
						'pass' => 'good',
						'warn' => 'recommended',
						'fail' => 'critical',
					);

					return array(
						'label'       => sprintf( 'Upsun: %s', $check['label'] ),
						'status'      => $map[ $result['status'] ] ?? 'recommended',
						'badge'       => array(
							'label' => 'Upsun',
							'color' => 'purple',
						),
						'description' => '<p>' . esc_html( $result['message'] ) . '</p>',
						'actions'     => '',
						'test'        => 'upsun_' . $id,
					);
				},
			);
		}

		return $tests;
	}

	public function add_debug_info( array $info ): array {
		$info['upsun'] = array(
			'label'  => 'Upsun',
			'fields' => array(
				'environment'    => array(
					'label' => __( 'Environment', 'upsun-mu-plugin' ),
					'value' => Environment::name() ?? '—',
				),
				'type'           => array(
					'label' => __( 'Type', 'upsun-mu-plugin' ),
					'value' => Environment::type() ?? '—',
				),
				'branch'         => array(
					'label' => __( 'Branch', 'upsun-mu-plugin' ),
					'value' => Environment::branch() ?? '—',
				),
				'project'        => array(
					'label' => __( 'Project', 'upsun-mu-plugin' ),
					'value' => Environment::project() ?? '—',
				),
				'application'    => array(
					'label' => __( 'Application', 'upsun-mu-plugin' ),
					'value' => Environment::application_name() ?? '—',
				),
				'primary_route'  => array(
					'label' => __( 'Primary route', 'upsun-mu-plugin' ),
					'value' => Environment::primary_route() ?? '—',
				),
				'plugin_version' => array(
					'label' => __( 'Plugin version', 'upsun-mu-plugin' ),
					'value' => \Upsun\version(),
				),
			),
		);

		return $info;
	}

	/**
	 * The first relationship instance whose scheme is redis, or null.
	 * Scans all relationships so no particular relationship name is assumed.
	 */
	public static function redis_relationship(): ?array {
		foreach ( Environment::relationships() as $instances ) {
			if ( ! is_array( $instances ) ) {
				continue;
			}

			foreach ( $instances as $instance ) {
				if ( is_array( $instance ) && 'redis' === ( $instance['scheme'] ?? '' ) ) {
					return $instance;
				}
			}
		}

		return null;
	}
}
