<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\SafePreviews;

final class SafePreviewsTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_options'] = array();
	}

	private function fake_preview_env( string $name = 'pr-42' ): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=' . $name );
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=development' );
		\Upsun\Environment::reset();
	}

	private function fake_production_env( string $name = 'main' ): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=' . $name );
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		\Upsun\Environment::reset();
	}

	public function test_enabled_filter_gates_should_load(): void {
		$this->assertTrue( ( new SafePreviews() )->should_load() );

		add_filter( 'upsun_safe_previews_enabled', '__return_false' );

		$this->assertFalse( ( new SafePreviews() )->should_load() );
	}

	/* Mail policy. */

	public function test_mail_mode_defaults_to_intercept(): void {
		$this->assertSame( 'intercept', ( new SafePreviews() )->mail_mode() );
	}

	public function test_mail_mode_allow_and_redirect(): void {
		$module = new SafePreviews();

		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'allow';
			}
		);
		$this->assertSame( 'allow', $module->mail_mode() );

		upsun_test_reset_hooks();
		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'redirect: qa@example.com ';
			}
		);
		$this->assertSame( 'redirect:qa@example.com', $module->mail_mode() );
	}

	public function test_malformed_redirect_fails_safe_to_intercept(): void {
		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'redirect:not-an-email';
			}
		);

		$this->assertSame( 'intercept', ( new SafePreviews() )->mail_mode() );
	}

	public function test_intercept_short_circuits_and_logs(): void {
		$this->fake_preview_env();
		$GLOBALS['upsun_test_options'][ SafePreviews::MAIL_LOG_OPTION ] = array();

		$result = ( new SafePreviews() )->maybe_intercept_mail(
			null,
			array(
				'to'      => array( 'someone@example.com' ),
				'subject' => 'Order receipt',
			)
		);

		$this->assertTrue( $result );

		$log = $GLOBALS['upsun_test_options'][ SafePreviews::MAIL_LOG_OPTION ];
		$this->assertCount( 1, $log );
		$this->assertSame( 'someone@example.com', $log[0]['to'] );
		$this->assertSame( 'Order receipt', $log[0]['subject'] );
	}

	public function test_mail_status_reports_the_platform_relay_state(): void {
		$this->fake_preview_env();
		$GLOBALS['upsun_test_options'][ SafePreviews::MAIL_LOG_OPTION ] = array();

		// PLATFORM_SMTP_HOST unset: previews block the platform proxy by default.
		$status = ( new SafePreviews() )->mail_status();
		$this->assertStringContainsString( 'platform email off', $status['detail'] );

		putenv( 'PLATFORM_SMTP_HOST=smtp.internal' );
		$status = ( new SafePreviews() )->mail_status();
		$this->assertStringContainsString( 'platform email on', $status['detail'] );
	}

	public function test_allow_mode_does_not_intercept(): void {
		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'allow';
			}
		);

		$this->assertNull( ( new SafePreviews() )->maybe_intercept_mail( null, array( 'to' => 'a@b.test' ) ) );
	}

	public function test_redirect_rewrites_recipients_and_keeps_original_header(): void {
		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'redirect:qa@example.com';
			}
		);

		$atts = ( new SafePreviews() )->maybe_redirect_mail(
			array(
				'to'      => array( 'one@example.com', 'two@example.com' ),
				'headers' => array(),
			)
		);

		$this->assertSame( 'qa@example.com', $atts['to'] );
		$this->assertContains( 'X-Upsun-Original-To: one@example.com, two@example.com', $atts['headers'] );
	}

	public function test_redirect_appends_to_string_headers(): void {
		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'redirect:qa@example.com';
			}
		);

		$atts = ( new SafePreviews() )->maybe_redirect_mail(
			array(
				'to'      => 'one@example.com',
				'headers' => "From: KEDS <noreply@example.com>\r\n",
			)
		);

		$this->assertStringContainsString( "From: KEDS <noreply@example.com>\r\nX-Upsun-Original-To: one@example.com", $atts['headers'] );
	}

	/* WooCommerce Stripe. */

	public function test_stripe_settings_forced_into_test_mode(): void {
		$settings = ( new SafePreviews() )->force_stripe_test_mode( array( 'testmode' => 'no' ) );

		$this->assertSame( 'yes', $settings['testmode'] );
	}

	public function test_stripe_non_array_settings_pass_through(): void {
		$this->assertFalse( ( new SafePreviews() )->force_stripe_test_mode( false ) );
	}

	public function test_stripe_forcing_can_be_opted_out(): void {
		add_filter( 'upsun_safe_previews_stripe_test_mode', '__return_false' );

		$settings = ( new SafePreviews() )->force_stripe_test_mode( array( 'testmode' => 'no' ) );

		$this->assertSame( 'no', $settings['testmode'] );
	}

	/* WooCommerce webhooks. */

	public function test_webhook_delivery_paused(): void {
		$this->assertFalse( ( new SafePreviews() )->maybe_pause_webhook( true ) );
	}

	public function test_webhook_pause_can_be_opted_out(): void {
		add_filter( 'upsun_safe_previews_pause_webhooks', '__return_false' );

		$this->assertTrue( ( new SafePreviews() )->maybe_pause_webhook( true ) );
	}

	/* Fresh-clone detection. */

	public function test_fresh_clone_fires_sanitize_once_and_restamps(): void {
		$this->fake_preview_env( 'pr-42' );
		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] = 'main';

		$received = array();
		add_action(
			SafePreviews::SANITIZE_HOOK,
			function ( $previous, $current ) use ( &$received ) {
				$received[] = array( $previous, $current );
			}
		);

		$module = new SafePreviews();
		$module->check_environment_stamp();

		$this->assertSame( array( array( 'main', 'pr-42' ) ), $received );
		$this->assertSame( 'pr-42', $GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] );

		// Stamp now matches: no re-trigger.
		$module->check_environment_stamp();
		$this->assertCount( 1, $received );
	}

	public function test_production_refreshes_stamp_without_sanitizing(): void {
		$this->fake_production_env( 'main' );
		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] = 'old-name';

		( new SafePreviews() )->check_environment_stamp();

		$this->assertSame( 'main', $GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] );
		$this->assertArrayNotHasKey( SafePreviews::SANITIZE_HOOK, $GLOBALS['upsun_test_fired'] );
	}

	public function test_matching_stamp_is_untouched(): void {
		$this->fake_preview_env( 'pr-42' );
		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] = 'pr-42';

		( new SafePreviews() )->check_environment_stamp();

		$this->assertArrayNotHasKey( SafePreviews::SANITIZE_HOOK, $GLOBALS['upsun_test_fired'] );
		$this->assertArrayNotHasKey( SafePreviews::SANITIZED_OPTION, $GLOBALS['upsun_test_options'] );
	}

	public function test_run_sanitize_reports_listeners_and_stamps(): void {
		$this->fake_preview_env( 'pr-42' );

		$result = ( new SafePreviews() )->run_sanitize( 'main' );

		$this->assertSame( 'main', $result['previous'] );
		$this->assertSame( 'pr-42', $result['environment'] );
		$this->assertFalse( $result['listeners'] );
		$this->assertCount( 1, $GLOBALS['upsun_test_fired'][ SafePreviews::SANITIZE_HOOK ] );
		$this->assertEqualsWithDelta( time(), $GLOBALS['upsun_test_options'][ SafePreviews::SANITIZED_OPTION ], 2 );
	}

	/* Registry and check wiring. */

	public function test_protections_registry_is_filterable(): void {
		add_filter(
			'upsun_safe_previews_actions',
			function ( array $protections ) {
				$protections['fluentcrm'] = array(
					'label'    => 'FluentCRM',
					'register' => '__return_true',
					'status'   => '__return_true',
				);
				unset( $protections['woocommerce-webhooks'] );

				return $protections;
			}
		);

		$protections = ( new SafePreviews() )->protections();

		$this->assertArrayHasKey( 'fluentcrm', $protections );
		$this->assertArrayNotHasKey( 'woocommerce-webhooks', $protections );
		$this->assertArrayHasKey( 'mail', $protections );
	}

	public function test_register_joins_check_and_panel_registries(): void {
		$this->fake_preview_env();

		( new SafePreviews() )->register();

		$checks = \Upsun\Modules\SiteHealth::checks();
		$this->assertArrayHasKey( 'preview_safety', $checks );

		$panels = apply_filters( 'upsun_dashboard_panels', array() );
		$this->assertArrayHasKey( 'preview-safety', $panels );
	}

	public function test_boot_check_is_opt_in(): void {
		$this->fake_preview_env();

		( new SafePreviews() )->register();
		$this->assertArrayNotHasKey( 'init', $GLOBALS['upsun_test_actions'] );

		add_filter( 'upsun_safe_previews_boot_check', '__return_true' );

		( new SafePreviews() )->register();
		$this->assertArrayHasKey( 'init', $GLOBALS['upsun_test_actions'] );
	}

	public function test_is_sanitized_tracks_the_stamp(): void {
		$this->fake_preview_env( 'pr-42' );
		$module = new SafePreviews();

		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] = 'main';
		$this->assertFalse( $module->is_sanitized() );

		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] = 'pr-42';
		$this->assertTrue( $module->is_sanitized() );
	}

	public function test_check_passes_on_production_with_current_stamp(): void {
		$this->fake_production_env( 'main' );
		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ] = 'main';

		$result = ( new SafePreviews() )->check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'Production environment', $result['message'] );
	}

	public function test_check_warns_on_production_without_stamp(): void {
		$this->fake_production_env( 'main' );

		$result = ( new SafePreviews() )->check();

		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'post_deploy', $result['message'] );
	}

	public function test_check_passes_on_sanitized_preview_with_defaults(): void {
		$this->fake_preview_env( 'pr-42' );
		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ]    = 'pr-42';
		$GLOBALS['upsun_test_options'][ SafePreviews::MAIL_LOG_OPTION ] = array();

		$result = ( new SafePreviews() )->check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'Outbound mail: intercepted', $result['message'] );
	}

	public function test_check_warns_on_unsanitized_preview(): void {
		$this->fake_preview_env( 'pr-42' );
		$GLOBALS['upsun_test_options'][ SafePreviews::STAMP_OPTION ]    = 'main';
		$GLOBALS['upsun_test_options'][ SafePreviews::MAIL_LOG_OPTION ] = array();

		$result = ( new SafePreviews() )->check();

		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'wp upsun sanitize --if-needed', $result['message'] );
	}

	public function test_check_warns_when_a_protection_is_disabled_by_filter(): void {
		$this->fake_preview_env();
		add_filter(
			'upsun_safe_previews_mail',
			function () {
				return 'allow';
			}
		);

		$result = ( new SafePreviews() )->check();

		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'sending allowed', $result['message'] );
	}
}
