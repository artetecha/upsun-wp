<?php
/**
 * WooCommerce Stripe gateway support: forced into test mode on preview
 * clones so the live keys copied from production can never charge anyone.
 * Runtime-only — the settings option in the cloned database is untouched,
 * and missing test keys simply make the gateway unavailable (fails safe).
 *
 * Forcing test mode creates an obligation this integration also carries:
 * saying so when the test keys it switched the site onto do not work.
 * Missing keys fail safe, but keys that are present and REJECTED by Stripe
 * leave the gateway available and broken — card fields error at payment
 * time and the express-checkout element 401s on every cart page load. That
 * state is invisible from the WordPress admin (the gateway settings screen
 * renders happily), so the dashboard panel below probes the configured test
 * keys against Stripe's API and reports the verdict where an operator will
 * see it.
 */

namespace Upsun\Integrations;

use Upsun\Integration;

class WooCommerceStripe implements Integration {

	/**
	 * How long a definite verdict (valid / invalid) is cached, in seconds.
	 * Keys change rarely, and the cache is keyed on the key value itself,
	 * so replacing a key re-probes immediately.
	 */
	private const VERDICT_TTL = 43200; // 12 hours.

	/**
	 * How long an inconclusive probe (network error, rate limit, Stripe
	 * outage) is cached, in seconds — long enough that dashboard reloads do
	 * not hammer a struggling host, short enough that the verdict recovers
	 * with connectivity.
	 */
	private const RETRY_TTL = 300; // 5 minutes.

	/**
	 * Per-probe HTTP timeout, in seconds. Probes run synchronously during
	 * the dashboard render, so with two keys the worst case is one
	 * 2 × TIMEOUT stall per cache window — bounded, but worth keeping short.
	 */
	private const TIMEOUT = 3;

	/*
	 * Verdict tokens. One name per state, shared by key_verdict(), the
	 * label map, and the broken-state check, so a rename cannot silently
	 * desynchronize them.
	 */
	private const VALID                = 'valid';
	private const INVALID              = 'invalid';
	private const MISSING              = 'missing';
	private const UNVERIFIED_DISABLED  = 'unverified-disabled';
	private const UNVERIFIED_NO_ANSWER = 'unverified-no-answer';

	public function label(): string {
		return 'WooCommerce Stripe';
	}

	public function is_active(): bool {
		return class_exists( 'WC_Stripe' );
	}

	public function register(): void {
		add_filter( 'upsun_safe_previews_actions', array( $this, 'add_protection' ), 5 );
		add_filter( 'upsun_dashboard_panels', array( $this, 'add_keys_panel' ) );
	}

	public function add_protection( array $protections ): array {
		$protections['woocommerce-stripe'] = array(
			'label'    => __( 'WooCommerce Stripe', 'upsun-mu-plugin' ),
			'register' => array( $this, 'register_protection' ),
			'status'   => array( $this, 'status' ),
		);

		return $protections;
	}

	public function register_protection(): void {
		add_filter( 'option_woocommerce_stripe_settings', array( $this, 'force_test_mode' ) );
	}

	/**
	 * Force the gateway into test mode at read time. The cloned live keys
	 * stay in the database untouched; if no test keys are configured the
	 * gateway simply becomes unavailable, which is the safe outcome.
	 *
	 * @param mixed $settings The woocommerce_stripe_settings option value.
	 * @return mixed
	 */
	public function force_test_mode( $settings ) {
		if ( ! is_array( $settings ) || ! $this->test_mode_forced() ) {
			return $settings;
		}

		$settings['testmode'] = 'yes';

		return $settings;
	}

