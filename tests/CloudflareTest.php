<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\Cloudflare;

final class CloudflareTest extends TestCase {

	protected function setUp(): void {
		upsun_test_reset_hooks();
		upsun_test_clear_env();

		foreach ( array(
			'REMOTE_ADDR',
			'UPSUN_ORIGINAL_REMOTE_ADDR',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CF_VISITOR',
			'HTTP_X_FORWARDED_PROTO',
			'HTTP_X_ORIGIN_SECRET',
			'HTTPS',
		) as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	private function ranges(): array {
		return array_merge( Cloudflare::IPV4_RANGES, Cloudflare::IPV6_RANGES );
	}

	/* ---- CIDR matching ------------------------------------------------ */

	public function test_ipv4_inside_and_outside_a_block(): void {
		$this->assertTrue( Cloudflare::ip_in_cidr( '162.158.5.5', '162.158.0.0/15' ) );
		$this->assertTrue( Cloudflare::ip_in_cidr( '162.159.255.255', '162.158.0.0/15' ) );
		$this->assertFalse( Cloudflare::ip_in_cidr( '8.8.8.8', '162.158.0.0/15' ) );
	}

	public function test_ipv4_boundaries_of_adjacent_cloudflare_blocks(): void {
		// 104.16.0.0/13 covers .16-.23; 104.24.0.0/14 covers .24-.27.
		$this->assertTrue( Cloudflare::ip_in_cidr( '104.23.1.1', '104.16.0.0/13' ) );
		$this->assertFalse( Cloudflare::ip_in_cidr( '104.24.1.1', '104.16.0.0/13' ) );
		$this->assertTrue( Cloudflare::ip_in_cidr( '104.24.1.1', '104.24.0.0/14' ) );
	}

	public function test_slash_32_matches_exact_host_only(): void {
		$this->assertTrue( Cloudflare::ip_in_cidr( '198.41.128.1', '198.41.128.1/32' ) );
		$this->assertFalse( Cloudflare::ip_in_cidr( '198.41.128.2', '198.41.128.1/32' ) );
	}

	public function test_ipv6_inside_and_family_mismatch(): void {
		$this->assertTrue( Cloudflare::ip_in_cidr( '2606:4700::1', '2606:4700::/32' ) );
		$this->assertFalse( Cloudflare::ip_in_cidr( '2400:cb00::1', '2606:4700::/32' ) );
		// IPv4 candidate against an IPv6 block (and vice versa) never matches.
		$this->assertFalse( Cloudflare::ip_in_cidr( '162.158.5.5', '2606:4700::/32' ) );
		$this->assertFalse( Cloudflare::ip_in_cidr( '2606:4700::1', '162.158.0.0/15' ) );
	}

	public function test_malformed_cidr_and_ip_never_match(): void {
		$this->assertFalse( Cloudflare::ip_in_cidr( '162.158.5.5', '162.158.0.0' ) ); // no /bits
		$this->assertFalse( Cloudflare::ip_in_cidr( '162.158.5.5', '162.158.0.0/x' ) );
		$this->assertFalse( Cloudflare::ip_in_cidr( '162.158.5.5', '162.158.0.0/40' ) ); // v4 out of range
		$this->assertFalse( Cloudflare::ip_in_cidr( 'not-an-ip', '162.158.0.0/15' ) );
	}

	public function test_ip_in_ranges_across_the_bundled_set(): void {
		$this->assertTrue( Cloudflare::ip_in_ranges( '173.245.48.10', $this->ranges() ) );
		$this->assertTrue( Cloudflare::ip_in_ranges( '2c0f:f248::5', $this->ranges() ) );
		$this->assertFalse( Cloudflare::ip_in_ranges( '203.0.113.7', $this->ranges() ) );
	}

	/* ---- Client IP restoration --------------------------------------- */

	public function test_real_ip_restored_when_peer_is_cloudflare(): void {
		$server = array(
			'REMOTE_ADDR'          => '173.245.48.5', // a Cloudflare edge
			'HTTP_CF_CONNECTING_IP' => '203.0.113.7',  // the real visitor
		);

		$this->assertSame( '203.0.113.7', Cloudflare::resolve_client_ip( $server, $this->ranges() ) );
	}

	public function test_spoofed_header_from_non_cloudflare_peer_is_ignored(): void {
		// A request straight to the *.upsun.app origin, forging the header.
		$server = array(
			'REMOTE_ADDR'           => '203.0.113.7', // not a Cloudflare address
			'HTTP_CF_CONNECTING_IP' => '10.0.0.1',    // attacker-controlled
		);

		$this->assertNull( Cloudflare::resolve_client_ip( $server, $this->ranges() ) );
	}

	public function test_missing_or_invalid_forwarded_ip_leaves_remote_addr_untouched(): void {
		$this->assertNull(
			Cloudflare::resolve_client_ip( array( 'REMOTE_ADDR' => '173.245.48.5' ), $this->ranges() )
		);
		$this->assertNull(
			Cloudflare::resolve_client_ip(
				array(
					'REMOTE_ADDR'           => '173.245.48.5',
					'HTTP_CF_CONNECTING_IP' => 'garbage',
				),
				$this->ranges()
			)
		);
	}

	public function test_restore_client_ip_mutates_superglobal_and_preserves_original(): void {
		$_SERVER['REMOTE_ADDR']           = '162.158.1.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
		$_SERVER['HTTP_CF_VISITOR']       = '{"scheme":"https"}';
		unset( $_SERVER['HTTPS'] );

		( new Cloudflare() )->restore_client_ip();

		$this->assertSame( '198.51.100.9', $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '162.158.1.1', $_SERVER['UPSUN_ORIGINAL_REMOTE_ADDR'] );
		$this->assertSame( 'on', $_SERVER['HTTPS'] );
	}

	/* ---- Scheme detection -------------------------------------------- */

	public function test_scheme_from_cf_visitor_then_forwarded_then_none(): void {
		$this->assertSame( 'https', Cloudflare::resolve_scheme( array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ) );
		$this->assertSame( 'http', Cloudflare::resolve_scheme( array( 'HTTP_CF_VISITOR' => '{"scheme":"http"}' ) ) );
		$this->assertSame( 'https', Cloudflare::resolve_scheme( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ) ) );
		$this->assertNull( Cloudflare::resolve_scheme( array() ) );
		$this->assertNull( Cloudflare::resolve_scheme( array( 'HTTP_CF_VISITOR' => 'not-json' ) ) );
	}

	/* ---- Fronting detection ------------------------------------------ */

	public function test_is_fronted(): void {
		$this->assertTrue( Cloudflare::is_fronted( array( 'REMOTE_ADDR' => '104.16.0.1' ), $this->ranges() ) );
		$this->assertFalse( Cloudflare::is_fronted( array( 'REMOTE_ADDR' => '203.0.113.7' ), $this->ranges() ) );
		$this->assertFalse( Cloudflare::is_fronted( array(), $this->ranges() ) );
	}

	public function test_is_fronted_uses_original_peer_after_restoration(): void {
		// Post-restoration: REMOTE_ADDR is the real visitor, but the original
		// Cloudflare peer is preserved and must still read as fronted.
		$server = array(
			'REMOTE_ADDR'                => '203.0.113.7', // restored real visitor
			'UPSUN_ORIGINAL_REMOTE_ADDR' => '104.16.0.1',  // the Cloudflare edge
		);

		$this->assertTrue( Cloudflare::is_fronted( $server, $this->ranges() ) );
	}

	/* ---- Origin secret guard ----------------------------------------- */

	public function test_guard_is_inert_without_a_secret_or_off_production(): void {
		$server = array( 'HTTP_X_ORIGIN_SECRET' => '' );

		$this->assertSame( 'allow', Cloudflare::guard_verdict( $server, '', 'HTTP_X_ORIGIN_SECRET', true ) );
		$this->assertSame( 'allow', Cloudflare::guard_verdict( $server, null, 'HTTP_X_ORIGIN_SECRET', true ) );
		// Configured, but not production: never blocks previews.
		$this->assertSame( 'allow', Cloudflare::guard_verdict( $server, 's3cret', 'HTTP_X_ORIGIN_SECRET', false ) );
	}

	public function test_guard_allows_matching_and_denies_wrong_or_missing_secret(): void {
		$this->assertSame(
			'allow',
			Cloudflare::guard_verdict( array( 'HTTP_X_ORIGIN_SECRET' => 's3cret' ), 's3cret', 'HTTP_X_ORIGIN_SECRET', true )
		);
		$this->assertSame(
			'deny',
			Cloudflare::guard_verdict( array( 'HTTP_X_ORIGIN_SECRET' => 'wrong' ), 's3cret', 'HTTP_X_ORIGIN_SECRET', true )
		);
		$this->assertSame(
			'deny',
			Cloudflare::guard_verdict( array(), 's3cret', 'HTTP_X_ORIGIN_SECRET', true )
		);
	}

	/* ---- Purge payload ----------------------------------------------- */

	public function test_purge_payload_shapes(): void {
		$this->assertSame( array( 'purge_everything' => true ), Cloudflare::purge_payload( array() ) );
		$this->assertSame(
			array( 'files' => array( 'https://example.com/', 'https://example.com/courses/' ) ),
			Cloudflare::purge_payload( array( 'https://example.com/', '', 'https://example.com/courses/' ) )
		);
	}

	/* ---- Health check ------------------------------------------------ */

	public function test_check_warns_on_production_when_not_fronted(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		\Upsun\Environment::reset();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // direct, not Cloudflare
		$result                 = Cloudflare::check();

		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_check_passes_on_production_when_fronted(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		\Upsun\Environment::reset();

		$_SERVER['REMOTE_ADDR'] = '173.245.48.5'; // a Cloudflare edge
		$result                 = Cloudflare::check();

		$this->assertSame( 'pass', $result['status'] );
	}

	public function test_check_passes_on_previews_regardless_of_fronting(): void {
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=staging' );
		\Upsun\Environment::reset();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->assertSame( 'pass', Cloudflare::check()['status'] );
	}

	public function test_ip_ranges_filter_can_extend_trusted_set(): void {
		add_filter(
			'upsun_cloudflare_ip_ranges',
			static function ( array $ranges ) {
				$ranges[] = '203.0.113.0/24';
				return $ranges;
			}
		);

		$this->assertTrue( Cloudflare::ip_in_ranges( '203.0.113.7', Cloudflare::trusted_ranges() ) );
	}
}
