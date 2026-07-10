<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\Dashboard;
use Upsun\ModuleRegistry;

final class DashboardTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	public function test_default_panels_are_registered(): void {
		$panels = ( new Dashboard() )->panels();

		$this->assertSame(
			array( 'environment', 'services', 'health', 'caching', 'modules' ),
			array_keys( $panels )
		);

		foreach ( $panels as $id => $panel ) {
			$this->assertIsCallable( $panel['render'], "panel {$id} render callback" );
			$this->assertNotSame( '', (string) $panel['title'], "panel {$id} title" );
		}
	}

	public function test_panels_filter_can_add_and_remove(): void {
		add_filter(
			'upsun_dashboard_panels',
			function ( array $panels ) {
				unset( $panels['smtp'], $panels['caching'] );
				$panels['custom'] = array(
					'title'  => 'Custom',
					'render' => function () {
						echo 'custom-panel-body';
					},
				);

				return $panels;
			}
		);

		$panels = ( new Dashboard() )->panels();

		$this->assertArrayNotHasKey( 'caching', $panels );
		$this->assertArrayHasKey( 'custom', $panels );
	}

	public function test_render_page_outputs_all_default_panels(): void {
		ob_start();
		( new Dashboard() )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'upsun-panel-environment', $html );
		$this->assertStringContainsString( 'upsun-panel-services', $html );
		$this->assertStringContainsString( 'upsun-panel-health', $html );
		$this->assertStringContainsString( 'upsun-panel-caching', $html );
		$this->assertStringContainsString( 'upsun-panel-modules', $html );

		// Health checks actually ran and rendered rows.
		$this->assertStringContainsString( 'Cron configuration', $html );

		// The flush action form is present and points at admin-post.php.
		$this->assertStringContainsString( 'upsun_flush_object_cache', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );

		// Off-platform, unbooted: the modules panel explains itself.
		$this->assertStringContainsString( 'No modules booted', $html );
	}

	public function test_render_page_reflects_consumer_cache_filters(): void {
		add_filter(
			'upsun_page_cache_ttl',
			function () {
				return 1200;
			}
		);
		add_filter(
			'upsun_page_cache_strip_cookies',
			function ( array $cookies ) {
				$cookies[] = 'lp_session_guest';

				return $cookies;
			}
		);

		ob_start();
		( new Dashboard() )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 's-maxage=1200', $html );
		$this->assertStringContainsString( 'lp_session_guest', $html );
	}

	public function test_custom_panel_renders_via_filter(): void {
		add_filter(
			'upsun_dashboard_panels',
			function ( array $panels ) {
				return array(
					'only' => array(
						'title'  => 'Only Panel',
						'render' => function () {
							echo 'only-panel-body';
						},
					),
				);
			}
		);

		ob_start();
		( new Dashboard() )->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'only-panel-body', $html );
		$this->assertStringNotContainsString( 'upsun-panel-environment', $html );
	}

	public function test_modules_panel_reports_boot_status(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		\Upsun\Environment::reset();

		add_filter(
			'upsun_mu_modules',
			function () {
				return array( 'test' => UpsunTestModule::class );
			}
		);

		ModuleRegistry::boot();

		ob_start();
		( new Dashboard() )->render_modules_panel();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'test', $html );
		$this->assertStringContainsString( 'loaded', $html );
		// Defaults removed by the consumer filter are reported as such.
		$this->assertStringContainsString( 'removed by the upsun_mu_modules filter', $html );

		// Reset registry state for other tests.
		upsun_test_reset_hooks();
		upsun_test_clear_env();
		ModuleRegistry::boot();
	}
}
