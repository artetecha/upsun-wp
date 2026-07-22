<?php

use PHPUnit\Framework\TestCase;
use Upsun\Vendor;

final class VendorTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_site_transients'] = array();
		$this->dir = sys_get_temp_dir() . '/upsun-vendor-' . getmypid() . '-' . uniqid();
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->dir );
		$GLOBALS['upsun_test_site_transients'] = array();
	}

	private function rrmdir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $item ) {
			$this->rrmdir( $path . '/' . $item );
		}
		rmdir( $path );
	}

	/* build_composer_json */

	public function test_build_composer_json_full_header(): void {
		$composer = Vendor::build_composer_json(
			array(
				'name'        => 'Learn<em>Press</em> Stripe',
				'uri'         => 'https://thimpress.com/product/stripe/',
				'version'     => '4.0.6',
				'description' => "Stripe   payment\n gateway",
				'author'      => '<a href="https://thimpress.com">ThimPress</a>',
				'author_uri'  => 'https://thimpress.com',
				'license'     => '',
			),
			'plugin',
			'learnpress-stripe',
			'keds-plugin'
		);

		$this->assertSame( 'keds-plugin/learnpress-stripe', $composer['name'] );
		$this->assertSame( 'wordpress-plugin', $composer['type'] );
		$this->assertSame( '4.0.6', $composer['version'] );
		$this->assertSame( 'https://thimpress.com/product/stripe/', $composer['homepage'] );
		$this->assertSame( 'proprietary', $composer['license'] );
		// HTML stripped, whitespace collapsed.
		$this->assertSame( 'Stripe payment gateway', $composer['description'] );
		$this->assertSame( 'ThimPress', $composer['authors'][0]['name'] );
		$this->assertSame( 'https://thimpress.com', $composer['authors'][0]['homepage'] );
		$this->assertArrayHasKey( 'composer/installers', $composer['require'] );
		$this->assertContains( 'wordpress-plugin', $composer['keywords'] );
	}

	public function test_build_composer_json_theme_and_license_override(): void {
		$composer = Vendor::build_composer_json(
			array( 'name' => 'Eduma', 'version' => '5.9.3', 'license' => 'GPL-2.0-or-later' ),
			'theme',
			'eduma',
			'keds-theme',
			'proprietary'
		);

		$this->assertSame( 'keds-theme/eduma', $composer['name'] );
		$this->assertSame( 'wordpress-theme', $composer['type'] );
		// Explicit override beats the header license.
		$this->assertSame( 'proprietary', $composer['license'] );
		$this->assertContains( 'wordpress-theme', $composer['keywords'] );
	}

	public function test_build_composer_json_omits_missing_optional_fields(): void {
		$composer = Vendor::build_composer_json(
			array( 'name' => 'Bare', 'version' => '' ),
			'plugin',
			'bare'
		);

		$this->assertSame( 'private/bare', $composer['name'] ); // Default vendor.
		$this->assertArrayNotHasKey( 'version', $composer );
		$this->assertArrayNotHasKey( 'homepage', $composer );
		$this->assertArrayNotHasKey( 'authors', $composer );
		$this->assertArrayNotHasKey( 'description', $composer );
		$this->assertSame( 'proprietary', $composer['license'] );
	}

	public function test_build_composer_json_uses_header_license_when_present(): void {
		$composer = Vendor::build_composer_json(
			array( 'name' => 'X', 'license' => 'MIT' ),
			'plugin',
			'x'
		);

		$this->assertSame( 'MIT', $composer['license'] );
	}

	/* classify_updates */

	private function plugin_transient( array $response ): object {
		return (object) array( 'response' => $response );
	}

	public function test_classify_distinguishes_wporg_from_external(): void {
		$plugins = $this->plugin_transient( array(
			'akismet/akismet.php' => (object) array(
				'slug'        => 'akismet',
				'new_version' => '5.8',
				'package'     => 'https://downloads.wordpress.org/plugin/akismet.5.8.zip',
				'id'          => 'w.org/plugins/akismet',
			),
			'learnpress-stripe/learnpress-stripe.php' => (object) array(
				'slug'        => 'learnpress-stripe',
				'new_version' => '4.0.7',
				'package'     => 'https://thimpress.com/downloads/stripe.zip',
				'id'          => 'thimpress.com/learnpress-stripe',
			),
			'no-id-premium/no-id-premium.php' => (object) array(
				'new_version' => '2.0',
				'package'     => '',
			),
		) );

		$rows = Vendor::classify_updates( $plugins, false );

		$this->assertCount( 3, $rows );

		$by_slug = array_column( $rows, 'source', 'slug' );
		$this->assertSame( 'wporg', $by_slug['akismet'] );
		$this->assertSame( 'external', $by_slug['learnpress-stripe'] );
		// No package and no w.org id => external (fails safe toward "vendored").
		$this->assertSame( 'external', $by_slug['no-id-premium'] );
	}

	public function test_classify_handles_theme_array_entries(): void {
		$themes = (object) array(
			'response' => array(
				'twentytwentyfive' => array(
					'theme'       => 'twentytwentyfive',
					'new_version' => '1.6',
					'package'     => 'https://downloads.wordpress.org/theme/twentytwentyfive.1.6.zip',
				),
				'eduma' => array(
					'theme'       => 'eduma',
					'new_version' => '5.9.4',
					'package'     => 'https://thimpress.com/downloads/eduma.zip',
				),
			),
		);

		$rows = Vendor::classify_updates( false, $themes );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'theme', $rows[0]['type'] );
		$by_slug = array_column( $rows, 'source', 'slug' );
		$this->assertSame( 'wporg', $by_slug['twentytwentyfive'] );
		$this->assertSame( 'external', $by_slug['eduma'] );
	}

	public function test_classify_empty_transients(): void {
		$this->assertSame( array(), Vendor::classify_updates( false, false ) );
		$this->assertSame( array(), Vendor::classify_updates( (object) array(), (object) array() ) );
	}

	public function test_classify_derives_slug_from_plugin_file_when_absent(): void {
		$plugins = $this->plugin_transient( array(
			'my-plugin/my-plugin.php' => (object) array( 'new_version' => '2.0', 'package' => 'https://x.test/a.zip' ),
		) );

		$rows = Vendor::classify_updates( $plugins, false );

		$this->assertSame( 'my-plugin', $rows[0]['slug'] );
	}

	/* check() */

	public function test_check_passes_with_no_external_updates(): void {
		$GLOBALS['upsun_test_site_transients']['update_plugins'] = $this->plugin_transient( array(
			'akismet/akismet.php' => (object) array(
				'slug'        => 'akismet',
				'new_version' => '5.8',
				'package'     => 'https://downloads.wordpress.org/plugin/akismet.5.8.zip',
				'id'          => 'w.org/plugins/akismet',
			),
		) );

		$result = Vendor::check();

		$this->assertSame( 'pass', $result['status'] );
		$this->assertStringContainsString( 'No premium', $result['message'] );
	}

	public function test_check_warns_on_external_update(): void {
		$GLOBALS['upsun_test_site_transients']['update_plugins'] = $this->plugin_transient( array(
			'learnpress-stripe/learnpress-stripe.php' => (object) array(
				'slug'        => 'learnpress-stripe',
				'new_version' => '4.0.7',
				'package'     => 'https://thimpress.com/downloads/stripe.zip',
			),
		) );

		$result = Vendor::check();

		$this->assertSame( 'warn', $result['status'] );
		$this->assertStringContainsString( 'learnpress-stripe', $result['message'] );
		$this->assertStringContainsString( '4.0.7', $result['message'] );
		$this->assertStringContainsString( 'wp upsun vendor', $result['message'] );
	}

	public function test_check_passes_when_no_updates_at_all(): void {
		$result = Vendor::check();

		$this->assertSame( 'pass', $result['status'] );
	}

	public function test_check_joins_the_shared_registry(): void {
		$checks = \Upsun\Modules\SiteHealth::checks();

		$this->assertArrayHasKey( 'vendored_updates', $checks );
		$this->assertIsCallable( $checks['vendored_updates']['callback'] );
	}

	/* copy_tree */

	public function test_copy_tree_copies_a_directory_recursively(): void {
		$src = $this->dir . '/src';
		mkdir( $src . '/inc', 0755, true );
		file_put_contents( $src . '/main.php', '<?php // main' );
		file_put_contents( $src . '/inc/lib.php', '<?php // lib' );

		$count = Vendor::copy_tree( $src, $this->dir . '/out' );

		$this->assertSame( 2, $count );
		$this->assertFileExists( $this->dir . '/out/main.php' );
		$this->assertFileExists( $this->dir . '/out/inc/lib.php' );
	}

	public function test_copy_tree_copies_a_single_file(): void {
		mkdir( $this->dir, 0755, true );
		file_put_contents( $this->dir . '/single.php', '<?php // single' );

		$count = Vendor::copy_tree( $this->dir . '/single.php', $this->dir . '/dest/single.php' );

		$this->assertSame( 1, $count );
		$this->assertFileExists( $this->dir . '/dest/single.php' );
	}
}
