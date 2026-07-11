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
		unset( $GLOBALS['menu'] );
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
			$this->assertContains( $panel['context'], array( 'normal', 'side', 'column3', 'column4' ), "panel {$id} context" );
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

	public function test_menu_sits_directly_below_the_wp_dashboard(): void {
		$this->assertSame( 2, ( new Dashboard() )->menu_position() );

		add_filter(
			'upsun_dashboard_menu_position',
			function () {
				return 99;
			}
		);

		$this->assertSame( 99, ( new Dashboard() )->menu_position() );
	}

	public function test_pin_menu_position_beats_the_collision_lottery(): void {
		// Position-2 squatters get md5-fraction keys from core; Upsun drew
		// the worst one. The pin must land it directly below Dashboard.
		$GLOBALS['menu'] = array(
			'2'       => array( 'Dashboard', 'read', 'index.php' ),
			'2.00007' => array( 'FluentCRM', 'manage_options', 'fluentcrm-admin' ),
			'2.32'    => array( 'Eduma', 'manage_options', 'thim-core' ),
			'2.53'    => array( 'Upsun', 'manage_options', 'upsun' ),
			'5'       => array( 'Posts', 'edit_posts', 'edit.php' ),
		);

		( new Dashboard() )->pin_menu_position();

		$this->assertSame(
			array( 'index.php', 'upsun', 'fluentcrm-admin', 'thim-core', 'edit.php' ),
			array_column( array_values( $GLOBALS['menu'] ), 2 )
		);
	}

	public function test_pin_menu_position_respects_a_filtered_position(): void {
		$GLOBALS['menu'] = array(
			'2'  => array( 'Dashboard', 'read', 'index.php' ),
			'99' => array( 'Upsun', 'manage_options', 'upsun' ),
		);

		add_filter(
			'upsun_dashboard_menu_position',
			function () {
				return 99;
			}
		);

		( new Dashboard() )->pin_menu_position();

		$this->assertArrayHasKey( '99', $GLOBALS['menu'] );
		$this->assertSame( 'upsun', $GLOBALS['menu']['99'][2] );
	}

	public function test_menu_icon_is_the_upsun_mark_as_a_base64_svg(): void {
		$icon   = ( new Dashboard() )->menu_icon();
		$prefix = 'data:image/svg+xml;base64,';

		$this->assertStringStartsWith( $prefix, $icon );

		$svg = base64_decode( substr( $icon, strlen( $prefix ) ), true );
		$this->assertIsString( $svg );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 32 32"', $svg );

		add_filter( 'upsun_dashboard_menu_icon', function () {
			return 'dashicons-cloud';
		} );

		$this->assertSame( 'dashicons-cloud', ( new Dashboard() )->menu_icon() );
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

		// The core dashboard grid: four containers, one sortable each, and
		// the nonces postbox.js posts when persisting layout state.
		$this->assertStringContainsString( 'id="dashboard-widgets"', $html );
		$this->assertStringContainsString( 'postbox-container-4', $html );
		$this->assertStringContainsString( 'normal-sortables', $html );
		$this->assertStringContainsString( 'column4-sortables', $html );
		$this->assertStringContainsString( 'meta-box-order-nonce', $html );
		$this->assertStringContainsString( 'closedpostboxesnonce', $html );

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
