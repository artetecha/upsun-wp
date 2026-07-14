<?php
/**
 * Cloudflare-in-front-of-Upsun support.
 *
 * When Cloudflare proxies a site, the Upsun router sees Cloudflare's edge
 * addresses as the client, so REMOTE_ADDR is a Cloudflare IP on every
 * request and X-Forwarded-For starts with the real visitor. Anything that
 * keys off the client IP — comment/order IPs, IP-based sessions, rate
 * limiters — then sees a handful of shared proxy addresses instead of real
 * visitors.
 *
 * This module:
 *
 * 1. Restores the real visitor IP into REMOTE_ADDR from CF-Connecting-IP,
 *    but ONLY when the connecting address is itself within a published
 *    Cloudflare range. A CF-Connecting-IP header sent straight to the
 *    origin's *.upsun.app URL (which stays publicly reachable) comes from a
 *    non-Cloudflare address and is ignored, so the header cannot be spoofed.
 * 2. Normalises the request scheme from Cloudflare's CF-Visitor header so
 *    HTTPS is detected behind the proxy.
 * 3. Optionally rejects production requests that did not arrive through
 *    Cloudflare, using a shared secret header injected by a Cloudflare
 *    Transform Rule (off by default; opt in with upsun_cloudflare_origin_secret).
 * 4. Exposes an edge cache purge helper and `wp upsun cloudflare` command —
 *    the invalidation the Upsun router cache never had — with optional
 *    auto-purge of a post's URLs when it changes.
 *
 * The IP restoration runs synchronously in register() (muplugins_loaded
 * priority 0) so REMOTE_ADDR is correct before init, ahead of any plugin
 * that reads it.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class Cloudflare implements Module {

	/**
	 * Published Cloudflare IPv4 ranges (cloudflare.com/ips-v4). Bundled so
	 * the module makes no external request at runtime; override or refresh
	 * with the upsun_cloudflare_ip_ranges filter. These change very rarely.
	 */
	public const IPV4_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	/**
	 * Published Cloudflare IPv6 ranges (cloudflare.com/ips-v6).
	 */
	public const IPV6_RANGES = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/** Cloudflare API base for cache purge. */
	private const API_BASE = 'https://api.cloudflare.com/client/v4';

	public function should_load(): bool {
		/**
		 * Filters whether the Cloudflare module loads. On by default: the
		 * IP restoration self-gates on the Cloudflare ranges, so it is inert
		 * on environments Cloudflare does not front (previews, direct origin
		 * hits) and safe to leave enabled everywhere.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_cloudflare_enabled', true );
	}

	public function register(): void {
		// Restore the client IP immediately, not on a later hook: REMOTE_ADDR
		// must be correct before anything reads it (WooCommerce order IPs,
		// IP-based LMS sessions, rate limiters all fire on init or later).
		$this->restore_client_ip();

		add_action( 'init', array( $this, 'maybe_block_direct_origin' ), 0 );
		add_filter( 'upsun_site_health_tests', array( $this, 'add_health_check' ) );
		add_filter( 'upsun_dashboard_panels', array( $this, 'add_dashboard_panel' ) );

		if ( apply_filters( 'upsun_cloudflare_auto_purge', false ) ) {
			add_action( 'clean_post_cache', array( $this, 'purge_post' ), 10, 2 );
		}
	}

	/**
	 * Contribute the Cloudflare check to the shared registry (Site Health
	 * and `wp upsun doctor`).
	 *
	 * @param array $checks
	 * @return array
	 */
	public function add_health_check( array $checks ): array {
		$checks['cloudflare'] = array(
			'label'    => __( 'Cloudflare', 'upsun-mu-plugin' ),
			'callback' => array( self::class, 'check' ),
		);

		return $checks;
	}

	/**
	 * Contribute a Cloudflare panel to the Upsun dashboard.
	 *
	 * @param array $panels
	 * @return array
	 */
	public function add_dashboard_panel( array $panels ): array {
		$panels['cloudflare'] = array(
			'title'   => __( 'Cloudflare', 'upsun-mu-plugin' ),
			'render'  => array( $this, 'render_panel' ),
			'context' => 'column3',
		);

		return $panels;
	}

	/**
	 * The dashboard panel body: whether Cloudflare is fronting this request,
	 * the restored client IP, the request scheme, the origin-guard state,
	 * and whether purge credentials are configured.
	 */
	public function render_panel(): void {
		$ranges      = self::trusted_ranges();
		$fronted     = self::is_fronted( $_SERVER, $ranges );
		$credentials = self::credentials();
		$purge_ready = '' !== $credentials['zone'] && '' !== $credentials['token'];

		$rows = array(
			__( 'Fronting this request', 'upsun-mu-plugin' ) => $fronted ? __( 'yes', 'upsun-mu-plugin' ) : __( 'no', 'upsun-mu-plugin' ),
			__( 'Client IP', 'upsun-mu-plugin' )            => (string) ( $_SERVER['REMOTE_ADDR'] ?? '—' ),
			__( 'Scheme', 'upsun-mu-plugin' )               => self::resolve_scheme( $_SERVER ) ?? '—',
			__( 'Trusted ranges', 'upsun-mu-plugin' )       => (string) count( $ranges ),
			__( 'Purge credentials', 'upsun-mu-plugin' )    => $purge_ready ? __( 'configured', 'upsun-mu-plugin' ) : __( 'not set', 'upsun-mu-plugin' ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Client IP restoration (pure decision + application).
	 * ------------------------------------------------------------------- */

	/**
	 * The trusted Cloudflare ranges, filterable so consumers can refresh
	 * the bundled list (e.g. from a cron-updated option) without a plugin
	 * release.
	 *
	 * @return string[] CIDR ranges.
	 */
	public static function trusted_ranges(): array {
		$ranges = array_merge( self::IPV4_RANGES, self::IPV6_RANGES );

		/**
		 * Filters the Cloudflare CIDR ranges trusted for header restoration.
		 *
		 * @param string[] $ranges CIDR ranges (IPv4 and IPv6).
		 */
		$ranges = (array) apply_filters( 'upsun_cloudflare_ip_ranges', $ranges );

		return array_values( array_filter( array_map( 'strval', $ranges ), 'strlen' ) );
	}

	/**
	 * The real client IP for this request, or null when the request did not
	 * arrive through a trusted Cloudflare address (so REMOTE_ADDR should be
	 * left untouched). Pure over $_SERVER so it is unit-testable.
	 *
	 * @param array    $server $_SERVER-shaped array.
	 * @param string[] $ranges Trusted Cloudflare CIDR ranges.
	 */
	public static function resolve_client_ip( array $server, array $ranges ): ?string {
		$connecting = (string) ( $server['REMOTE_ADDR'] ?? '' );

		// Only trust the forwarded header if the immediate peer is Cloudflare.
		if ( '' === $connecting || ! self::ip_in_ranges( $connecting, $ranges ) ) {
			return null;
		}

		$candidate = trim( (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) );

		if ( '' === $candidate || false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * The scheme Cloudflare saw from the visitor, from CF-Visitor's
	 * {"scheme":"https"} payload (falling back to X-Forwarded-Proto), or
	 * null when neither is present. Pure over $_SERVER.
	 *
	 * @param array $server $_SERVER-shaped array.
	 */
	public static function resolve_scheme( array $server ): ?string {
		$visitor = (string) ( $server['HTTP_CF_VISITOR'] ?? '' );

		if ( '' !== $visitor ) {
			$decoded = json_decode( $visitor, true );

			if ( is_array( $decoded ) && ! empty( $decoded['scheme'] ) ) {
				$scheme = strtolower( (string) $decoded['scheme'] );

				if ( 'https' === $scheme || 'http' === $scheme ) {
					return $scheme;
				}
			}
		}

		$forwarded = strtolower( trim( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) );

		if ( 'https' === $forwarded || 'http' === $forwarded ) {
			return $forwarded;
		}

		return null;
	}

	/**
	 * Whether this request reached the origin through a Cloudflare address.
	 * Once restore_client_ip() has run, REMOTE_ADDR holds the real visitor,
	 * so the original peer (preserved under UPSUN_ORIGINAL_REMOTE_ADDR) is
	 * the authoritative signal; before restoration REMOTE_ADDR is the peer.
	 *
	 * @param array    $server $_SERVER-shaped array.
	 * @param string[] $ranges Trusted Cloudflare CIDR ranges.
	 */
	public static function is_fronted( array $server, array $ranges ): bool {
		$peer = (string) ( $server['UPSUN_ORIGINAL_REMOTE_ADDR'] ?? $server['REMOTE_ADDR'] ?? '' );

		return '' !== $peer && self::ip_in_ranges( $peer, $ranges );
	}

	/**
	 * Apply resolve_client_ip()/resolve_scheme() to the live superglobals.
	 * The original REMOTE_ADDR is preserved under UPSUN_ORIGINAL_REMOTE_ADDR.
	 */
	public function restore_client_ip(): void {
		$ranges    = self::trusted_ranges();
		$client_ip = self::resolve_client_ip( $_SERVER, $ranges );

		if ( null === $client_ip ) {
			return;
		}

		$_SERVER['UPSUN_ORIGINAL_REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '';
		$_SERVER['REMOTE_ADDR']                = $client_ip;

		if ( 'https' === self::resolve_scheme( $_SERVER ) ) {
			$_SERVER['HTTPS']                  = 'on';
			$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		}
	}

	/* ---------------------------------------------------------------------
	 * CIDR matching (IPv4 and IPv6).
	 * ------------------------------------------------------------------- */

	/**
	 * @param string   $ip     Candidate address.
	 * @param string[] $ranges CIDR ranges.
	 */
	public static function ip_in_ranges( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::ip_in_cidr( $ip, (string) $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address falls inside a single CIDR block. Handles IPv4 and
	 * IPv6 by comparing the leading prefix bits of the packed addresses;
	 * mismatched families and malformed input return false.
	 */
	public static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}

		list( $subnet, $bits ) = explode( '/', $cidr, 2 );

		if ( ! is_numeric( $bits ) ) {
			return false;
		}

		$bits       = (int) $bits;
		$ip_packed  = @inet_pton( $ip );
		$net_packed = @inet_pton( $subnet );

		// Both must parse and be the same address family (same byte length).
		if ( false === $ip_packed || false === $net_packed || strlen( $ip_packed ) !== strlen( $net_packed ) ) {
			return false;
		}

		$max_bits = strlen( $ip_packed ) * 8;

		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true; // A /0 matches everything of that family.
		}

		$whole_bytes    = intdiv( $bits, 8 );
		$remainder_bits = $bits % 8;

		if ( $whole_bytes > 0 && 0 !== substr_compare( $ip_packed, substr( $net_packed, 0, $whole_bytes ), 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $remainder_bits ) {
			return true;
		}

		$mask    = 0xff << ( 8 - $remainder_bits ) & 0xff;
		$ip_byte = ord( $ip_packed[ $whole_bytes ] );
		$net_byte = ord( $net_packed[ $whole_bytes ] );

		return ( $ip_byte & $mask ) === ( $net_byte & $mask );
	}

	/* ---------------------------------------------------------------------
	 * Origin bypass guard (optional, off by default).
	 * ------------------------------------------------------------------- */

	/**
	 * 'allow' or 'deny' for the shared-secret origin guard. Denies only when
	 * a secret is configured, this is production, and the request's secret
	 * header is missing or wrong — so the guard is inert until deliberately
	 * enabled, and never fires on previews or off-Cloudflare environments.
	 * Pure so it is unit-testable.
	 *
	 * @param array       $server        $_SERVER-shaped array.
	 * @param string|null $secret        Expected shared secret ('' / null = disabled).
	 * @param string      $header        The $_SERVER key carrying the secret.
	 * @param bool        $is_production  Whether this is the production environment.
	 */
	public static function guard_verdict( array $server, ?string $secret, string $header, bool $is_production ): string {
		if ( null === $secret || '' === $secret || ! $is_production ) {
			return 'allow';
		}

		$presented = (string) ( $server[ $header ] ?? '' );

		return hash_equals( $secret, $presented ) ? 'allow' : 'deny';
	}

	/**
	 * Reject production requests that did not come through Cloudflare, when
	 * a shared secret is configured. The Transform Rule that adds the header
	 * is a Cloudflare-side step; without it this guard stays inert.
	 */
	public function maybe_block_direct_origin(): void {
		/**
		 * Filters the shared secret a Cloudflare Transform Rule injects on
		 * proxied requests. Empty (default) leaves the guard disabled. Read
		 * it from an environment variable, never hard-code it.
		 *
		 * @param string $secret Default '' (from CLOUDFLARE_ORIGIN_SECRET).
		 */
		$secret = (string) apply_filters( 'upsun_cloudflare_origin_secret', (string) getenv( 'CLOUDFLARE_ORIGIN_SECRET' ) );

		/**
		 * Filters the request header carrying the origin secret. The value
		 * is the PHP $_SERVER key (header name upper-cased, dashes to
		 * underscores, HTTP_ prefixed).
		 *
		 * @param string $header Default 'HTTP_X_ORIGIN_SECRET' (X-Origin-Secret).
		 */
		$header = (string) apply_filters( 'upsun_cloudflare_origin_secret_header', 'HTTP_X_ORIGIN_SECRET' );

		if ( 'deny' !== self::guard_verdict( $_SERVER, $secret, $header, Environment::is_production() ) ) {
			return;
		}

		// CLI (cron, deploy hooks) never carries HTTP headers; leave it be.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		wp_die(
			esc_html__( 'Direct origin access is not allowed.', 'upsun-mu-plugin' ),
			'',
			array( 'response' => 403 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Edge cache purge.
	 * ------------------------------------------------------------------- */

	/**
	 * The Cloudflare API purge request body for a set of URLs, or a
	 * purge-everything body when none are given. Pure so it is testable
	 * without the network.
	 *
	 * @param string[] $urls Absolute URLs to purge.
	 * @return array
	 */
	public static function purge_payload( array $urls ): array {
		$urls = array_values( array_filter( array_map( 'strval', $urls ), 'strlen' ) );

		if ( array() === $urls ) {
			return array( 'purge_everything' => true );
		}

		return array( 'files' => $urls );
	}

	/**
	 * Zone id and API token for purge calls, from environment variables by
	 * default and overridable by filters. Never printed anywhere.
	 *
	 * @return array{zone: string, token: string}
	 */
	public static function credentials(): array {
		/**
		 * Filters the Cloudflare zone id used for purge calls.
		 *
		 * @param string $zone Default from the CLOUDFLARE_ZONE_ID env var.
		 */
		$zone = (string) apply_filters( 'upsun_cloudflare_zone_id', (string) getenv( 'CLOUDFLARE_ZONE_ID' ) );

		/**
		 * Filters the Cloudflare API token used for purge calls. Use a
		 * scoped token with only the Zone → Cache Purge permission.
		 *
		 * @param string $token Default from the CLOUDFLARE_API_TOKEN env var.
		 */
		$token = (string) apply_filters( 'upsun_cloudflare_api_token', (string) getenv( 'CLOUDFLARE_API_TOKEN' ) );

		return array(
			'zone'  => $zone,
			'token' => $token,
		);
	}

	/**
	 * Purge the given URLs from Cloudflare's edge cache (all of it when the
	 * list is empty). Returns a WP_Error on missing credentials or a failed
	 * request, true on success.
	 *
	 * @param string[] $urls
	 * @return true|\WP_Error
	 */
	public function purge( array $urls = array() ) {
		$credentials = self::credentials();

		if ( '' === $credentials['zone'] || '' === $credentials['token'] ) {
			return new \WP_Error(
				'upsun_cloudflare_no_credentials',
				__( 'Cloudflare zone id or API token is not configured (CLOUDFLARE_ZONE_ID / CLOUDFLARE_API_TOKEN).', 'upsun-mu-plugin' )
			);
		}

		$response = wp_remote_post(
			sprintf( '%s/zones/%s/purge_cache', self::API_BASE, $credentials['zone'] ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $credentials['token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( self::purge_payload( $urls ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['success'] ) ) {
			$detail = '';

			if ( is_array( $body ) && ! empty( $body['errors'] ) ) {
				$messages = array_map(
					static fn ( $e ) => is_array( $e ) ? (string) ( $e['message'] ?? '' ) : (string) $e,
					$body['errors']
				);
				$detail   = ' ' . implode( '; ', array_filter( $messages ) );
			}

			return new \WP_Error(
				'upsun_cloudflare_purge_failed',
				sprintf( __( 'Cloudflare purge failed (HTTP %d).', 'upsun-mu-plugin' ), $code ) . $detail
			);
		}

		return true;
	}

	/**
	 * Auto-purge a post's permalink when its cache is cleaned. Hooked only
	 * when upsun_cloudflare_auto_purge is enabled.
	 *
	 * @param int          $post_id
	 * @param \WP_Post|null $post
	 */
	public function purge_post( $post_id, $post = null ): void {
		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			return;
		}

		/**
		 * Filters the URLs purged when a post changes.
		 *
		 * @param string[] $urls    Default: the post permalink.
		 * @param int      $post_id
		 */
		$urls = (array) apply_filters( 'upsun_cloudflare_post_purge_urls', array( $permalink ), $post_id );

		$this->purge( $urls );
	}

	/* ---------------------------------------------------------------------
	 * Site Health check.
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		$fronted = self::is_fronted( $_SERVER, self::trusted_ranges() );

		if ( ! Environment::is_production() ) {
			return array(
				'status'  => 'pass',
				'message' => $fronted
					? __( 'Requests are arriving through Cloudflare and the client IP is being restored.', 'upsun-mu-plugin' )
					: __( 'Non-production environment; Cloudflare is not fronting it (client IP restoration is inert, as expected).', 'upsun-mu-plugin' ),
			);
		}

		if ( $fronted ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'Production requests are arriving through Cloudflare; the real client IP is restored into REMOTE_ADDR.', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'status'  => 'warn',
			'message' => __( 'This request did not arrive through a Cloudflare address. If Cloudflare should be fronting production, check the proxy (orange cloud) and DNS; until then REMOTE_ADDR is the direct client.', 'upsun-mu-plugin' ),
		);
	}
}
