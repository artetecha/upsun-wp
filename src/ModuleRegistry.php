<?php

namespace Upsun;

final class ModuleRegistry {

	private const MODULES = array(
		// First: restores the real client IP into REMOTE_ADDR (in register())
		// before any other module's hooks read it.
		'cloudflare'            => Modules\Cloudflare::class,
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
	 * Per-module outcome of the last boot: id => [ class, state ].
	 * States: loaded | constant | filter | missing | declined.
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
	 * 4. The upsun_mu_modules filter (for other mu-plugins).
	 * 5. Each module's own should_load().
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
		$modules = (array) apply_filters( 'upsun_mu_modules', self::MODULES );

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

	private static function disabled_by_constant( string $id ): bool {
		$constant = self::disable_constant_name( $id );

		return defined( $constant ) && constant( $constant );
	}
}
