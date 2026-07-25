<?php

namespace Upsun;

final class ModuleRegistry {

	private const MODULES = array(
		// First: restores the real client IP into REMOTE_ADDR (in register())
		// before any other module's hooks read it.
		'cloudflare'            => Modules\Cloudflare::class,
		// After cloudflare: its send_headers HSTS decision reads
		// Cloudflare::is_fronted() to defer to the edge when proxied.
		'security-headers'      => Modules\SecurityHeaders::class,
		'environment-indicator' => Modules\EnvironmentIndicator::class,
		'page-cache'            => Modules\PageCache::class,
		'updates-policy'        => Modules\UpdatesPolicy::class,
		'site-health'           => Modules\SiteHealth::class,
		'preview-protection'    => Modules\PreviewProtection::class,
		'smtp'                  => Modules\Smtp::class,
		'dashboard'             => Modules\Dashboard::class,
		'cron-heartbeat'        => Modules\CronHeartbeat::class,
		'safe-previews'         => Modules\SafePreviews::class,
		'writable-paths'        => Modules\WritablePaths::class,
		'mount-usage'           => Modules\MountUsage::class,
	);

	/**
	 * The per-module boot filters replaced by upsun_module_enabled in 0.7.
	 *
	 * They covered eight of thirteen modules, so a consumer wanting a
	 * conditional (per-environment) toggle could have one for eight modules
	 * and had to fall back to a wp-config constant for the other five. The
	 * generic filter covers all thirteen; these are honoured through
	 * Deprecations until 1.0.
	 *
	 * @var array<string, string> module id => deprecated filter name.
	 */
	private const TOGGLES = array(
		'cloudflare'            => 'upsun_cloudflare_enabled',
		'security-headers'      => 'upsun_security_headers_enabled',
		'environment-indicator' => 'upsun_environment_indicator_enabled',
		'dashboard'             => 'upsun_dashboard_enabled',
		'cron-heartbeat'        => 'upsun_cron_heartbeat_enabled',
		'safe-previews'         => 'upsun_safe_previews_enabled',
		'writable-paths'        => 'upsun_writable_paths_enabled',
		'mount-usage'           => 'upsun_mount_usage_enabled',
	);

	/**
	 * Per-module outcome of the last boot: id => [ class, state ].
	 * States: loaded | constant | filter | disabled | missing | declined.
	 *
	 * @var array<string, array{class: string, state: string}>
	 */
	private static array $status = array();

	/**
	 * Boot enabled modules. Runs at muplugins_loaded priority 0.
	 *
	 * Gating, most specific wins:
	 * 1. UPSUN_MU_DISABLE kill switch.
	 * 2. Off-platform: no-op (UPSUN_MU_FORCE overrides, for local/CI testing
	 *    of individual modules against faked PLATFORM_* variables).
	 * 3. Per-module UPSUN_DISABLE_{MODULE} constants (wp-config friendly).
	 * 4. The upsun_modules filter, for removing or adding modules wholesale.
	 * 5. The upsun_module_enabled filter, for toggling one by id.
	 * 6. Each module's own should_load().
	 *
	 * @internal
	 */
	public static function boot(): void {
		self::$status = array();

		if ( defined( 'UPSUN_MU_DISABLE' ) && UPSUN_MU_DISABLE ) {
			return;
		}

		if ( ! Environment::is_upsun() && ! ( defined( 'UPSUN_MU_FORCE' ) && UPSUN_MU_FORCE ) ) {
			return;
		}

		/**
		 * Filters the module map before boot.
		 *
		 * @param array<string, class-string<Module>> $modules id => class.
		 */
		$modules = (array) Deprecations::filter( 'upsun_modules', 'upsun_mu_modules', self::MODULES );

		// Defaults absent from the filtered map were removed by a consumer.
		foreach ( array_diff_key( self::MODULES, $modules ) as $id => $class ) {
			self::$status[ $id ] = array(
				'class' => $class,
				'state' => 'filter',
			);
		}

		foreach ( $modules as $id => $class ) {
			$id    = (string) $id;
			$class = (string) $class;

			if ( self::disabled_by_constant( $id ) ) {
				self::$status[ $id ] = array(
					'class' => $class,
					'state' => 'constant',
				);
				continue;
			}

			if ( ! self::enabled_by_filter( $id ) ) {
				self::$status[ $id ] = array(
					'class' => $class,
					'state' => 'disabled',
				);
				continue;
			}

			if ( ! class_exists( $class ) ) {
				self::$status[ $id ] = array(
					'class' => $class,
					'state' => 'missing',
				);
				continue;
			}

			$module = new $class();

			if ( $module instanceof Module && $module->should_load() ) {
				$module->register();
				self::$status[ $id ] = array(
					'class' => $class,
					'state' => 'loaded',
				);
			} else {
				self::$status[ $id ] = array(
					'class' => $class,
					'state' => 'declined',
				);
			}
		}
	}

	/**
	 * Per-module outcome of the last boot (empty when boot no-opped: kill
	 * switch or off-platform). Consumed by the dashboard's Modules panel.
	 *
	 * @return array<string, array{class: string, state: string}>
	 */
	public static function status(): array {
		return self::$status;
	}

	/**
	 * e.g. 'page-cache' => UPSUN_DISABLE_PAGE_CACHE.
	 */
	public static function disable_constant_name( string $id ): string {
		return 'UPSUN_DISABLE_' . strtoupper( str_replace( '-', '_', $id ) );
	}

	/**
	 * Whether a module is enabled by filter — the conditional counterpart to
	 * the UPSUN_DISABLE_{MODULE} constants, available for every module.
	 */
	private static function enabled_by_filter( string $id ): bool {
		$enabled = true;

		if ( isset( self::TOGGLES[ $id ] ) ) {
			// The module's own pre-0.7 toggle, honoured until 1.0. Its result
			// feeds the generic filter below, so a consumer registering only
			// the old name still decides.
			$enabled = (bool) apply_filters_deprecated(
				self::TOGGLES[ $id ],
				array( $enabled ),
				Deprecations::SINCE,
				'upsun_module_enabled'
			);
		}

		/**
		 * Filters whether a single module boots, by id (the ids are the keys
		 * of the upsun_modules map: 'page-cache', 'smtp', ...).
		 *
		 * Use for conditional gating — per environment type, per hostname —
		 * where a wp-config constant cannot express the condition. For an
		 * unconditional switch prefer UPSUN_DISABLE_{MODULE}, which is read
		 * before this filter and wins.
		 *
		 * @param bool   $enabled Default true.
		 * @param string $id      Module id.
		 */
		return (bool) apply_filters( 'upsun_module_enabled', $enabled, $id );
	}

	private static function disabled_by_constant( string $id ): bool {
		$constant = self::disable_constant_name( $id );

		return defined( $constant ) && constant( $constant );
	}
}
