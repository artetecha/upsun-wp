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

/**
 * Define the WC_Stripe class marker at CALL time, not file-load time — a
 * class declaration nested in a plain function only executes when the
 * function runs, which lets test_labels_and_detection assert the class is
 * absent before the Stripe-keys panel tests bring it into existence.
 */
function upsun_test_define_wc_stripe(): void {
	if ( ! class_exists( 'WC_Stripe' ) ) {
		class WC_Stripe {}
	}
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
		add_filter( 'upsun_woocommerce_pause_webhooks', '__return_false' );

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
		add_filter( 'upsun_woocommerce_stripe_test_mode', '__return_false' );

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

	/* Stripe keys dashboard panel.
	 *
	 * ORDER-SENSITIVE: fake_wc_stripe() defines the WC_Stripe class for the
	 * rest of the process, so every test below must run AFTER
	 * test_labels_and_detection (which asserts the class is absent). PHPUnit
	 * runs methods in declaration order and this suite does not randomize —
	 * keep these at the bottom of the file.
	 */

	private function fake_wc_stripe(): void {
		upsun_test_define_wc_stripe();
	}

	private function seed_test_mode_settings( string $pk = 'pk_test_dead', string $sk = 'sk_test_dead' ): void {
		$GLOBALS['upsun_test_options']['woocommerce_stripe_settings'] = array(
			'testmode'             => 'yes',
			'test_publishable_key' => $pk,
			'test_secret_key'      => $sk,
		);
	}

	private function render_keys_panel(): string {
		ob_start();
		( new WooCommerceStripe() )->render_keys_panel();

		return (string) ob_get_clean();
	}

	public function test_stripe_keys_panel_joins_the_dashboard(): void {
		( new WooCommerceStripe() )->register();

		$panels = apply_filters( 'upsun_dashboard_panels', array() );

		$this->assertArrayHasKey( 'stripe-keys', $panels );
		$this->assertIsCallable( $panels['stripe-keys']['render'] );
	}

	public function test_stripe_keys_panel_reports_keys_stripe_rejects(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings();
		// Stripe answers 401 for both probes: dead publishable, dead secret.
		upsun_test_http_reset( array( array( 'code' => 401 ), array( 'code' => 401 ) ) );

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'INVALID', $html );
		$this->assertStringContainsString( 'Checkout will fail on this environment.', $html );

		$requests = $GLOBALS['upsun_test_http']['requests'];
		$this->assertCount( 2, $requests );
		// Publishable key probes the way stripe.js does; secret via account.
		$this->assertSame( 'https://api.stripe.com/v1/tokens', $requests[0]['url'] );
		$this->assertSame( 'https://api.stripe.com/v1/account', $requests[1]['url'] );
		$this->assertSame( 'Bearer sk_test_dead', $requests[1]['args']['headers']['Authorization'] );
	}

	public function test_stripe_keys_panel_reports_working_keys(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings( 'pk_test_live', 'sk_test_live' );
		// A valid publishable key answers 400 to a card-less tokens request
		// (only a dead key gets 401); a valid secret answers 200.
		upsun_test_http_reset( array( array( 'code' => 400 ), array( 'code' => 200 ) ) );

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'valid', $html );
		$this->assertStringNotContainsString( 'INVALID', $html );
		$this->assertStringNotContainsString( 'Checkout will fail', $html );
	}

	public function test_stripe_keys_verdicts_are_cached(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings( 'pk_test_cached', 'sk_test_cached' );
		upsun_test_http_reset( array( array( 'code' => 401 ), array( 'code' => 401 ) ) );

		$this->render_keys_panel();
		$first = count( $GLOBALS['upsun_test_http']['requests'] );
		$this->render_keys_panel();

		$this->assertSame( 2, $first );
		$this->assertCount( 2, $GLOBALS['upsun_test_http']['requests'], 'second render must serve verdicts from cache' );
	}

	public function test_stripe_keys_live_mode_is_never_probed(): void {
		$this->fake_wc_stripe();
		$GLOBALS['upsun_test_options']['woocommerce_stripe_settings'] = array( 'testmode' => 'no' );
		upsun_test_http_reset();

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'Live mode', $html );
		$this->assertCount( 0, $GLOBALS['upsun_test_http']['requests'] );
	}

	public function test_stripe_keys_missing_keys_warn_without_probing(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings( '', '' );
		upsun_test_http_reset();

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'missing', $html );
		$this->assertStringContainsString( 'Checkout will fail on this environment.', $html );
		$this->assertCount( 0, $GLOBALS['upsun_test_http']['requests'] );
	}

	public function test_stripe_keys_probing_can_be_disabled(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings();
		upsun_test_http_reset();
		add_filter( 'upsun_woocommerce_stripe_validate_keys', '__return_false' );

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'unverified (probing disabled)', $html );
		$this->assertCount( 0, $GLOBALS['upsun_test_http']['requests'] );
	}

	public function test_stripe_keys_unreachable_stripe_is_reported_not_guessed(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings( 'pk_test_x', 'sk_test_x' );
		upsun_test_http_reset( array( array( 'error' => 'timed out' ), array( 'error' => 'timed out' ) ) );

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'unverified (no conclusive answer from Stripe)', $html );
		$this->assertStringNotContainsString( 'INVALID', $html );
	}

	public function test_stripe_keys_rate_limits_and_outages_are_not_verdicts(): void {
		$this->fake_wc_stripe();
		$this->seed_test_mode_settings( 'pk_test_429', 'sk_test_429' );
		// A 429 (or any 5xx) proves nothing about the key: it must NOT be
		// cached as valid for 12 hours, which is what a naive
		// "anything-but-401 means accepted" mapping would do.
		upsun_test_http_reset( array( array( 'code' => 429 ), array( 'code' => 503 ) ) );

		$html = $this->render_keys_panel();

		$this->assertStringContainsString( 'unverified (no conclusive answer from Stripe)', $html );
		$this->assertStringNotContainsString( '>valid<', $html );
		$this->assertStringNotContainsString( 'INVALID', $html );
	}

	public function test_stripe_keys_same_string_in_both_fields_probes_both_endpoints(): void {
		$this->fake_wc_stripe();
		// Deliberately misconfigured: the same string in both key fields.
		// Each field must still get its own probe against its own endpoint —
		// a type-blind cache would decide the secret key's verdict from the
		// publishable-key probe and skip /v1/account entirely.
		$this->seed_test_mode_settings( 'sk_test_same', 'sk_test_same' );
		upsun_test_http_reset( array( array( 'code' => 400 ), array( 'code' => 401 ) ) );

		$html = $this->render_keys_panel();

		$requests = $GLOBALS['upsun_test_http']['requests'];
		$this->assertCount( 2, $requests );
		$this->assertSame( 'https://api.stripe.com/v1/tokens', $requests[0]['url'] );
		$this->assertSame( 'https://api.stripe.com/v1/account', $requests[1]['url'] );
		// And the verdicts stay independent: publishable valid, secret invalid.
		$this->assertStringContainsString( '>valid<', $html );
		$this->assertStringContainsString( 'INVALID', $html );
	}
}
