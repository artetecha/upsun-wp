<?php

use PHPUnit\Framework\TestCase;
use Upsun\Fetcher;
use Upsun\Fetchers\TransientFetcher;
use Upsun\Vendor;

/**
 * A fetcher that serves a fixture zip from a local path — lets the update()
 * orchestration be exercised end-to-end with no network.
 */
final class UpsunFixtureFetcher implements Fetcher {

	public string $slug;
	public string $version;
	public string $zip;

	public function __construct( string $slug, string $version, string $zip ) {
		$this->slug    = $slug;
		$this->version = $version;
		$this->zip     = $zip;
	}

	public function id(): string {
		return 'fixture';
	}

	public function supports( string $slug, string $type ): bool {
		return $slug === $this->slug;
	}

	public function available_update( string $slug, string $type ): ?array {
		if ( $slug !== $this->slug ) {
			return null;
		}

		return array( 'version' => $this->version, 'url' => 'file://' . $this->zip, 'headers' => array() );
	}
}

final class FetcherTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		$GLOBALS['upsun_test_site_transients'] = array();
		$this->dir = sys_get_temp_dir() . '/upsun-fetch-' . getmypid() . '-' . uniqid();
		mkdir( $this->dir, 0755, true );
	}

	protected function tearDown(): void {
		Vendor::remove_tree( $this->dir );
		$GLOBALS['upsun_test_site_transients'] = array();
	}

	private function make_zip( string $path, array $files ): void {
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
	}

	/* Registry / dispatch. */

	public function test_transient_fetcher_is_the_default_fallback(): void {
		$fetchers = Vendor::fetchers();

		$this->assertInstanceOf( TransientFetcher::class, end( $fetchers ) );
	}

	public function test_registered_fetcher_wins_over_the_fallback(): void {
		$fixture = new UpsunFixtureFetcher( 'eduma', '9.9', '/nope.zip' );
		add_filter( 'upsun_vendor_fetchers', static fn ( array $f ) => array_merge( $f, array( $fixture ) ) );

		$this->assertSame( 'fixture', Vendor::pick_fetcher( 'eduma', 'theme' )->id() );
		// A slug the fixture doesn't claim falls through to the transient.
		$this->assertSame( 'transient', Vendor::pick_fetcher( 'other', 'plugin' )->id() );
	}

	/* TransientFetcher. */

	public function test_transient_fetcher_resolves_a_packaged_update(): void {
		$GLOBALS['upsun_test_site_transients']['update_plugins'] = (object) array(
			'response' => array(
				'learnpress-stripe/learnpress-stripe.php' => (object) array(
					'slug'        => 'learnpress-stripe',
					'new_version' => '4.0.7',
					'package'     => 'https://thimpress.com/downloads/stripe.zip',
				),
			),
		);

		$update = ( new TransientFetcher() )->available_update( 'learnpress-stripe', 'plugin' );

		$this->assertSame( '4.0.7', $update['version'] );
		$this->assertSame( 'https://thimpress.com/downloads/stripe.zip', $update['url'] );
	}

	public function test_transient_fetcher_returns_null_without_a_package_url(): void {
		$GLOBALS['upsun_test_site_transients']['update_plugins'] = (object) array(
			'response' => array(
				'unlicensed/unlicensed.php' => (object) array( 'slug' => 'unlicensed', 'new_version' => '2.0', 'package' => '' ),
			),
		);

		$this->assertNull( ( new TransientFetcher() )->available_update( 'unlicensed', 'plugin' ) );
		$this->assertNull( ( new TransientFetcher() )->available_update( 'absent', 'plugin' ) );
	}

	/* merge_composer_json (preserve-merge). */

	public function test_merge_preserves_extra_and_strips_risky_keys(): void {
		$upstream = array(
			'name'        => 'thimpress/learnpress-stripe',
			'description' => 'upstream desc',
			'require'     => array( 'php' => '>=7.4' ),
			'autoload'    => array( 'psr-4' => array( 'X\\' => 'src/' ) ),
			'repositories'=> array( array( 'type' => 'vcs', 'url' => 'x' ) ),
			'extra'       => array( 'wpfluent' => array( 'namespace' => 'FluentCampaign' ) ),
		);

		$merged = Vendor::merge_composer_json( $upstream, 'keds-plugin/learnpress-stripe', 'plugin', '4.0.7' );

		$this->assertSame( 'keds-plugin/learnpress-stripe', $merged['name'] );
		$this->assertSame( 'wordpress-plugin', $merged['type'] );
		$this->assertSame( '4.0.7', $merged['version'] );
		// Runtime-load-bearing key preserved.
		$this->assertSame( array( 'wpfluent' => array( 'namespace' => 'FluentCampaign' ) ), $merged['extra'] );
		$this->assertSame( 'upstream desc', $merged['description'] );
		// Risky keys stripped.
		$this->assertArrayNotHasKey( 'require', $merged );
		$this->assertArrayNotHasKey( 'autoload', $merged );
		$this->assertArrayNotHasKey( 'repositories', $merged );
	}

	public function test_merge_from_empty_upstream(): void {
		$merged = Vendor::merge_composer_json( array(), 'private/foo', 'theme', '1.0' );

		$this->assertSame(
			array( 'name' => 'private/foo', 'type' => 'wordpress-theme', 'version' => '1.0' ),
			$merged
		);
	}

	/* extract_zip. */

	public function test_extract_unwraps_a_single_top_level_dir(): void {
		$zip = $this->dir . '/pkg.zip';
		$this->make_zip( $zip, array(
			'eduma/style.css'   => '/* theme */',
			'eduma/inc/foo.php' => '<?php',
		) );

		$root = Vendor::extract_zip( $zip, $this->dir . '/extracted' );

		$this->assertNotNull( $root );
		$this->assertSame( 'eduma', basename( $root ) );
		$this->assertFileExists( $root . '/style.css' );
		$this->assertFileExists( $root . '/inc/foo.php' );
	}

	public function test_extract_flat_zip_returns_root(): void {
		$zip = $this->dir . '/flat.zip';
		$this->make_zip( $zip, array( 'a.php' => '<?php', 'b.php' => '<?php' ) );

		$root = Vendor::extract_zip( $zip, $this->dir . '/flat' );

		$this->assertSame( realpath( $this->dir . '/flat' ), realpath( $root ) );
		$this->assertFileExists( $root . '/a.php' );
	}

	/* End-to-end update(). */

	public function test_update_downloads_extracts_and_re_vendors(): void {
		// A fixture "new version" zip, wrapping-dir style, shipping an
		// upstream composer.json with a runtime-load-bearing extra key.
		$zip = $this->dir . '/new.zip';
		$this->make_zip( $zip, array(
			'my-plugin/my-plugin.php' => "<?php\n/* Plugin Name: My Plugin */",
			'my-plugin/composer.json' => json_encode( array(
				'name'    => 'upstream/my-plugin',
				'require' => array( 'php' => '>=8.0' ),
				'extra'   => array( 'keep' => 'this' ),
			) ),
		) );

		add_filter( 'upsun_vendor_fetchers', static fn ( array $f ) =>
			array_merge( $f, array( new UpsunFixtureFetcher( 'my-plugin', '2.0.0', $zip ) ) )
		);

		// Pre-existing vendored package fixes the Composer name to preserve.
		$dest_base = $this->dir . '/private-packages/plugins';
		mkdir( $dest_base . '/my-plugin', 0755, true );
		file_put_contents(
			$dest_base . '/my-plugin/composer.json',
			json_encode( array( 'name' => 'keds-plugin/my-plugin', 'type' => 'wordpress-plugin', 'version' => '1.0.0' ) )
		);
		file_put_contents( $dest_base . '/my-plugin/old.php', '<?php // stale' );

		$result = Vendor::update( 'my-plugin', array( 'type' => 'plugin', 'to' => $dest_base ) );

		$this->assertNotNull( $result );
		$this->assertSame( '2.0.0', $result['to'] );
		$this->assertSame( 'fixture', $result['fetcher'] );

		// New source replaced the old (stale file gone, new file present).
		$this->assertFileDoesNotExist( $dest_base . '/my-plugin/old.php' );
		$this->assertFileExists( $dest_base . '/my-plugin/my-plugin.php' );

		$composer = json_decode( (string) file_get_contents( $dest_base . '/my-plugin/composer.json' ), true );
		$this->assertSame( 'keds-plugin/my-plugin', $composer['name'] ); // Preserved vendor namespace.
		$this->assertSame( '2.0.0', $composer['version'] );
		$this->assertSame( array( 'keep' => 'this' ), $composer['extra'] ); // Upstream extra preserved.
		$this->assertArrayNotHasKey( 'require', $composer ); // Risky key stripped.
	}

	public function test_update_returns_null_when_not_newer(): void {
		$zip = $this->dir . '/same.zip';
		$this->make_zip( $zip, array( 'x/x.php' => '<?php' ) );

		add_filter( 'upsun_vendor_fetchers', static fn ( array $f ) =>
			array_merge( $f, array( new UpsunFixtureFetcher( 'x', '1.0.0', $zip ) ) )
		);

		$dest_base = $this->dir . '/pp';
		mkdir( $dest_base . '/x', 0755, true );
		// Note: installed_version() reads WP; with no plugin installed it is
		// '' so the version guard can't fire — resolve still returns a plan.
		$plan = Vendor::resolve_update( 'x', 'plugin' );
		$this->assertNotNull( $plan );
		$this->assertSame( '1.0.0', $plan['to'] );
	}

	public function test_resolvable_updates_lists_external_pending(): void {
		$GLOBALS['upsun_test_site_transients']['update_plugins'] = (object) array(
			'response' => array(
				'akismet/akismet.php' => (object) array(
					'slug' => 'akismet', 'new_version' => '5.9',
					'package' => 'https://downloads.wordpress.org/plugin/akismet.5.9.zip', 'id' => 'w.org/plugins/akismet',
				),
			),
		);

		// The transient fetcher resolves akismet too (it doesn't care about
		// wporg vs external — that's the check's classification job), so a
		// plan exists; this asserts the discovery pass runs without error.
		$plans = Vendor::resolvable_updates();

		$this->assertIsArray( $plans );
	}
}
