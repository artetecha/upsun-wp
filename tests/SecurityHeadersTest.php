<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\SecurityHeaders;

final class SecurityHeadersTest extends TestCase {

	protected function setUp(): void {
		upsun_test_reset_hooks();
		upsun_test_clear_env();

		foreach ( array(
			'HTTPS',
			'HTTP_X_FORWARDED_PROTO',
			'SERVER_PORT',
			'HTTP_CF_RAY',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CF_VISITOR',
			'REMOTE_ADDR',
		) as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	private function https_direct(): array {
		return array( 'HTTP_X_FORWARDED_PROTO' => 'https' );
	}

	private function https_via_cloudflare(): array {
		return array(
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'HTTP_CF_RAY'            => 'a1b2c3-MXP',
		);
	}

	/* ---- Baseline headers -------------------------------------------- */

	public function test_baseline_headers_are_always_present(): void {
		foreach ( array( true, false ) as $is_production ) {
			$headers = SecurityHeaders::headers( array(), $is_production );

			$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
			$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
			$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		}
	}

	/* ---- HTTPS detection --------------------------------------------- */

	public function test_is_https_recognises_each_signal(): void {
		$this->assertTrue( SecurityHeaders::is_https( array( 'HTTPS' => 'on' ) ) );
		$this->assertTrue( SecurityHeaders::is_https( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ) ) );
		$this->assertTrue( SecurityHeaders::is_https( array( 'SERVER_PORT' => '443' ) ) );
		$this->assertTrue( SecurityHeaders::is_https( array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ) );
	}

	public function test_is_https_is_false_for_plaintext(): void {
		$this->assertFalse( SecurityHeaders::is_https( array() ) );
		$this->assertFalse( SecurityHeaders::is_https( array( 'HTTP_X_FORWARDED_PROTO' => 'http', 'SERVER_PORT' => '80' ) ) );
	}

	/* ---- HSTS decision ----------------------------------------------- */

	public function test_hsts_emitted_on_direct_https_production(): void {
		$this->assertTrue( SecurityHeaders::should_emit_hsts( $this->https_direct(), true ) );

		$headers = SecurityHeaders::headers( $this->https_direct(), true );
		$this->assertSame( SecurityHeaders::HSTS_DEFAULT, $headers['Strict-Transport-Security'] );
	}

	public function test_hsts_deferred_when_cloudflare_fronts_the_request(): void {
		$this->assertFalse( SecurityHeaders::should_emit_hsts( $this->https_via_cloudflare(), true ) );

		$headers = SecurityHeaders::headers( $this->https_via_cloudflare(), true );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
	}

	public function test_hsts_not_emitted_over_plaintext(): void {
		$this->assertFalse( SecurityHeaders::should_emit_hsts( array(), true ) );
	}

	public function test_hsts_not_emitted_outside_production_by_default(): void {
		$this->assertFalse( SecurityHeaders::should_emit_hsts( $this->https_direct(), false ) );
	}

	/* ---- HSTS disposition (what we report) --------------------------- */

	public function test_hsts_disposition_covers_the_three_states(): void {
		$this->assertSame( 'emitted', SecurityHeaders::hsts_disposition( $this->https_direct(), true ) );
		$this->assertSame( 'deferred-cloudflare', SecurityHeaders::hsts_disposition( $this->https_via_cloudflare(), true ) );
		$this->assertSame( 'off', SecurityHeaders::hsts_disposition( array(), true ) );      // plaintext
		$this->assertSame( 'off', SecurityHeaders::hsts_disposition( $this->https_direct(), false ) ); // non-prod
	}

	/* ---- Filters ------------------------------------------------------ */

	public function test_hsts_filter_can_force_on_in_non_production(): void {
		add_filter( 'upsun_security_hsts', '__return_true' );

		$this->assertTrue( SecurityHeaders::should_emit_hsts( $this->https_direct(), false ) );
	}

	public function test_hsts_filter_can_force_off_in_production(): void {
		add_filter( 'upsun_security_hsts', '__return_false' );

		$this->assertFalse( SecurityHeaders::should_emit_hsts( $this->https_direct(), true ) );
	}

	public function test_hsts_value_is_filterable(): void {
		add_filter(
			'upsun_security_hsts_value',
			static fn () => 'max-age=63072000; includeSubDomains'
		);

		$headers = SecurityHeaders::headers( $this->https_direct(), true );
		$this->assertSame( 'max-age=63072000; includeSubDomains', $headers['Strict-Transport-Security'] );
	}

	public function test_headers_filter_can_add_and_remove(): void {
		add_filter(
			'upsun_security_headers',
			static function ( array $headers ) {
				$headers['X-Frame-Options']         = '';           // drop it
				$headers['Permissions-Policy']      = 'browsing-topics=()'; // add one
				return $headers;
			}
		);

		$headers = SecurityHeaders::headers( array(), true );

		$this->assertArrayNotHasKey( 'X-Frame-Options', $headers );
		$this->assertSame( 'browsing-topics=()', $headers['Permissions-Policy'] );
	}

	public function test_header_values_cannot_smuggle_crlf(): void {
		add_filter(
			'upsun_security_headers',
			static function ( array $headers ) {
				$headers['X-Frame-Options'] = "SAMEORIGIN\r\nSet-Cookie: pwned=1";
				return $headers;
			}
		);

		$headers = SecurityHeaders::headers( array(), true );

		$this->assertSame( 'SAMEORIGINSet-Cookie: pwned=1', $headers['X-Frame-Options'] );
		$this->assertStringNotContainsString( "\n", $headers['X-Frame-Options'] );
	}

	/* ---- Site Health message ----------------------------------------- */

	public function test_check_reports_emitted_on_direct_production_https(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		\Upsun\Environment::reset();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$result = SecurityHeaders::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'HSTS is emitted here', $result['message'] );
	}

	public function test_check_reports_deferral_when_fronted_by_cloudflare(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		\Upsun\Environment::reset();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$_SERVER['HTTP_CF_RAY']            = 'a1b2c3-MXP';

		$result = SecurityHeaders::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'deferred to Cloudflare', $result['message'] );
	}

	public function test_check_reports_off_on_non_production(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=staging' );
		\Upsun\Environment::reset();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$result = SecurityHeaders::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'not asserted', $result['message'] );
	}
}
