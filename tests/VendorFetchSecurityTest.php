<?php

use PHPUnit\Framework\TestCase;
use Upsun\Vendor;

/**
 * The vendoring engine downloads code that gets committed and later executed,
 * from sources that offer no checksum or signature. These are the guards on
 * that path — the transport, the archive, and the work directory.
 */
final class VendorFetchSecurityTest extends TestCase {

	private string $dest;

	protected function setUp(): void {
		upsun_test_reset_hooks();
		upsun_test_http_reset();
		$this->dest = sys_get_temp_dir() . '/upsun-fetch-test-' . bin2hex( random_bytes( 6 ) ) . '.zip';
	}

	protected function tearDown(): void {
		if ( is_file( $this->dest ) ) {
			unlink( $this->dest );
		}

		upsun_test_http_reset();
	}

	/* ---- Transport ---------------------------------------------------- */

	public function test_https_download_writes_the_body(): void {
		upsun_test_http_reset( array( array( 'code' => 200, 'body' => 'PK-payload' ) ) );

		$this->assertTrue( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );
		$this->assertSame( 'PK-payload', file_get_contents( $this->dest ) );
	}

	public function test_plain_http_is_refused_without_a_request(): void {
		upsun_test_http_reset( array( array( 'code' => 200, 'body' => 'PK-payload' ) ) );

		$this->assertFalse( Vendor::fetch_zip( 'http://example.test/pkg.zip', array(), $this->dest ) );
		$this->assertSame( array(), $GLOBALS['upsun_test_http']['requests'] );
		$this->assertFileDoesNotExist( $this->dest );
	}

