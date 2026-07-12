<?php
/**
 * WooCommerce Stripe gateway support: forced into test mode on preview
 * clones so the live keys copied from production can never charge anyone.
 * Runtime-only — the settings option in the cloned database is untouched,
 * and missing test keys simply make the gateway unavailable (fails safe).
 */

namespace Upsun\Integrations;

use Upsun\Integration;

class WooCommerceStripe implements Integration {

	public function label(): string {
		return 'WooCommerce Stripe';
	}

	public function is_active(): bool {
		return class_exists( 'WC_Stripe' );
	}

	public function register(): void {
		add_filter( 'upsun_safe_previews_actions', array( $this, 'add_protection' ), 5 );
	}

	public function add_protection( array $protections ): array {
		$protections['woocommerce-stripe'] = array(
			'label'    => __( 'WooCommerce Stripe', 'upsun-mu-plugin' ),
			'register' => array( $this, 'register_protection' ),
			'status'   => array( $this, 'status' ),
		);

		return $protections;
	}

	public function register_protection(): void {
		add_filter( 'option_woocommerce_stripe_settings', array( $this, 'force_test_mode' ) );
	}

	/**
	 * Force the gateway into test mode at read time. The cloned live keys
	 * stay in the database untouched; if no test keys are configured the
	 * gateway simply becomes unavailable, which is the safe outcome.
	 *
	 * @param mixed $settings The woocommerce_stripe_settings option value.
	 * @return mixed
	 */
	public function force_test_mode( $settings ) {
		if ( ! is_array( $settings ) || ! $this->test_mode_forced() ) {
			return $settings;
		}

		$settings['testmode'] = 'yes';

		return $settings;
	}

	/**
	 * @return array{state: string, detail: string}
	 */
	public function status(): array {
		if ( ! $this->test_mode_forced() ) {
			return array(
				'state'  => 'off',
				'detail' => __( 'not forced (upsun_safe_previews_stripe_test_mode filter)', 'upsun-mu-plugin' ),
			);
		}

		if ( ! $this->is_active() ) {
			return array(
				'state'  => 'inactive',
				'detail' => __( 'WooCommerce Stripe not detected', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'state'  => 'active',
			'detail' => __( 'test mode forced at runtime (live keys unused)', 'upsun-mu-plugin' ),
		);
	}

	private function test_mode_forced(): bool {
		/**
		 * Filters whether WooCommerce Stripe is forced into test mode on
		 * previews.
		 *
		 * @param bool $forced Default true.
		 */
		return (bool) apply_filters( 'upsun_safe_previews_stripe_test_mode', true );
	}
}
