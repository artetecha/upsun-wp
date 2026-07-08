<?php

use PHPUnit\Framework\TestCase;
use Upsun\Environment;

final class EnvironmentTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
	}

	public function test_is_upsun_requires_both_variables(): void {
		$this->assertFalse( Environment::is_upsun() );

		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		$this->assertFalse( Environment::is_upsun(), 'application name alone is not enough (build hooks)' );

		putenv( 'PLATFORM_APPLICATION_NAME' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		$this->assertFalse( Environment::is_upsun(), 'environment alone is not enough' );

		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		$this->assertTrue( Environment::is_upsun() );
	}

	public function test_scalar_accessors(): void {
		$this->assertNull( Environment::name() );
		$this->assertNull( Environment::type() );
		$this->assertFalse( Environment::is_production() );

		putenv( 'PLATFORM_ENVIRONMENT=pr-42' );
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=development' );
		putenv( 'PLATFORM_BRANCH=feature/x' );
		putenv( 'PLATFORM_PROJECT=abc123' );

		$this->assertSame( 'pr-42', Environment::name() );
		$this->assertSame( 'development', Environment::type() );
		$this->assertSame( 'feature/x', Environment::branch() );
		$this->assertSame( 'abc123', Environment::project() );
		$this->assertFalse( Environment::is_production() );

		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		$this->assertTrue( Environment::is_production() );
	}

	public function test_smtp_host_empty_string_means_disabled(): void {
		$this->assertNull( Environment::smtp_host() );

		putenv( 'PLATFORM_SMTP_HOST=' );
		$this->assertNull( Environment::smtp_host() );

		putenv( 'PLATFORM_SMTP_HOST=smtp.internal' );
		$this->assertSame( 'smtp.internal', Environment::smtp_host() );
	}

	public function test_routes_decodes_base64_json(): void {
		$routes = array(
			'https://www.example.com/' => array(
				'primary'  => true,
				'type'     => 'upstream',
				'upstream' => 'app',
			),
			'https://example.com/'     => array(
				'primary' => false,
				'type'    => 'redirect',
				'to'      => 'https://www.example.com/',
			),
		);

		putenv( 'PLATFORM_ROUTES=' . base64_encode( json_encode( $routes ) ) );

		$this->assertSame( $routes, Environment::routes() );
		$this->assertSame( 'https://www.example.com/', Environment::primary_route() );
	}

	public function test_primary_route_prefers_upstream_over_redirect(): void {
		$routes = array(
			'https://example.com/'     => array(
				'primary' => true,
				'type'    => 'redirect',
			),
			'https://www.example.com/' => array(
				'primary' => true,
				'type'    => 'upstream',
			),
		);

		putenv( 'PLATFORM_ROUTES=' . base64_encode( json_encode( $routes ) ) );

		$this->assertSame( 'https://www.example.com/', Environment::primary_route() );
	}

	public function test_primary_route_falls_back_to_any_primary(): void {
		$routes = array(
			'https://example.com/' => array(
				'primary' => true,
				'type'    => 'redirect',
			),
		);

		putenv( 'PLATFORM_ROUTES=' . base64_encode( json_encode( $routes ) ) );

		$this->assertSame( 'https://example.com/', Environment::primary_route() );
	}

	public function test_malformed_base64_degrades_to_empty(): void {
		putenv( 'PLATFORM_ROUTES=!!!not-base64!!!' );

		$this->assertSame( array(), Environment::routes() );
		$this->assertNull( Environment::primary_route() );
	}

	public function test_malformed_json_degrades_to_empty(): void {
		putenv( 'PLATFORM_ROUTES=' . base64_encode( '{ not json' ) );

		$this->assertSame( array(), Environment::routes() );
	}

	public function test_non_array_json_degrades_to_empty(): void {
		putenv( 'PLATFORM_RELATIONSHIPS=' . base64_encode( '"just a string"' ) );

		$this->assertSame( array(), Environment::relationships() );
	}

	public function test_relationship_returns_first_instance(): void {
		$relationships = array(
			'database' => array(
				array(
					'scheme' => 'mysql',
					'host'   => 'db.internal',
					'port'   => 3306,
				),
			),
		);

		putenv( 'PLATFORM_RELATIONSHIPS=' . base64_encode( json_encode( $relationships ) ) );

		$this->assertSame( 'db.internal', Environment::relationship( 'database' )['host'] );
		$this->assertNull( Environment::relationship( 'missing' ) );
	}

	public function test_decoded_values_are_cached_until_reset(): void {
		putenv( 'PLATFORM_ROUTES=' . base64_encode( json_encode( array( 'https://a/' => array( 'primary' => true ) ) ) ) );
		$this->assertArrayHasKey( 'https://a/', Environment::routes() );

		// Changing the variable without reset returns the cached value.
		putenv( 'PLATFORM_ROUTES=' . base64_encode( json_encode( array( 'https://b/' => array( 'primary' => true ) ) ) ) );
		$this->assertArrayHasKey( 'https://a/', Environment::routes() );

		Environment::reset();
		$this->assertArrayHasKey( 'https://b/', Environment::routes() );
	}

	public function test_helpers_delegate(): void {
		$this->assertFalse( \Upsun\is_upsun() );
		$this->assertFalse( \Upsun\is_preview_environment() );
		$this->assertSame( '0.0.0-dev', \Upsun\version() );

		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=pr-1' );
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=development' );

		$this->assertTrue( \Upsun\is_upsun() );
		$this->assertTrue( \Upsun\is_preview_environment() );
		$this->assertSame( 'pr-1', \Upsun\environment_name() );

		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		$this->assertFalse( \Upsun\is_preview_environment() );
	}
}
