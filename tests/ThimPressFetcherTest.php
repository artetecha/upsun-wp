<?php

use PHPUnit\Framework\TestCase;
use Upsun\Fetchers\ThimPressFetcher;
use Upsun\Fetchers\TransientFetcher;
use Upsun\Vendor;

/*
 * WordPress + thim-core stubs the standalone harness doesn't provide. All
 * guarded so they don't collide with other test files or a future bootstrap
 * stub, and driven by globals so they stay benign for tests that don't set them.
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return $GLOBALS['upsun_test_http_response'] ?? new WP_Error();
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? ( $r['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = null ) {
		return new Upsun_Test_Theme();
	}
	class Upsun_Test_Theme {
		public function get_template(): string {
			return (string) ( $GLOBALS['upsun_test_theme_template'] ?? '' );
		}
	}
}

/* Minimal thim-core surface the ThimPressFetcher drives. */
if ( ! class_exists( 'Thim_Admin_Config' ) ) {
	class Thim_Admin_Config {
		public static function get( $key ) {
			$map = array(
				'api_update_plugins' => 'https://downloads.thimpress.test/plugins',
				'api_thim_market'    => 'https://updates.thimpress.test/market',
			);
			return $map[ $key ] ?? '';
		}
	}
	class Thim_Remote_Helper {
		public static function post( $url, $args, $json ) {
			return array(
				(object) array( 'slug' => 'learnpress-stripe', 'version' => '4.2.0' ),
				(object) array( 'slug' => 'thim-core', 'version' => '2.1.5' ),
			);
		}
	}
	class Thim_Test_External_Plugin {
		public function __construct( private string $slug ) {}
		public function get_slug(): string {
			return $this->slug;
		}
	}
	class Thim_Plugins_Manager {
		public static function get_external_plugins(): array {
			return array(
				new Thim_Test_External_Plugin( 'learnpress-stripe' ),
				new Thim_Test_External_Plugin( 'thim-core' ),
			);
		}
		public static function get_link_download_plugin( $slug ) {
			return 'https://updates.thimpress.test/market/license/download?token=SECRET&slug=' . $slug;
		}
	}
	class Thim_Product_Registration {
		public static function get_data_theme_register( $key ) {
			return 'PURCHASE-TOKEN';
		}
		public static function get_url_download_theme() {
			return 'https://updates.thimpress.test/market/license/download?token=SECRET&theme=eduma';
		}
	}
}

final class ThimPressFetcherTest extends TestCase {

	private ThimPressFetcher $fetcher;

	protected function setUp(): void {
		upsun_test_reset_hooks();
		$this->fetcher                          = new ThimPressFetcher();
		$GLOBALS['upsun_test_theme_template']   = 'eduma';
		$GLOBALS['upsun_test_http_response']    = null;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['upsun_test_theme_template'], $GLOBALS['upsun_test_http_response'] );
	}

	public function test_identity_and_availability(): void {
		$this->assertSame( 'thimpress', $this->fetcher->id() );
		$this->assertSame( 'ThimPress', $this->fetcher->label() );
		$this->assertTrue( $this->fetcher->is_available(), 'thim-core stubs are present' );
	}

	public function test_supports_gates_to_thimpress_packages(): void {
		$this->assertTrue( $this->fetcher->supports( 'eduma', 'theme' ) );               // active theme
		$this->assertTrue( $this->fetcher->supports( 'learnpress-stripe', 'plugin' ) );  // in catalog
		$this->assertFalse( $this->fetcher->supports( 'fluentformpro', 'plugin' ) );     // not thim
		$this->assertFalse( $this->fetcher->supports( 'twentytwentyfive', 'theme' ) );   // not the active theme
	}

	public function test_theme_update_resolves_version_and_licensed_url(): void {
		$GLOBALS['upsun_test_http_response'] = array(
			'code' => 200,
			'body' => json_encode( array( 'status' => 'success', 'data' => array( 'version' => '5.5.0' ) ) ),
		);

		$update = $this->fetcher->available_update( 'eduma', 'theme' );

		$this->assertSame( '5.5.0', $update['version'] );
		$this->assertStringContainsString( 'token=SECRET', $update['url'] );
		$this->assertSame( array(), $update['headers'] );
	}

	public function test_theme_update_null_when_market_unreachable(): void {
		$GLOBALS['upsun_test_http_response'] = new WP_Error();

		$this->assertNull( $this->fetcher->available_update( 'eduma', 'theme' ) );
	}

	public function test_plugin_update_resolves_from_catalog(): void {
		$update = $this->fetcher->available_update( 'learnpress-stripe', 'plugin' );

		$this->assertSame( '4.2.0', $update['version'] );
		$this->assertStringContainsString( 'slug=learnpress-stripe', $update['url'] );
	}

	public function test_non_catalog_plugin_returns_null(): void {
		$this->assertNull( $this->fetcher->available_update( 'fluentformpro', 'plugin' ) );
	}

	public function test_registered_as_a_builtin_before_the_transient_fallback(): void {
		$fetchers = Vendor::fetchers();
		$ids      = array_map( static fn ( $f ) => $f->id(), $fetchers );

		$this->assertContains( 'thimpress', $ids );
		$this->assertInstanceOf( TransientFetcher::class, end( $fetchers ), 'transient stays the last fallback' );
		$this->assertLessThan(
			array_search( 'transient', $ids, true ),
			array_search( 'thimpress', $ids, true ),
			'thimpress is tried before transient'
		);
	}

	public function test_fetcher_status_reports_labels_and_availability(): void {
		$status = Vendor::fetcher_status();
		$byId   = array();
		foreach ( $status as $row ) {
			$byId[ $row['id'] ] = $row;
		}

		$this->assertSame( 'ThimPress', $byId['thimpress']['label'] );
		$this->assertTrue( $byId['thimpress']['available'] );
		$this->assertTrue( $byId['transient']['available'] );
	}

	public function test_disable_constant_name(): void {
		$this->assertSame( 'UPSUN_DISABLE_FETCHER_THIMPRESS', Vendor::fetcher_disable_constant_name( 'thimpress' ) );
	}
}
