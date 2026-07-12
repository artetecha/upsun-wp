<?php
/**
 * Everything this plugin knows about WooCommerce, in one place:
 *
 * - its session/cart cookies mark a request personalised (page-cache
 *   bypass patterns),
 * - its cart/checkout/account pages are dynamic even without
 *   DONOTCACHEPAGE (page-cache skip),
 * - its outbound webhooks must not fire from preview clones
 *   (SafePreviews protection registry).
 *
 * Contributions go through the same public filters consumers use.
 */

namespace Upsun\Integrations;

use Upsun\Integration;

class WooCommerce implements Integration {

	public const COOKIE_PATTERNS = array(
		'/^(wp_woocommerce_session_|woocommerce_)/',
	);

	public function label(): string {
		return 'WooCommerce';
	}

	public function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function register(): void {
		// Priority 5: built-in contributions land before consumer filters
		// (default 10), so consumers can still remove or override them.
		add_filter( 'upsun_page_cache_bypass_cookie_patterns', array( $this, 'add_cookie_patterns' ), 5 );
		add_filter( 'upsun_page_cache_skip', array( $this, 'skip_dynamic_pages' ), 5 );
		add_filter( 'upsun_safe_previews_actions', array( $this, 'add_webhook_protection' ), 5 );
	}

	/**
	 * @param mixed $patterns
	 * @return array
	 */
	public function add_cookie_patterns( $patterns ): array {
		return array_merge( (array) $patterns, self::COOKIE_PATTERNS );
	}

	/**
	 * Belt and braces for pages WooCommerce marks dynamic without setting
	 * DONOTCACHEPAGE.
	 *
	 * @param mixed $skip
	 * @return mixed
	 */
	public function skip_dynamic_pages( $skip ) {
		if ( $skip ) {
			return $skip;
		}

		return function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
	}

	/* ---------------------------------------------------------------------
	 * SafePreviews protection: pause outbound webhooks on previews.
	 * ------------------------------------------------------------------- */

	public function add_webhook_protection( array $protections ): array {
		$protections['woocommerce-webhooks'] = array(
			'label'    => __( 'WooCommerce webhooks', 'upsun-mu-plugin' ),
			'register' => array( $this, 'register_webhook_protection' ),
			'status'   => array( $this, 'webhook_status' ),
		);

		return $protections;
	}

	public function register_webhook_protection(): void {
		add_filter( 'woocommerce_webhook_should_deliver', array( $this, 'maybe_pause_webhook' ), PHP_INT_MAX );
	}

	/**
	 * Short-circuit webhook delivery; the webhooks keep their active status
	 * in the database, so nothing can sync back wrong.
	 *
	 * @param mixed $should_deliver
	 * @return mixed
	 */
	public function maybe_pause_webhook( $should_deliver ) {
		return $this->webhooks_paused() ? false : $should_deliver;
	}

	/**
	 * @return array{state: string, detail: string}
	 */
	public function webhook_status(): array {
		if ( ! $this->webhooks_paused() ) {
			return array(
				'state'  => 'off',
				'detail' => __( 'not paused (upsun_safe_previews_pause_webhooks filter)', 'upsun-mu-plugin' ),
			);
		}

		if ( ! $this->is_active() ) {
			return array(
				'state'  => 'inactive',
				'detail' => __( 'WooCommerce not detected', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'state'  => 'active',
			'detail' => __( 'deliveries paused (statuses untouched)', 'upsun-mu-plugin' ),
		);
	}

	private function webhooks_paused(): bool {
		/**
		 * Filters whether WooCommerce webhook deliveries are paused on
		 * previews.
		 *
		 * @param bool $paused Default true.
		 */
		return (bool) apply_filters( 'upsun_safe_previews_pause_webhooks', true );
	}
}
