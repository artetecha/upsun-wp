<?php

namespace Upsun;

final class IntegrationRegistry {

	private const INTEGRATIONS = array(
		'woocommerce'        => Integrations\WooCommerce::class,
		'woocommerce-stripe' => Integrations\WooCommerceStripe::class,
		'wordfence'          => Integrations\Wordfence::class,
		'updraftplus'        => Integrations\UpdraftPlus::class,
		'wp-rocket'          => Integrations\WpRocket::class,
	);

	/**
	 * Per-integration outcome of the last boot: id => [ class, state ].
	 * States: loaded | constant | filter | missing | invalid.
	 *
	 * @var array<string, array{class: string, state: string}>
	 */
	private static array $status = array();

	/** @var array<string, Integration> Loaded instances, for reporting. */
	private static array $instances = array();

	/**
	 * Boot enabled integrations. Runs at muplugins_loaded priority 0,
	 * BEFORE ModuleRegistry::boot, so integration contributions are on the
	 * filters when modules read them. Gating mirrors ModuleRegistry:
	 *
	 * 1. UPSUN_MU_DISABLE kill switch.
	 * 2. Off-platform: no-op (UPSUN_MU_FORCE overrides).
	 * 3. Per-integration UPSUN_DISABLE_INTEGRATION_{ID} constants.
	 * 4. The upsun_integrations filter (for other mu-plugins).
	 */
	public static function boot(): void {
		self::$status    = array();
		self::$instances = array();

		if ( defined( 'UPSUN_MU_DISABLE' ) && UPSUN_MU_DISABLE ) {
			return;
		}

		if ( ! Environment::is_upsun() && ! ( defined( 'UPSUN_MU_FORCE' ) && UPSUN_MU_FORCE ) ) {
			return;
		}

		/**
		 * Filters the integration map before boot.
		 *
		 * @param array<string, class-string<Integration>> $integrations id => class.
		 */
		$integrations = (array) apply_filters( 'upsun_integrations', self::INTEGRATIONS );

		// Defaults absent from the filtered map were removed by a consumer.
		foreach ( array_diff_key( self::INTEGRATIONS, $integrations ) as $id => $class ) {
			self::$status[ $id ] = array(
				'class' => $class,
				'state' => 'filter',
			);
		}

		foreach ( $integrations as $id => $class ) {
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

			$integration = new $class();

			if ( $integration instanceof Integration ) {
				$integration->register();
				self::$instances[ $id ] = $integration;
				self::$status[ $id ]    = array(
					'class' => $class,
					'state' => 'loaded',
				);
			} else {
				self::$status[ $id ] = array(
					'class' => $class,
					'state' => 'invalid',
				);
			}
		}
	}

	/**
	 * Per-integration outcome of the last boot (empty when boot no-opped).
	 * Consumed by the dashboard's Modules panel.
	 *
	 * @return array<string, array{class: string, state: string}>
	 */
	public static function status(): array {
		return self::$status;
	}

	/**
	 * The loaded instance for an id, for render-time is_active() reporting.
	 */
	public static function instance( string $id ): ?Integration {
		return self::$instances[ $id ] ?? null;
	}

	/**
	 * e.g. 'woocommerce' => UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE.
	 */
	public static function disable_constant_name( string $id ): string {
		return 'UPSUN_DISABLE_INTEGRATION_' . strtoupper( str_replace( '-', '_', $id ) );
	}

	private static function disabled_by_constant( string $id ): bool {
		$constant = self::disable_constant_name( $id );

		return defined( $constant ) && constant( $constant );
	}
}
