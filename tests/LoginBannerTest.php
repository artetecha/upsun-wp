<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\EnvironmentIndicator;

final class LoginBannerTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	public function test_banner_shows_branch_and_type(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=development' );
		putenv( 'PLATFORM_BRANCH=pr-12' );
		\Upsun\Environment::reset();

		$html = ( new EnvironmentIndicator() )->login_banner( '<p>existing</p>' );

		$this->assertStringContainsString( 'upsun-login-banner', $html );
		$this->assertStringContainsString( 'pr-12', $html );
		$this->assertStringContainsString( 'development', $html );
		$this->assertStringContainsString( '#6d28d9', $html ); // Development color.
		// The existing login message is preserved, after the banner.
		$this->assertStringEndsWith( '<p>existing</p>', $html );
	}

	public function test_production_uses_production_color(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		putenv( 'PLATFORM_BRANCH=main' );
		\Upsun\Environment::reset();

		$html = ( new EnvironmentIndicator() )->login_banner( '' );

		$this->assertStringContainsString( '#00753b', $html );
	}

	public function test_unknown_type_uses_fallback_color(): void {
		$html = ( new EnvironmentIndicator() )->login_banner( '' );

		$this->assertStringContainsString( '#50575e', $html );
	}

	public function test_filter_disables_the_banner(): void {
		add_filter( 'upsun_login_banner', '__return_false' );

		$this->assertSame( '<p>existing</p>', ( new EnvironmentIndicator() )->login_banner( '<p>existing</p>' ) );
	}

	public function test_non_string_message_is_tolerated(): void {
		$html = ( new EnvironmentIndicator() )->login_banner( null );

		$this->assertStringContainsString( 'upsun-login-banner', $html );
	}
}
