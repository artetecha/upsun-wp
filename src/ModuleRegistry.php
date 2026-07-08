<?php

namespace Upsun;

final class ModuleRegistry {

	private const MODULES = array(
		'environment-indicator' => Modules\EnvironmentIndicator::class,
		'page-cache'            => Modules\PageCache::class,
		'updates-policy'        => Modules\UpdatesPolicy::class,
		'site-health'           => Modules\SiteHealth::class,
		'preview-protection'    => Modules\PreviewProtection::class,
		'smtp'                  => Modules\Smtp::class,
	);

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

		foreach ( $modules as $id => $class ) {
			if ( self::disabled_by_constant( (string) $id ) || ! class_exists( $class ) ) {
				continue;
			}

			$module = new $class();

			if ( $module instanceof Module && $module->should_load() ) {
				$module->register();
			}
		}
	}

	/**
	 * e.g. 'page-cache' => UPSUN_DISABLE_PAGE_CACHE.
	 */
	private static function disabled_by_constant( string $id ): bool {
		$constant = 'UPSUN_DISABLE_' . strtoupper( str_replace( '-', '_', $id ) );

		return defined( $constant ) && constant( $constant );
	}
}