	/**
	 * The finding this suite was written for: WordPress follows up to five
	 * redirects itself and only validates them when reject_unsafe_urls is set,
	 * so an https URL redirecting to http would have downgraded the one fetch
	 * whose bytes get committed and executed.
	 */
	public function test_a_redirect_to_http_is_refused(): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => 'http://example.test/pkg.zip' ) ),
				array( 'code' => 200, 'body' => 'PK-attacker' ),
			)
		);

		$this->assertFalse( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );

		// One request only: the downgrade was refused before it was made.
		$this->assertCount( 1, $GLOBALS['upsun_test_http']['requests'] );
	}

	/**
	 * A streamed redirect writes its body to the target too, so a refused
	 * chain must not leave a non-archive behind for the extract step.
	 */
	public function test_a_refused_chain_leaves_no_file(): void {
		upsun_test_http_reset(
			array(
				array(
					'code'    => 302,
					'headers' => array( 'location' => 'http://example.test/pkg.zip' ),
					'body'    => '<html>redirecting</html>',
				),
			)
		);

		Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest );

		$this->assertFileDoesNotExist( $this->dest );
	}

	public function test_an_https_redirect_chain_is_followed(): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 301, 'headers' => array( 'location' => 'https://cdn.example.test/a.zip' ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		$this->assertTrue( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );
		$this->assertSame( 'PK-final', file_get_contents( $this->dest ) );
		$this->assertSame(
			'https://cdn.example.test/a.zip',
			$GLOBALS['upsun_test_http']['requests'][1]['url']
		);
	}

	/**
	 * @dataProvider relative_locations
	 */
	public function test_a_relative_redirect_resolves_against_its_base( string $location, string $expected ): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => $location ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		$this->assertTrue( Vendor::fetch_zip( 'https://example.test/dl/pkg.zip', array(), $this->dest ) );
		$this->assertSame( $expected, $GLOBALS['upsun_test_http']['requests'][1]['url'] );
	}

	public static function relative_locations(): array {
		return array(
			'absolute path'     => array( '/other/pkg.zip', 'https://example.test/other/pkg.zip' ),
			'same directory'    => array( 'real.zip', 'https://example.test/dl/real.zip' ),
			// A form real CDNs emit: different host, same scheme. Must not be
			// read as an absolute path on the original host.
			'protocol relative' => array( '//cdn.example.test/pkg.zip', 'https://cdn.example.test/pkg.zip' ),
		);
	}

	/**
	 * The scheme a protocol-relative redirect inherits is the one we just
	 * used, so the https requirement still applies to the next hop.
	 */
	public function test_a_protocol_relative_redirect_stays_https(): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => '//cdn.example.test/pkg.zip' ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		$this->assertTrue( Vendor::fetch_zip( 'https://example.test/dl/pkg.zip', array(), $this->dest ) );
		$this->assertStringStartsWith( 'https://', $GLOBALS['upsun_test_http']['requests'][1]['url'] );
	}

	/* ---- Credentials across a redirect -------------------------------- */

	/**
	 * The Fetcher contract permits auth headers in the resolved download, and
	 * the vendor endpoint is untrusted, so a 302 to a host it controls must not
	 * carry the licence token with it.
	 */
	public function test_credentials_are_dropped_on_a_cross_host_redirect(): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => 'https://attacker.test/pkg.zip' ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		Vendor::fetch_zip( 'https://vendor.test/pkg.zip', array( 'Authorization' => 'Bearer licence-token' ), $this->dest );

		$requests = $GLOBALS['upsun_test_http']['requests'];

		$this->assertSame( array( 'Authorization' => 'Bearer licence-token' ), $requests[0]['args']['headers'] );
		$this->assertSame( array(), $requests[1]['args']['headers'], 'the token followed the redirect off-origin' );
	}

	/**
	 * @dataProvider off_origin_redirects
	 */
	public function test_any_origin_change_drops_credentials( string $from, string $location ): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => $location ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		Vendor::fetch_zip( $from, array( 'X-Api-Key' => 'secret' ), $this->dest );

		$this->assertSame( array(), $GLOBALS['upsun_test_http']['requests'][1]['args']['headers'] );
	}

	public static function off_origin_redirects(): array {
		return array(
			'different host'        => array( 'https://vendor.test/pkg.zip', 'https://cdn.test/pkg.zip' ),
			'protocol relative'    => array( 'https://vendor.test/pkg.zip', '//cdn.test/pkg.zip' ),
			'different port'       => array( 'https://vendor.test/pkg.zip', 'https://vendor.test:8443/pkg.zip' ),
			'subdomain of the same' => array( 'https://vendor.test/pkg.zip', 'https://dl.vendor.test/pkg.zip' ),
		);
	}

	/**
	 * A same-origin redirect is the ordinary case — a vendor moving the path —
	 * and must keep working with the credential attached.
	 */
	public function test_credentials_survive_a_same_origin_redirect(): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => '/real/pkg.zip' ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		$this->assertTrue(
			Vendor::fetch_zip( 'https://vendor.test/dl/pkg.zip', array( 'Authorization' => 'Bearer t' ), $this->dest )
		);

		$requests = $GLOBALS['upsun_test_http']['requests'];

		$this->assertSame( 'https://vendor.test/real/pkg.zip', $requests[1]['url'] );
		$this->assertSame( array( 'Authorization' => 'Bearer t' ), $requests[1]['args']['headers'] );
	}

	/** An implicit :443 and an explicit one are the same origin. */
	public function test_an_explicit_default_port_is_the_same_origin(): void {
		upsun_test_http_reset(
			array(
				array( 'code' => 302, 'headers' => array( 'location' => 'https://vendor.test:443/real.zip' ) ),
				array( 'code' => 200, 'body' => 'PK-final' ),
			)
		);

		Vendor::fetch_zip( 'https://vendor.test/pkg.zip', array( 'Authorization' => 'Bearer t' ), $this->dest );

		$this->assertSame(
			array( 'Authorization' => 'Bearer t' ),
			$GLOBALS['upsun_test_http']['requests'][1]['args']['headers']
		);
	}

	public function test_a_redirect_loop_is_bounded(): void {
		$hops = array_fill(
			0,
			10,
			array( 'code' => 302, 'headers' => array( 'location' => 'https://example.test/again.zip' ) )
		);

		upsun_test_http_reset( $hops );

		$this->assertFalse( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );
		$this->assertLessThanOrEqual( 6, count( $GLOBALS['upsun_test_http']['requests'] ) );
	}

	public function test_a_redirect_without_a_location_fails(): void {
		upsun_test_http_reset( array( array( 'code' => 302 ) ) );

		$this->assertFalse( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );
	}

	public function test_an_error_response_fails(): void {
		upsun_test_http_reset( array( array( 'code' => 404, 'body' => 'nope' ) ) );

		$this->assertFalse( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );
	}

	public function test_a_transport_error_fails(): void {
		upsun_test_http_reset( array( array( 'error' => 'dns failure' ) ) );

		$this->assertFalse( Vendor::fetch_zip( 'https://example.test/pkg.zip', array(), $this->dest ) );
	}

	public function test_the_download_is_size_capped_and_carries_its_headers(): void {
		upsun_test_http_reset( array( array( 'code' => 200, 'body' => 'PK' ) ) );

		Vendor::fetch_zip( 'https://example.test/pkg.zip', array( 'Authorization' => 'Bearer x' ), $this->dest );

		$args = $GLOBALS['upsun_test_http']['requests'][0]['args'];

		$this->assertSame( 268435456, $args['limit_response_size'] );
		// Redirects are followed by us, one hop at a time, not by WordPress.
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( array( 'Authorization' => 'Bearer x' ), $args['headers'] );
	}

	/** Non-http(s) schemes are neither fetched nor treated as local paths. */
	public function test_an_unknown_scheme_is_refused(): void {
		upsun_test_http_reset( array( array( 'code' => 200, 'body' => 'PK' ) ) );

		$this->assertFalse( Vendor::fetch_zip( 'ftp://example.test/pkg.zip', array(), $this->dest ) );
		$this->assertSame( array(), $GLOBALS['upsun_test_http']['requests'] );
	}

	/** file:// and bare paths stay usable for artifacts and fixtures. */
	public function test_local_paths_still_work(): void {
		$source = sys_get_temp_dir() . '/upsun-fetch-src-' . bin2hex( random_bytes( 6 ) );
		file_put_contents( $source, 'PK-local' );

		$this->assertTrue( Vendor::fetch_zip( 'file://' . $source, array(), $this->dest ) );
		$this->assertSame( 'PK-local', file_get_contents( $this->dest ) );

		$this->assertTrue( Vendor::fetch_zip( $source, array(), $this->dest ) );

		unlink( $source );
	}

	/* ---- Archive ------------------------------------------------------ */

	public function test_the_uncompressed_cap_is_one_gigabyte(): void {
		$this->assertFalse( Vendor::exceeds_uncompressed_cap( 1073741824 ) );
		$this->assertTrue( Vendor::exceeds_uncompressed_cap( 1073741825 ) );
	}

	public function test_a_normal_archive_still_extracts(): void {
		$zip  = sys_get_temp_dir() . '/upsun-zip-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$into = sys_get_temp_dir() . '/upsun-out-' . bin2hex( random_bytes( 6 ) );

		$archive = new ZipArchive();
		$archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$archive->addFromString( 'pkg/style.css', 'body{}' );
		$archive->close();

		$root = Vendor::extract_zip( $zip, $into );

		$this->assertNotNull( $root );
		$this->assertFileExists( $root . '/style.css' );

		Vendor::remove_tree( $into );
		unlink( $zip );
	}

	public function test_a_traversing_entry_is_refused(): void {
		$zip  = sys_get_temp_dir() . '/upsun-zip-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$into = sys_get_temp_dir() . '/upsun-out-' . bin2hex( random_bytes( 6 ) );

		$archive = new ZipArchive();
		$archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$archive->addFromString( '../escaped.php', '<?php // no' );
		$archive->close();

		$this->assertNull( Vendor::extract_zip( $zip, $into ) );
		$this->assertFileDoesNotExist( dirname( $into ) . '/escaped.php' );

		Vendor::remove_tree( $into );
		unlink( $zip );
	}

	/* ---- Work directory ----------------------------------------------- */

	public function test_the_work_directory_is_private_and_unpredictable(): void {
		$method = new ReflectionMethod( Vendor::class, 'temp_dir' );

		$first  = $method->invoke( null );
		$second = $method->invoke( null );

		$this->assertNotSame( $first, $second );
		$this->assertDirectoryExists( $first );
		// Owner-only: the package sits here between download and commit.
		$this->assertSame( '0700', substr( sprintf( '%o', fileperms( $first ) ), -4 ) );

		Vendor::remove_tree( $first );
		Vendor::remove_tree( $second );
	}
}
