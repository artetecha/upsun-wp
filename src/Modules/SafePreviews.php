<?php
/**
 * Neuter live outbound integrations on preview environments.
 *
 * Upsun previews hold a byte-for-byte clone of production — including live
 * payment keys, webhook targets, and mail configuration. This module applies
 * runtime protections on non-production environments (filters, never DB
 * writes, so the cloned data stays untouched) and detects fresh clones via
 * an environment-name stamp so consumers can run one-time sanitize actions.
 *
 * Built in here: the plugin-agnostic mail protection and the sanitize
 * machinery. Plugin-specific protections live in src/Integrations/ and join
 * through the upsun_safe_previews_actions filter.
 *
 * On production the module only maintains the stamp: the clone carries the
 * parent's stamp, and the mismatch with the clone's own environment name is
 * exactly what makes a fresh clone detectable.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class SafePreviews implements Module {

	/** Environment name the current database last ran under (autoloaded). */
	public const STAMP_OPTION = 'upsun_environment_stamp';

	/** Timestamp of the last sanitize run on this database. */
	public const SANITIZED_OPTION = 'upsun_preview_sanitize_last';

	/** Report of the last sanitize run: [ time, reports[] ] (non-autoloaded). */
	public const REPORT_OPTION = 'upsun_preview_sanitize_report';

	/** Ring buffer of recently intercepted mail (to/subject/time only). */
	public const MAIL_LOG_OPTION = 'upsun_intercepted_mail';

	/** Consumer hook fired on fresh clones and by `wp upsun sanitize`. */
	public const SANITIZE_HOOK = 'upsun_preview_sanitize';

	/** admin-post action for the dashboard "Sanitize now" button. */
	public const SANITIZE_ACTION = 'upsun_safe_previews_sanitize';

	private const MAIL_LOG_MAX = 20;

	public function should_load(): bool {
		/**
		 * Filters whether preview safety is active.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_safe_previews_enabled', true );
	}

	public function register(): void {
		// The primary sanitize/stamp trigger is `wp upsun sanitize
		// --if-needed` in the post_deploy hook — the only hook that runs on
		// every redeploy, including data syncs. The per-request boot check
		// is an opt-in fallback for projects that cannot edit their hooks;
		// the preview_safety check warns when neither has run.
		if ( $this->boot_check_enabled() ) {
			add_action( 'init', array( $this, 'check_environment_stamp' ) );
		}

		add_filter( 'upsun_site_health_tests', array( $this, 'add_check' ) );
		add_filter( 'upsun_dashboard_panels', array( $this, 'add_panel' ) );

		if ( Environment::is_production() ) {
			return;
		}

		add_action( 'admin_post_' . self::SANITIZE_ACTION, array( $this, 'handle_sanitize_action' ) );

		// Registration happens at muplugins_loaded, before regular plugins
		// load, so every hook must be an unconditional no-op when its target
		// plugin is absent; the per-concern filters are consulted lazily at
		// call time so consumer opt-outs work regardless of load order.
		foreach ( $this->protections() as $protection ) {
			if ( is_callable( $protection['register'] ?? null ) ) {
				call_user_func( $protection['register'] );
			}
		}
	}

	/**
	 * The protection registry: id => [ label, register, status ].
	 *
	 * register() attaches the runtime hooks; status() runs at render time
	 * (plugins loaded) and returns [ 'state' => active|inactive|off,
	 * 'detail' => string ] — inactive means the target plugin is absent,
	 * off means a consumer filter disabled the protection.
	 *
	 * Only the plugin-agnostic mail protection is built in here;
	 * plugin-specific protections (WooCommerce webhooks, WooCommerce Stripe
	 * test mode) are contributed by their Integrations classes through the
	 * same filter consumers use.
	 *
	 * @return array<string, array{label: string, register: callable, status: callable}>
	 */
	public function protections(): array {
		$protections = array(
			'mail' => array(
				'label'    => __( 'Outbound mail', 'upsun-mu-plugin' ),
				'register' => array( $this, 'register_mail_protection' ),
				'status'   => array( $this, 'mail_status' ),
			),
		);

		/**
		 * Filters the preview protection registry. Built-in Integrations and
		 * consumers add entries for specific plugins (gateways, CRMs) or
		 * remove existing ones.
		 *
		 * @param array $protections id => [ 'label', 'register', 'status' ].
		 */
		return (array) apply_filters( 'upsun_safe_previews_actions', $protections );
	}

	/* ---------------------------------------------------------------------
	 * Outbound mail.
	 * ------------------------------------------------------------------- */

	public function register_mail_protection(): void {
		// PHP_INT_MAX so the verdict runs after any mail-logging plugins.
		add_filter( 'pre_wp_mail', array( $this, 'maybe_intercept_mail' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_mail', array( $this, 'maybe_redirect_mail' ), PHP_INT_MAX );
	}

	/**
	 * The effective mail policy: 'intercept', 'allow', or a validated
	 * 'redirect:<address>'. Anything malformed fails safe to 'intercept'.
	 */
	public function mail_mode(): string {
		/**
		 * Filters the preview mail policy.
		 *
		 * @param string $mode 'intercept' (default), 'allow', or
		 *                     'redirect:qa@example.com'.
		 */
		$mode = (string) apply_filters( 'upsun_safe_previews_mail', 'intercept' );

		if ( 'allow' === $mode ) {
			return 'allow';
		}

		if ( 0 === strpos( $mode, 'redirect:' ) ) {
			$address = trim( substr( $mode, strlen( 'redirect:' ) ) );

			if ( false !== filter_var( $address, FILTER_VALIDATE_EMAIL ) ) {
				return 'redirect:' . $address;
			}
		}

		return 'intercept';
	}

	/**
	 * pre_wp_mail: short-circuit sending in intercept mode. Returns true
	 * (reported as sent) so calling plugins follow their success path.
	 *
	 * @param mixed $short_circuit Null to proceed with sending.
	 * @param mixed $atts          wp_mail() arguments.
	 * @return mixed
	 */
	public function maybe_intercept_mail( $short_circuit, $atts ) {
		if ( 'intercept' !== $this->mail_mode() ) {
			return $short_circuit;
		}

		$this->log_intercepted_mail( is_array( $atts ) ? $atts : array() );

		return true;
	}

	/**
	 * wp_mail: rewrite recipients in redirect mode, keeping the original
	 * addresses in an X-Upsun-Original-To header.
	 *
	 * @param mixed $atts wp_mail() arguments.
	 * @return mixed
	 */
	public function maybe_redirect_mail( $atts ) {
		$mode = $this->mail_mode();

		if ( 0 !== strpos( $mode, 'redirect:' ) || ! is_array( $atts ) ) {
			return $atts;
		}

		$original = $atts['to'] ?? '';
		$original = implode( ', ', array_map( 'strval', (array) $original ) );

		$atts['to'] = substr( $mode, strlen( 'redirect:' ) );

		$headers = $atts['headers'] ?? array();
		$header  = 'X-Upsun-Original-To: ' . $original;

		if ( is_array( $headers ) ) {
			$headers[] = $header;
		} else {
			$headers = rtrim( (string) $headers ) . ( '' === trim( (string) $headers ) ? '' : "\r\n" ) . $header;
		}

		$atts['headers'] = $headers;

		return $atts;
	}

	/**
	 * @return array{state: string, detail: string}
	 */
	public function mail_status(): array {
		$mode = $this->mail_mode();

		// The platform blocks its own SMTP proxy on previews by default, but
		// that toggle does not reach external SMTP/API mailers configured in
		// the cloned data — report both layers so the picture is complete.
		$relay = null === Environment::smtp_host()
			? __( 'platform email off', 'upsun-mu-plugin' )
			: __( 'platform email on', 'upsun-mu-plugin' );

		if ( 'allow' === $mode ) {
			return array(
				'state'  => 'off',
				'detail' => sprintf( '%s; %s', __( 'sending allowed (upsun_safe_previews_mail filter)', 'upsun-mu-plugin' ), $relay ),
			);
		}

		if ( 0 === strpos( $mode, 'redirect:' ) ) {
			return array(
				'state'  => 'active',
				'detail' => sprintf(
					/* translators: 1: email address, 2: platform relay state. */
					__( 'redirected to %1$s; %2$s', 'upsun-mu-plugin' ),
					substr( $mode, strlen( 'redirect:' ) ),
					$relay
				),
			);
		}

		$intercepted = get_option( self::MAIL_LOG_OPTION );

		return array(
			'state'  => 'active',
			'detail' => sprintf(
				/* translators: 1: number of messages, 2: platform relay state. */
				__( 'intercepted, never sent (%1$d logged recently); %2$s', 'upsun-mu-plugin' ),
				is_array( $intercepted ) ? count( $intercepted ) : 0,
				$relay
			),
		);
	}

	private function log_intercepted_mail( array $atts ): void {
		$to  = implode( ', ', array_map( 'strval', (array) ( $atts['to'] ?? array() ) ) );
		$log = get_option( self::MAIL_LOG_OPTION );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'to'      => $to,
				'subject' => (string) ( $atts['subject'] ?? '' ),
				'time'    => time(),
			)
		);

		update_option( self::MAIL_LOG_OPTION, array_slice( $log, 0, self::MAIL_LOG_MAX ), false );

		error_log( sprintf( '[upsun-mu-plugin] Intercepted wp_mail on preview environment: to=%s subject=%s', $to, (string) ( $atts['subject'] ?? '' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Fresh-clone detection and sanitize.
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the opt-in per-request stamp check runs at init. Off by
	 * default: post_deploy is the intended trigger, and a boot-time check
	 * would run consumer sanitize callbacks inside a live request.
	 */
	public function boot_check_enabled(): bool {
		/**
		 * Filters whether the environment stamp is checked on every boot,
		 * as a fallback for projects without the post_deploy hook wiring.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'upsun_safe_previews_boot_check', false );
	}

	/**
	 * Whether the current database is stamped for this environment — i.e.
	 * sanitize has run here since the data was last cloned or synced in
	 * (or, on production, whether the stamp that makes clones detectable
	 * is current).
	 */
	public function is_sanitized(): bool {
		$current = Environment::name();

		return null !== $current && '' !== $current
			&& get_option( self::STAMP_OPTION ) === $current;
	}

	/**
	 * Write the current environment name into the stamp option (production
	 * upkeep: the clone carries this value, making the copy detectable).
	 */
	public function refresh_stamp(): void {
		$current = Environment::name();

		if ( null !== $current && '' !== $current ) {
			update_option( self::STAMP_OPTION, $current );
		}
	}

	/**
	 * Compare the stamped environment name with the current one. A mismatch
	 * on a preview means this database was just cloned or synced from
	 * elsewhere: fire the one-time sanitize hook. Production only refreshes
	 * the stamp. Used by the opt-in boot check.
	 */
	public function check_environment_stamp(): void {
		$current = Environment::name();

		if ( null === $current || '' === $current || $this->is_sanitized() ) {
			return;
		}

		if ( Environment::is_production() ) {
			$this->refresh_stamp();

			return;
		}

		$stored = get_option( self::STAMP_OPTION );

		$this->run_sanitize( is_string( $stored ) ? $stored : null );
	}

	/**
	 * Restamp, run the enabled sanitizers, and fire the consumer sanitize
	 * hook. The stamp is written before anything runs so concurrent first
	 * requests do not re-trigger; sanitizers and hooked callbacks must
	 * still be idempotent. Built-in sanitizers run before the hook so
	 * consumer callbacks get the final say.
	 *
	 * @param string|null $previous The environment the database came from.
	 * @return array{previous: ?string, environment: string, listeners: bool, reports: string[]}
	 */
	public function run_sanitize( ?string $previous = null ): array {
		$current = (string) Environment::name();

		update_option( self::STAMP_OPTION, $current );

		$reports = \Upsun\Sanitizers::run( false );

		/**
		 * Fires when a preview database needs sanitizing: on the first boot
		 * of a fresh clone, from the dashboard button, and from
		 * `wp upsun sanitize`. Scrub or reconfigure site-specific
		 * integrations here. Callbacks must be idempotent.
		 *
		 * @param string|null $previous Environment the database came from.
		 * @param string      $current  Current environment name.
		 */
		do_action( self::SANITIZE_HOOK, $previous, $current );

		update_option( self::SANITIZED_OPTION, time(), false );
		update_option(
			self::REPORT_OPTION,
			array(
				'time'    => time(),
				'reports' => $reports,
			),
			false
		);

		return array(
			'previous'    => $previous,
			'environment' => $current,
			'listeners'   => (bool) has_action( self::SANITIZE_HOOK ),
			'reports'     => $reports,
		);
	}

	/* ---------------------------------------------------------------------
	 * Health check.
	 * ------------------------------------------------------------------- */

	public function add_check( array $checks ): array {
		$checks['preview_safety'] = array(
			'label'    => __( 'Preview safety', 'upsun-mu-plugin' ),
			'callback' => array( $this, 'check' ),
		);

		return $checks;
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public function check(): array {
		if ( Environment::is_production() ) {
			if ( $this->is_sanitized() ) {
				return array(
					'status'  => 'pass',
					'message' => __( 'Production environment; preview protections are idle and the environment stamp keeps clones detectable.', 'upsun-mu-plugin' ),
				);
			}

			return array(
				'status'  => 'warn',
				'message' => __( 'Production environment, but the environment stamp is not current — add "wp upsun sanitize --if-needed" to the post_deploy hook so preview clones stay detectable.', 'upsun-mu-plugin' ),
			);
		}

		$parts  = array();
		$status = 'pass';

		foreach ( $this->protections() as $id => $protection ) {
			if ( ! is_callable( $protection['status'] ?? null ) ) {
				continue;
			}

			$state = call_user_func( $protection['status'] );
			$label = (string) ( $protection['label'] ?? $id );

			$parts[] = sprintf( '%s: %s', $label, (string) ( $state['detail'] ?? '' ) );

			// A consumer deliberately switched a protection off on a clone
			// of production — worth a nudge, not a failure.
			if ( 'off' === ( $state['state'] ?? '' ) ) {
				$status = 'warn';
			}
		}

		$message = sprintf(
			/* translators: %s: protection summary list. */
			__( 'Preview environment. %s.', 'upsun-mu-plugin' ),
			implode( '; ', $parts )
		);

		if ( ! $this->is_sanitized() ) {
			$status   = 'warn';
			$message .= ' ' . __( 'The sanitize actions have not run for this data yet — add "wp upsun sanitize --if-needed" to the post_deploy hook (the only hook that runs on data syncs), or run it manually.', 'upsun-mu-plugin' );
		}

		return array(
			'status'  => $status,
			'message' => $message,
		);
	}

	/* ---------------------------------------------------------------------
	 * Dashboard panel.
	 * ------------------------------------------------------------------- */

	public function add_panel( array $panels ): array {
		$panels['preview-safety'] = array(
			'title'   => __( 'Preview safety', 'upsun-mu-plugin' ),
			'render'  => array( $this, 'render_panel' ),
			'context' => 'side',
		);

		return $panels;
	}

	public function render_panel(): void {
		$this->render_panel_notice();

		if ( Environment::is_production() ) {
			echo '<p>' . esc_html__( 'This is the production environment: protections are idle. The plugin maintains an environment stamp here so fresh preview clones can be detected and sanitized.', 'upsun-mu-plugin' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><tbody>';

		foreach ( $this->protections() as $id => $protection ) {
			if ( ! is_callable( $protection['status'] ?? null ) ) {
				continue;
			}

			$state = call_user_func( $protection['status'] );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) ( $protection['label'] ?? $id ) ),
				$this->state_badge( (string) ( $state['state'] ?? '' ) ),
				esc_html( (string) ( $state['detail'] ?? '' ) )
			);
		}

		echo '</tbody></table>';

		$stamp     = get_option( self::STAMP_OPTION );
		$sanitized = get_option( self::SANITIZED_OPTION );

		printf(
			'<p>%s <code>%s</code> · %s <strong>%s</strong></p>',
			esc_html__( 'Environment stamp:', 'upsun-mu-plugin' ),
			esc_html( is_string( $stamp ) && '' !== $stamp ? $stamp : '—' ),
			esc_html__( 'Last sanitize:', 'upsun-mu-plugin' ),
			esc_html(
				is_numeric( $sanitized ) && (int) $sanitized > 0
					? sprintf(
						/* translators: %s: human-readable time difference. */
						__( '%s ago', 'upsun-mu-plugin' ),
						human_time_diff( (int) $sanitized, time() )
					)
					: __( 'never', 'upsun-mu-plugin' )
			)
		);

		if ( ! $this->is_sanitized() ) {
			printf(
				'<p><em>%s</em> <code>wp upsun sanitize --if-needed</code></p>',
				esc_html__( 'The sanitize actions have not run for this data yet. Use the button below, or wire the post_deploy hook:', 'upsun-mu-plugin' )
			);
		}

		$this->render_sanitizers();
		$this->render_intercepted_mail();
		$this->render_sanitize_form();

		echo '<p>' . esc_html__( 'Protections are runtime-only: the cloned database is never modified (except the stamp options above).', 'upsun-mu-plugin' ) . '</p>';
	}

	private function render_sanitizers(): void {
		$registry = \Upsun\Sanitizers::registry();

		if ( array() !== $registry ) {
			$parts = array();

			foreach ( $registry as $id => $sanitizer ) {
				$parts[] = sprintf(
					'%s (%s)',
					(string) $id,
					\Upsun\Sanitizers::is_enabled( (string) $id, $sanitizer )
						? __( 'enabled', 'upsun-mu-plugin' )
						: __( 'disabled', 'upsun-mu-plugin' )
				);
			}

			printf(
				'<p>%s %s</p>',
				esc_html__( 'Sanitizers (opt-in via filters):', 'upsun-mu-plugin' ),
				esc_html( implode( ', ', $parts ) )
			);
		}

		$report = get_option( self::REPORT_OPTION );

		if ( is_array( $report ) && ! empty( $report['reports'] ) && is_array( $report['reports'] ) ) {
			echo '<ul style="list-style: disc; margin-left: 1.2em;">';

			foreach ( $report['reports'] as $line ) {
				printf( '<li>%s</li>', esc_html( (string) $line ) );
			}

			echo '</ul>';
		}
	}

	private function render_intercepted_mail(): void {
		$log = get_option( self::MAIL_LOG_OPTION );

		if ( ! is_array( $log ) || array() === $log ) {
			return;
		}

		echo '<details><summary>' . esc_html(
			sprintf(
				/* translators: %d: number of messages. */
				__( 'Recently intercepted mail (%d)', 'upsun-mu-plugin' ),
				count( $log )
			)
		) . '</summary><ul>';

		foreach ( array_slice( $log, 0, 5 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			printf(
				'<li><code>%s</code> — %s <em>(%s)</em></li>',
				esc_html( (string) ( $entry['to'] ?? '' ) ),
				esc_html( (string) ( $entry['subject'] ?? '' ) ),
				esc_html(
					is_numeric( $entry['time'] ?? null )
						? sprintf(
							/* translators: %s: human-readable time difference. */
							__( '%s ago', 'upsun-mu-plugin' ),
							human_time_diff( (int) $entry['time'], time() )
						)
						: '—'
				)
			);
		}

		echo '</ul></details>';
	}

	private function render_sanitize_form(): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::SANITIZE_ACTION ) );
		wp_nonce_field( self::SANITIZE_ACTION );
		printf(
			'<p><button type="submit" class="button button-secondary">%s</button> %s</p>',
			esc_html__( 'Run sanitize actions now', 'upsun-mu-plugin' ),
			esc_html__( '(fires upsun_preview_sanitize)', 'upsun-mu-plugin' )
		);
		echo '</form>';
	}

	public function handle_sanitize_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'upsun-mu-plugin' ), '', 403 );
		}

		check_admin_referer( self::SANITIZE_ACTION );

		$stored = get_option( self::STAMP_OPTION );

		$this->run_sanitize( is_string( $stored ) ? $stored : null );

		wp_safe_redirect(
			add_query_arg(
				'upsun-notice',
				'sanitized',
				admin_url( 'admin.php?page=' . Dashboard::MENU_SLUG )
			)
		);
		exit;
	}

	private function render_panel_notice(): void {
		// Set only by our own redirect after the admin-post action.
		$notice = isset( $_GET['upsun-notice'] ) ? sanitize_key( (string) $_GET['upsun-notice'] ) : '';

		if ( 'sanitized' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible inline"><p>%s</p></div>',
				esc_html__( 'Sanitize actions fired.', 'upsun-mu-plugin' )
			);
		}
	}

	private function state_badge( string $state ): string {
		$map = array(
			'active'   => array( '#00a32a', __( 'active', 'upsun-mu-plugin' ) ),
			'inactive' => array( '#757575', __( 'n/a', 'upsun-mu-plugin' ) ),
			'off'      => array( '#dba617', __( 'disabled', 'upsun-mu-plugin' ) ),
		);

		list( $color, $label ) = $map[ $state ] ?? $map['off'];

		return sprintf(
			'<span style="color: %s; font-weight: 600;">&#9679; %s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}
}
