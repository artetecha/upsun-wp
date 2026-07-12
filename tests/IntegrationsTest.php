<?php

use PHPUnit\Framework\TestCase;
use Upsun\Integrations\WooCommerce;
use Upsun\Integrations\WooCommerceStripe;
use Upsun\Modules\SafePreviews;

// WooCommerce conditional-tag stubs, toggled per test.
function is_cart() {
	return ! empty( $GLOBALS['upsun_test_is_cart'] );
}

function is_checkout() {
	return ! empty( $GLOBALS['upsun_test_is_checkout'] );
}

function is_account_page() {
	return ! empty( $GLOBALS['upsun_test_is_account'] );
}

final class IntegrationsTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		unset( $GLOBALS['upsun_test_is_cart'], $GLOBALS['upsun_test_is_checkout'], $GLOBALS['upsun_test_is_account'] );
	}

	/* WooCommerce: page-cache contributions. */

	public function test_cookie_patterns_are_contributed_through_the_public_filter(): void {
		( new WooCommerce() )->register();

		$patterns = apply_filters(
			'upsun_page_cache_bypass_cookie_patterns',
			\Upsun\Modules\PageCache::DEFAULT_COOKIE_PATTERNS
		);

		$this->assertContains( WooCommerce::COOKIE_PATTERNS[0], $patterns );
		// Core defaults survive the contribution.
		$this->assertContains( \Upsun\Modules\PageCache::DEFAULT_COOKIE_PATTERNS[0], $patterns );
	}

	public function test_dynamic_pages_skip_caching(): void {
		( new WooCommerce() )->register();

		$this->assertFalse( apply_filters( 'upsun_page_cache_skip', false ) );

		$GLOBALS['upsun_test_is_checkout'] = true;
		$this->assertTrue( apply_filters( 'upsun_page_cache_skip', false ) );
	}

	public function test_skip_respects_a_prior_true(): void {
		$this->assertTrue( ( new WooCommerce() )->skip_dynamic_pages( true ) );
	}

	/* WooCommerce: webhook protection (ported from SafePreviews). */

	public function test_webhook_delivery_paused(): void {
		$this->assertFalse( ( new WooCommerce() )->maybe_pause_webhook( true ) );
	}

	public function test_webhook_pause_can_be_opted_out(): void {
		add_filter( 'upsun_safe_previews_pause_webhooks', '__return_false' );

		$this->assertTrue( ( new WooCommerce() )->maybe_pause_webhook( true ) );
	}

	/* WooCommerce Stripe (ported from SafePreviews). */

	public function test_stripe_settings_forced_into_test_mode(): void {
		$settings = ( new WooCommerceStripe() )->force_test_mode( array( 'testmode' => 'no' ) );

		$this->assertSame( 'yes', $settings['testmode'] );
	}

	public function test_stripe_non_array_settings_pass_through(): void {
		$this->assertFalse( ( new WooCommerceStripe() )->force_test_mode( false ) );
	}

	public function test_stripe_forcing_can_be_opted_out(): void {
		add_filter( 'upsun_safe_previews_stripe_test_mode', '__return_false' );

		$settings = ( new WooCommerceStripe() )->force_test_mode( array( 'testmode' => 'no' ) );

		$this->assertSame( 'no', $settings['testmode'] );
	}

	/* Contribution flow into SafePreviews. */

	public function test_integrations_join_the_safe_previews_registry(): void {
		( new WooCommerce() )->register();
		( new WooCommerceStripe() )->register();

		$protections = ( new SafePreviews() )->protections();

		// The pre-0.3.0 built-in set, now assembled through the filter.
		$this->assertSame(
			array( 'mail', 'woocommerce-webhooks', 'woocommerce-stripe' ),
			array_keys( $protections )
		);

		foreach ( $protections as $id => $protection ) {
			$this->assertIsCallable( $protection['register'], "{$id} register" );
			$this->assertIsCallable( $protection['status'], "{$id} status" );
		}
	}

	public function test_consumer_filters_can_remove_integration_contributions(): void {
		( new WooCommerce() )->register();

		// Integrations contribute at priority 5; consumer filters at the
		// default 10 run later and win.
		add_filter(
			'upsun_safe_previews_actions',
			function ( array $protections ) {
				unset( $protections['woocommerce-webhooks'] );

				return $protections;
			}
		);

		$this->assertArrayNotHasKey( 'woocommerce-webhooks', ( new SafePreviews() )->protections() );
	}

	public function test_labels_and_detection(): void {
		$woocommerce = new WooCommerce();
		$stripe      = new WooCommerceStripe();

		$this->assertSame( 'WooCommerce', $woocommerce->label() );
		$this->assertSame( 'WooCommerce Stripe', $stripe->label() );
		// Neither target plugin exists in the test environment.
		$this->assertFalse( $woocommerce->is_active() );
		$this->assertFalse( $stripe->is_active() );
	}
}
