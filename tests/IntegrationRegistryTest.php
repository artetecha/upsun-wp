<?php

use PHPUnit\Framework\TestCase;
use Upsun\Integration;
use Upsun\IntegrationRegistry;

final class UpsunTestIntegration implements Integration {

	public static int $registered = 0;

	public function label(): string {
		return 'Test Integration';
	}

	public function is_active(): bool {
		return true;
	}

	public function register(): void {
		self::$registered++;
	}
}

final class IntegrationRegistryTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		UpsunTestIntegration::$registered = 0;
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	private function on_platform(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
	}

	public function test_boot_is_a_noop_off_platform(): void {
		add_filter(
			'upsun_integrations',
			function () {
				return array( 'test' => UpsunTestIntegration::class );
			}
		);

		IntegrationRegistry::boot();

		$this->assertSame( 0, UpsunTestIntegration::$registered );
		$this->assertSame( array(), IntegrationRegistry::status() );
	}

	public function test_boot_registers_integrations_on_platform(): void {
		$this->on_platform();

		add_filter(
			'upsun_integrations',
			function () {
				return array( 'test' => UpsunTestIntegration::class );
			}
		);

		IntegrationRegistry::boot();

		$this->assertSame( 1, UpsunTestIntegration::$registered );
		$this->assertSame( 'loaded', IntegrationRegistry::status()['test']['state'] );
		$this->assertInstanceOf( UpsunTestIntegration::class, IntegrationRegistry::instance( 'test' ) );
	}

	public function test_defaults_boot_the_woocommerce_integrations(): void {
		$this->on_platform();

		IntegrationRegistry::boot();
		$status = IntegrationRegistry::status();

		$this->assertSame( 'loaded', $status['woocommerce']['state'] );
		$this->assertSame( 'loaded', $status['woocommerce-stripe']['state'] );

		// Their contributions are on the public filters.
		$protections = apply_filters( 'upsun_safe_previews_actions', array() );
		$this->assertArrayHasKey( 'woocommerce-webhooks', $protections );
		$this->assertArrayHasKey( 'woocommerce-stripe', $protections );
	}

	public function test_filter_removed_defaults_are_reported(): void {
		$this->on_platform();

		add_filter(
			'upsun_integrations',
			function () {
				return array();
			}
		);

		IntegrationRegistry::boot();
		$status = IntegrationRegistry::status();

		$this->assertSame( 'filter', $status['woocommerce']['state'] );
		$this->assertSame( 'filter', $status['woocommerce-stripe']['state'] );
	}

	public function test_constant_disables_an_integration(): void {
		$this->on_platform();

		define( 'UPSUN_DISABLE_INTEGRATION_FAKE_TARGET', true );

		add_filter(
			'upsun_integrations',
			function () {
				return array( 'fake-target' => UpsunTestIntegration::class );
			}
		);

		IntegrationRegistry::boot();

		$this->assertSame( 0, UpsunTestIntegration::$registered );
		$this->assertSame( 'constant', IntegrationRegistry::status()['fake-target']['state'] );
	}

	public function test_unknown_classes_are_reported_missing(): void {
		$this->on_platform();

		add_filter(
			'upsun_integrations',
			function () {
				return array( 'ghost' => 'Upsun\\Does\\Not\\Exist' );
			}
		);

		IntegrationRegistry::boot();

		$this->assertSame( 'missing', IntegrationRegistry::status()['ghost']['state'] );
		$this->assertNull( IntegrationRegistry::instance( 'ghost' ) );
	}
}
