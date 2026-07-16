<?php
/**
 * Baseline security response headers for the front end.
 *
 * On Upsun, web.locations `headers` only decorate *static* files — dynamic
 * responses (WordPress HTML via passthru → index.php) get their headers from
 * the app. So the HTML document, which is exactly what clickjacking / MIME /
 * referrer / HSTS protections are about, cannot be covered from config.yaml;
 * it has to come from PHP. This module emits that set on `send_headers`.
 *
 * The three baseline headers (X-Content-Type-Options, Referrer-Policy,
 * X-Frame-Options) are safe, site-agnostic defaults and are always sent.
 *
 * HSTS is handled deliberately: when Cloudflare (or any CDN it can detect)
 * fronts the request, that edge owns HSTS, so emitting it here too would send
 * a duplicate header — the module defers, and says so in Site Health and the
 * dashboard. When nothing is fronting (a direct-Upsun production site over
 * HTTPS) the module emits HSTS itself. Either way there is exactly one source.
 *
 * Content-Security-Policy is intentionally out of scope: a useful CSP is
 * per-site and breaks things when applied blind, so it is left to consumers.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class SecurityHeaders implements Module {

	/**
	 * Baseline headers applied to every front-end response, regardless of
	 * environment. HSTS is added conditionally on top of these.
	 */
	public const BASELINE = array(
		'X-Content-Type-Options' => 'nosniff',
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		'X-Frame-Options'        => 'SAMEORIGIN',
	);

	/**
	 * Default HSTS value: 180 days, no includeSubDomains, no preload — a
	 * conservative, reversible default. Tune with upsun_security_hsts_value.
	 */
	public const HSTS_DEFAULT = 'max-age=15552000';

	public function should_load(): bool {
		/**
		 * Filters whether the security-headers module loads.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_security_headers_enabled', true );
	}

	public function register(): void {
		add_action( 'send_headers', array( $this, 'send' ) );
		add_filter( 'upsun_site_health_tests', array( $this, 'add_health_check' ) );
		add_filter( 'upsun_dashboard_panels', array( $this, 'add_dashboard_panel' ) );
	}

	/* ---------------------------------------------------------------------
	 * Header decision (pure over $_SERVER, so it is unit-testable).
	 * ------------------------------------------------------------------- */

	/**
	 * The full header set for a request. Baseline headers plus HSTS when this
	 * app is the outermost HTTPS terminator (see should_emit_hsts()).
	 *
	 * @param array $server        $_SERVER-shaped array.
	 * @param bool  $is_production  Whether this is the production environment.
	 * @return array<string, string> Header name => value.
	 */
	public static function headers( array $server, bool $is_production ): array {
		$headers = self::BASELINE;

		if ( self::should_emit_hsts( $server, $is_production ) ) {
			$headers['Strict-Transport-Security'] = self::hsts_value();
		}

		/**
		 * Filters the security response headers before they are sent. Return an
		 * empty value to drop a header; add keys to send more (e.g. a CSP).
		 *
		 * @param array<string, string> $headers Header name => value.
		 * @param array                 $server  $_SERVER-shaped array.
		 */
		$headers = (array) apply_filters( 'upsun_security_headers', $headers, $server );

		// Never let a header value smuggle CR/LF.
		$clean = array();

		foreach ( $headers as $name => $value ) {
			$name  = trim( (string) $name );
			$value = trim( preg_replace( '/[\r\n]+/', '', (string) $value ) );

			if ( '' !== $name && '' !== $value ) {
				$clean[ $name ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Whether this app should assert HSTS itself.
	 *
	 * Only over HTTPS (asserting it over plaintext is meaningless and
	 * spec-discouraged), and only when nothing is fronting the request — if
	 * Cloudflare is in front it owns HSTS and we defer to avoid a duplicate
	 * header. The remaining gate is filterable and defaults to production only,
	 * so previews never pin a hostname to HTTPS.
	 *
	 * @param array $server        $_SERVER-shaped array.
	 * @param bool  $is_production  Whether this is the production environment.
	 */
	public static function should_emit_hsts( array $server, bool $is_production ): bool {
		if ( ! self::is_https( $server ) ) {
			return false;
		}

		if ( Cloudflare::is_fronted( $server ) ) {
			return false;
		}

		/**
		 * Filters whether this app emits HSTS (when not fronted and on HTTPS).
		 * Defaults to production only.
		 *
		 * @param bool  $enabled Default: whether this is production.
		 * @param array $server  $_SERVER-shaped array.
		 */
		return (bool) apply_filters( 'upsun_security_hsts', $is_production, $server );
	}

	/**
	 * The HSTS header value, filterable so consumers can lengthen max-age or
	 * add includeSubDomains/preload once they are sure.
	 */
	public static function hsts_value(): string {
		/**
		 * Filters the Strict-Transport-Security value.
		 *
		 * @param string $value Default 'max-age=15552000' (180 days).
		 */
		return (string) apply_filters( 'upsun_security_hsts_value', self::HSTS_DEFAULT );
	}

	/**
	 * Whether the request reached us over HTTPS. Pure over $_SERVER, covering
	 * the direct HTTPS flag, the router's X-Forwarded-Proto, the HTTPS port,
	 * and Cloudflare's CF-Visitor scheme.
	 *
	 * @param array $server $_SERVER-shaped array.
	 */
	public static function is_https( array $server ): bool {
		if ( 'on' === strtolower( (string) ( $server['HTTPS'] ?? '' ) ) ) {
			return true;
		}

		if ( 'https' === strtolower( trim( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) ) ) {
			return true;
		}

		if ( '443' === (string) ( $server['SERVER_PORT'] ?? '' ) ) {
			return true;
		}

		return 'https' === Cloudflare::resolve_scheme( $server );
	}

	/* ---------------------------------------------------------------------
	 * Emission.
	 * ------------------------------------------------------------------- */

	/**
	 * Send the headers on the live response. Hooked to send_headers, which
	 * fires for front-end requests before output; the admin already receives
	 * X-Frame-Options and Referrer-Policy from WordPress core.
	 */
	public function send(): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( self::headers( $_SERVER, Environment::is_production() ) as $name => $value ) {
			header( sprintf( '%s: %s', $name, $value ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Site Health + dashboard reporting.
	 * ------------------------------------------------------------------- */

	public function add_health_check( array $checks ): array {
		$checks['security_headers'] = array(
			'label'    => __( 'Security headers', 'upsun-mu-plugin' ),
			'callback' => array( self::class, 'check' ),
		);

		return $checks;
	}

	public function add_dashboard_panel( array $panels ): array {
		$panels['security-headers'] = array(
			'title'   => __( 'Security headers', 'upsun-mu-plugin' ),
			'render'  => array( $this, 'render_panel' ),
			'context' => 'column3',
		);

		return $panels;
	}

	/**
	 * A human-readable summary of what HSTS is doing for this request:
	 * emitted here, deferred to Cloudflare, or not asserted.
	 */
	public static function hsts_disposition( array $server, bool $is_production ): string {
		if ( self::should_emit_hsts( $server, $is_production ) ) {
			return 'emitted';
		}

		if ( self::is_https( $server ) && Cloudflare::is_fronted( $server ) ) {
			return 'deferred-cloudflare';
		}

		return 'off';
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		$server   = $_SERVER;
		$emitted  = array_keys( self::headers( $server, Environment::is_production() ) );
		$baseline = implode( ', ', array_keys( self::BASELINE ) );

		switch ( self::hsts_disposition( $server, Environment::is_production() ) ) {
			case 'emitted':
				return array(
					'status'  => 'pass',
					'message' => sprintf(
						/* translators: %s: comma-separated header names. */
						__( 'Security headers are set by the plugin (%s), and HSTS is emitted here — no CDN was detected in front of this request.', 'upsun-mu-plugin' ),
						implode( ', ', $emitted )
					),
				);

			case 'deferred-cloudflare':
				return array(
					'status'  => 'pass',
					'message' => sprintf(
						/* translators: %s: comma-separated baseline header names. */
						__( 'Baseline security headers are set by the plugin (%s). HSTS is deferred to Cloudflare because this request is proxied by it — enable HSTS at the Cloudflare edge (SSL/TLS → Edge Certificates → HSTS) so it is set exactly once.', 'upsun-mu-plugin' ),
						$baseline
					),
				);

			default:
				return array(
					'status'  => 'pass',
					'message' => sprintf(
						/* translators: %s: comma-separated baseline header names. */
						__( 'Baseline security headers are set by the plugin (%s). HSTS is not asserted on this request (non-production or non-HTTPS).', 'upsun-mu-plugin' ),
						$baseline
					),
				);
		}
	}

	/**
	 * Dashboard panel: the headers being emitted for this request and the
	 * HSTS disposition.
	 */
	public function render_panel(): void {
		$server      = $_SERVER;
		$headers     = self::headers( $server, Environment::is_production() );
		$disposition = self::hsts_disposition( $server, Environment::is_production() );

		$labels = array(
			'emitted'             => __( 'emitted by this plugin', 'upsun-mu-plugin' ),
			'deferred-cloudflare' => __( 'deferred to Cloudflare', 'upsun-mu-plugin' ),
			'off'                 => __( 'not asserted (non-production or non-HTTPS)', 'upsun-mu-plugin' ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $headers as $name => $value ) {
			printf(
				'<tr><td><code>%s</code></td><td><code>%s</code></td></tr>',
				esc_html( (string) $name ),
				esc_html( (string) $value )
			);
		}

		printf(
			'<tr><td>%s</td><td>%s</td></tr>',
			esc_html__( 'HSTS', 'upsun-mu-plugin' ),
			esc_html( $labels[ $disposition ] ?? $disposition )
		);

		echo '</tbody></table>';
	}
}