	/**
	 * @return array{state: string, detail: string}
	 */
	public function status(): array {
		if ( ! $this->test_mode_forced() ) {
			return array(
				'state'  => 'off',
				'detail' => __( 'not forced (upsun_woocommerce_stripe_test_mode filter)', 'upsun-mu-plugin' ),
			);
		}

		if ( ! $this->is_active() ) {
			return array(
				'state'  => 'inactive',
				'detail' => __( 'WooCommerce Stripe not detected', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'state'  => 'active',
			'detail' => __( 'test mode forced at runtime (live keys unused)', 'upsun-mu-plugin' ),
		);
	}

	private function test_mode_forced(): bool {
		/**
		 * Filters whether WooCommerce Stripe is forced into test mode on
		 * previews.
		 *
		 * @param bool $forced Default true.
		 */
		return (bool) apply_filters( 'upsun_woocommerce_stripe_test_mode', true );
	}

	/* ---------------------------------------------------------------------
	 * Dashboard: do the test keys actually work?
	 * ------------------------------------------------------------------ */

	public function add_keys_panel( array $panels ): array {
		$panels['stripe-keys'] = array(
			'title'   => __( 'Stripe keys', 'upsun-mu-plugin' ),
			'render'  => array( $this, 'render_keys_panel' ),
			'context' => 'side',
		);

		return $panels;
	}

	/**
	 * Report whether the keys the gateway is currently using are usable.
	 *
	 * Reads the settings through get_option() so the effective mode is the
	 * one force_test_mode() produced, not whatever the cloned database says.
	 * Live mode is reported but never probed — a production gateway proves
	 * its keys by taking payments, and this panel has no business calling
	 * Stripe with live credentials. Test mode probes both keys, cached.
	 */
	public function render_keys_panel(): void {
		if ( ! $this->is_active() ) {
			echo '<p>' . esc_html__( 'WooCommerce Stripe not detected.', 'upsun-mu-plugin' ) . '</p>';
			return;
		}

		$settings = get_option( 'woocommerce_stripe_settings' );

		if ( ! is_array( $settings ) ) {
			echo '<p>' . esc_html__( 'Gateway not configured.', 'upsun-mu-plugin' ) . '</p>';
			return;
		}

		if ( 'yes' !== ( $settings['testmode'] ?? 'no' ) ) {
			echo '<p>' . esc_html__( 'Live mode — keys are not probed from the dashboard; a live gateway proves itself by taking payments.', 'upsun-mu-plugin' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Test mode is in effect; the gateway is using the test keys below.', 'upsun-mu-plugin' ) . '</p>';

		$keys = array(
			__( 'Publishable key', 'upsun-mu-plugin' ) => array( trim( (string) ( $settings['test_publishable_key'] ?? '' ) ), 'publishable' ),
			__( 'Secret key', 'upsun-mu-plugin' )      => array( trim( (string) ( $settings['test_secret_key'] ?? '' ) ), 'secret' ),
		);

		$labels = array(
			self::MISSING              => __( 'missing', 'upsun-mu-plugin' ),
			self::VALID                => __( 'valid', 'upsun-mu-plugin' ),
			self::INVALID              => __( 'INVALID — rejected by Stripe', 'upsun-mu-plugin' ),
			self::UNVERIFIED_DISABLED  => __( 'unverified (probing disabled)', 'upsun-mu-plugin' ),
			self::UNVERIFIED_NO_ANSWER => __( 'unverified (no conclusive answer from Stripe)', 'upsun-mu-plugin' ),
		);

		$broken = false;

		echo '<table class="widefat striped"><tbody>';

		foreach ( $keys as $label => list( $key, $type ) ) {
			$verdict = '' === $key ? self::MISSING : $this->key_verdict( $key, $type );
			$broken  = $broken || in_array( $verdict, array( self::MISSING, self::INVALID ), true );

			printf(
				'<tr><td>%s</td><td><strong>%s</strong></td></tr>',
				esc_html( $label ),
				esc_html( $labels[ $verdict ] ?? $verdict )
			);
		}

		echo '</tbody></table>';

		if ( $broken ) {
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html__( 'Checkout will fail on this environment.', 'upsun-mu-plugin' ),
				esc_html__( 'Card fields error at payment time and the express-checkout element is rejected on every cart page (HTTP 401). Configure valid Stripe TEST keys in the gateway settings (Stripe dashboard → test mode → API keys).', 'upsun-mu-plugin' )
			);
		}
	}

	/**
	 * Probe one key against Stripe and cache the verdict.
	 *
	 * A publishable key is exercised the way stripe.js exercises it — a
	 * tokens request carrying the key and nothing else. Stripe rejects a
	 * dead key with 401; a live key answers 400 (no card supplied), which
	 * is proof enough. A secret key is read-only probed via /v1/account.
	 * Only 401 means invalid, and only the probe's expected happy answers
	 * mean valid; a transport failure, a rate limit, or a Stripe outage is
	 * reported as unverified rather than guessed at. Probes run
	 * synchronously in the dashboard render: the worst case is a
	 * 2 × TIMEOUT stall on the first render per cache window, and the
	 * upsun_woocommerce_stripe_validate_keys filter removes even that.
	 *
	 * @return string One of the verdict constants.
	 */
	private function key_verdict( string $key, string $type ): string {
		/**
		 * Filters whether the dashboard probes Stripe to verify the
		 * configured test keys. Disable on environments whose network
		 * policy forbids outbound calls from admin page loads; the panel
		 * then reports the keys as unverified instead of probing.
		 *
		 * @param bool $validate Default true.
		 */
		if ( ! apply_filters( 'upsun_woocommerce_stripe_validate_keys', true ) ) {
			return self::UNVERIFIED_DISABLED;
		}

		// The probe type is part of the cache key: the same string configured
		// in both fields must yield two independent probes, not one verdict
		// read back for the other key's endpoint.
		$cache_key = 'upsun_stripe_key_' . md5( $type . '|' . $key );
		$cached    = get_site_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( 'secret' === $type ) {
			$response = wp_remote_get(
				'https://api.stripe.com/v1/account',
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				)
			);
		} else {
			$response = wp_remote_post(
				'https://api.stripe.com/v1/tokens',
				array(
					'timeout' => self::TIMEOUT,
					'body'    => array( 'key' => $key ),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			set_site_transient( $cache_key, self::UNVERIFIED_NO_ANSWER, self::RETRY_TTL );

			return self::UNVERIFIED_NO_ANSWER;
		}

		// Only the answers this probe can interpret are conclusive: 401 is
		// Stripe rejecting the key; the expected happy answers are 200 for
		// /v1/account and 200/400/402 for the card-less tokens request
		// (400 = key accepted, card missing). Anything else — a 429, a 5xx,
		// an intercepting proxy — proves nothing about the key and is cached
		// briefly as unverified rather than guessed either way.
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$accepted = 'secret' === $type ? array( 200 ) : array( 200, 400, 402 );

		if ( 401 === $code ) {
			$verdict = self::INVALID;
		} elseif ( in_array( $code, $accepted, true ) ) {
			$verdict = self::VALID;
		} else {
			set_site_transient( $cache_key, self::UNVERIFIED_NO_ANSWER, self::RETRY_TTL );

			return self::UNVERIFIED_NO_ANSWER;
		}

		set_site_transient( $cache_key, $verdict, self::VERDICT_TTL );

		return $verdict;
	}
}
