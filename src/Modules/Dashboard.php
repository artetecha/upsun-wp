<?php
/**
 * The "Upsun" page in wp-admin: a platform home rendering environment,
 * services, health, caching, and module status, plus operational actions.
 *
 * Deliberately actions-not-settings: configuration lives in code
 * (constants/filters) where it is versioned and survives environment clones,
 * so this page never writes options. Panels are extensible via the
 * upsun_dashboard_panels filter.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;
use Upsun\ModuleRegistry;

class Dashboard implements Module {

	public const MENU_SLUG = 'upsun';

	private const FLUSH_ACTION = 'upsun_flush_object_cache';

	/**
	 * The official Upsun mark (upsun.com favicon, vector version). Embedded
	 * so admin pages make no external requests; served as a base64 data URI
	 * because wp-admin's svg-painter repaints those to match the admin color
	 * scheme (a bitmap would stay dark-on-dark in the menu).
	 */
	private const MENU_ICON_SVG = '<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.0064 10.3029C19.1584 10.3029 21.7066 12.8576 21.7066 16.0032H27.4069C27.4069 9.70559 22.3029 4.59625 16 4.59625C9.69702 4.59625 4.59302 9.70025 4.59302 16.0032H10.2933C10.3061 12.8512 12.8608 10.3029 16.0064 10.3029Z" fill="black"/><path d="M17.9392 21.3653C20.1365 20.5706 21.7067 18.4714 21.7067 16.0032H10.3051C10.3051 18.4714 11.8752 20.577 14.0725 21.3653V21.4634H5.99573C7.92853 25.0037 11.6907 27.4037 16.0117 27.4037C20.3328 27.4037 24.0885 25.0026 26.0277 21.4634H17.9381V21.3653H17.9392Z" fill="black"/></svg>';

	public function should_load(): bool {
		/**
		 * Filters whether the Upsun dashboard page is registered.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_dashboard_enabled', true );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'pin_menu_position' ), PHP_INT_MAX );
		add_action( 'admin_post_' . self::FLUSH_ACTION, array( $this, 'handle_flush_object_cache' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Upsun', 'upsun-mu-plugin' ),
			__( 'Upsun', 'upsun-mu-plugin' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			$this->menu_icon(),
			$this->menu_position()
		);
	}

	/**
	 * The default 2 means "directly below Dashboard" and is enforced by
	 * pin_menu_position() after all plugins have registered; any other
	 * value is passed to add_menu_page as-is, unpinned.
	 *
	 * @return int|float|string Anything add_menu_page accepts as a position.
	 */
	public function menu_position() {
		/**
		 * Filters the admin-menu position of the Upsun page.
		 *
		 * @param int $position Default 2 (pinned directly below Dashboard).
		 */
		return apply_filters( 'upsun_dashboard_menu_position', 2 );
	}

	/**
	 * Keep the page directly below Dashboard once every plugin has
	 * registered. Several plugins squat position 2, and core resolves each
	 * collision with an md5-of-slug fraction — so the relative order of the
	 * squatters is a hash lottery, not registration order. Re-keying our
	 * entry between Dashboard (2) and its current closest follower is the
	 * only deterministic placement. Skipped when
	 * upsun_dashboard_menu_position is filtered away from the default.
	 */
	public function pin_menu_position(): void {
		global $menu;

		if ( 2 !== $this->menu_position() || ! is_array( $menu ) ) {
			return;
		}

		$our_key = null;

		foreach ( $menu as $key => $item ) {
			if ( self::MENU_SLUG === (string) ( $item[2] ?? '' ) ) {
				$our_key = $key;
				break;
			}
		}

		if ( null === $our_key ) {
			return;
		}

		$entry = $menu[ $our_key ];
		unset( $menu[ $our_key ] );

		// The smallest position after Dashboard (2) among everyone else.
		$next = null;

		foreach ( $menu as $key => $item ) {
			$position = (float) $key;

			if ( $position > 2 && ( null === $next || $position < $next ) ) {
				$next = $position;
			}
		}

		$position = null === $next
			? '3'
			: rtrim( rtrim( number_format( 2 + ( $next - 2 ) / 2, 8, '.', '' ), '0' ), '.' );

		$menu[ $position ] = $entry;
		ksort( $menu );
	}

	/**
	 * The menu icon: the Upsun mark as a base64 SVG data URI.
	 */
	public function menu_icon(): string {
		/**
		 * Filters the admin-menu icon of the Upsun page. Accepts anything
		 * add_menu_page does: a data URI, an image URL, or a dashicon class.
		 *
		 * @param string $icon Default: the Upsun mark as a base64 SVG data URI.
		 */
		return (string) apply_filters(
			'upsun_dashboard_menu_icon',
			'data:image/svg+xml;base64,' . base64_encode( self::MENU_ICON_SVG )
		);
	}

	/**
	 * The panel registry: id => [ title, render ]. Render callbacks echo
	 * their panel body.
	 *
	 * @return array<string, array{title: string, render: callable}>
	 */
	public function panels(): array {
		$panels = array(
			'environment' => array(
				'title'  => __( 'Environment', 'upsun-mu-plugin' ),
				'render' => array( $this, 'render_environment_panel' ),
			),
			'services'    => array(
				'title'  => __( 'Services', 'upsun-mu-plugin' ),
				'render' => array( $this, 'render_services_panel' ),
			),
			'health'      => array(
				'title'  => __( 'Health', 'upsun-mu-plugin' ),
				'render' => array( $this, 'render_health_panel' ),
			),
			'caching'     => array(
				'title'  => __( 'Caching', 'upsun-mu-plugin' ),
				'render' => array( $this, 'render_caching_panel' ),
			),
			'modules'     => array(
				'title'  => __( 'Modules', 'upsun-mu-plugin' ),
				'render' => array( $this, 'render_modules_panel' ),
			),
		);

		/**
		 * Filters the dashboard panel registry. Modules and consumers can
		 * add, remove, or reorder panels.
		 *
		 * @param array $panels id => [ 'title' => string, 'render' => callable ].
		 */
		return (array) apply_filters( 'upsun_dashboard_panels', $panels );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap" style="max-width: 900px;">';
		printf(
			'<h1>%s <span style="font-size: 0.5em; color: #757575;">%s %s</span></h1>',
			esc_html__( 'Upsun', 'upsun-mu-plugin' ),
			esc_html__( 'plugin', 'upsun-mu-plugin' ),
			esc_html( \Upsun\version() )
		);

		$this->render_notice();

		echo '<div class="metabox-holder">';

		foreach ( $this->panels() as $id => $panel ) {
			if ( ! is_callable( $panel['render'] ?? null ) ) {
				continue;
			}

			printf( '<div class="postbox" id="upsun-panel-%s">', esc_attr( (string) $id ) );
			printf( '<h2 class="hndle" style="padding: 8px 12px; margin: 0;">%s</h2>', esc_html( (string) ( $panel['title'] ?? $id ) ) );
			echo '<div class="inside">';
			call_user_func( $panel['render'] );
			echo '</div></div>';
		}

		echo '</div></div>';
	}

	public function render_environment_panel(): void {
		global $wp_version;

		$rows = array(
			__( 'Environment', 'upsun-mu-plugin' )    => Environment::name(),
			__( 'Type', 'upsun-mu-plugin' )           => Environment::type(),
			__( 'Branch', 'upsun-mu-plugin' )         => Environment::branch(),
			__( 'Application', 'upsun-mu-plugin' )    => Environment::application_name(),
			__( 'Project', 'upsun-mu-plugin' )        => Environment::project(),
			__( 'Primary route', 'upsun-mu-plugin' )  => Environment::primary_route(),
			__( 'WordPress', 'upsun-mu-plugin' )      => is_string( $wp_version ?? null ) ? $wp_version : null,
			__( 'PHP', 'upsun-mu-plugin' )            => PHP_VERSION,
			__( 'Plugin version', 'upsun-mu-plugin' ) => \Upsun\version(),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width: 30%%;">%s</td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $value ?? '—' )
			);
		}

		echo '</tbody></table>';

		$project = Environment::project();

		if ( null !== $project ) {
			printf(
				'<p><a class="button button-secondary" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( 'https://console.upsun.com/projects/' . rawurlencode( $project ) ),
				esc_html__( 'Open in Upsun Console', 'upsun-mu-plugin' )
			);
		}
	}

	public function render_services_panel(): void {
		$relationships = Environment::relationships();

		if ( array() === $relationships ) {
			echo '<p>' . esc_html__( 'No relationships found.', 'upsun-mu-plugin' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';

			foreach ( array( __( 'Relationship', 'upsun-mu-plugin' ), __( 'Scheme', 'upsun-mu-plugin' ), __( 'Host', 'upsun-mu-plugin' ), __( 'Port', 'upsun-mu-plugin' ), __( 'Path', 'upsun-mu-plugin' ) ) as $heading ) {
				printf( '<th>%s</th>', esc_html( $heading ) );
			}

			echo '</tr></thead><tbody>';

			// Credentials are deliberately never rendered.
			foreach ( $relationships as $name => $instances ) {
				foreach ( (array) $instances as $instance ) {
					if ( ! is_array( $instance ) ) {
						continue;
					}

					printf(
						'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( (string) $name ),
						esc_html( (string) ( $instance['scheme'] ?? '—' ) ),
						esc_html( (string) ( $instance['host'] ?? '—' ) ),
						esc_html( (string) ( $instance['port'] ?? '—' ) ),
						esc_html( (string) ( $instance['path'] ?? '—' ) )
					);
				}
			}

			echo '</tbody></table>';
		}

		printf(
			'<p>%s <strong>%s</strong></p>',
			esc_html__( 'Persistent object cache:', 'upsun-mu-plugin' ),
			wp_using_ext_object_cache()
				? esc_html__( 'active', 'upsun-mu-plugin' )
				: esc_html__( 'not in use', 'upsun-mu-plugin' )
		);

		$this->render_flush_form();
	}

	public function render_health_panel(): void {
		echo '<table class="widefat striped"><tbody>';

		foreach ( SiteHealth::checks() as $id => $check ) {
			if ( ! is_callable( $check['callback'] ?? null ) ) {
				continue;
			}

			$result = call_user_func( $check['callback'] );

			printf(
				'<tr><td style="width: 30%%;">%s</td><td style="width: 10%%;">%s</td><td>%s</td></tr>',
				esc_html( (string) ( $check['label'] ?? $id ) ),
				$this->status_badge( (string) ( $result['status'] ?? 'warn' ) ),
				esc_html( (string) ( $result['message'] ?? '' ) )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p><a class="button button-secondary" href="%s">%s</a> <a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Re-run checks', 'upsun-mu-plugin' ),
			esc_url( admin_url( 'site-health.php' ) ),
			esc_html__( 'Open Site Health', 'upsun-mu-plugin' )
		);
	}

	public function render_caching_panel(): void {
		$ttl      = (int) apply_filters( 'upsun_page_cache_ttl', PageCache::DEFAULT_TTL );
		$patterns = (array) apply_filters( 'upsun_page_cache_bypass_cookie_patterns', PageCache::DEFAULT_COOKIE_PATTERNS );
		$stripped = array_filter( array_map( 'strval', (array) apply_filters( 'upsun_page_cache_strip_cookies', array() ) ) );
		$state    = ModuleRegistry::status()['page-cache']['state'] ?? 'unknown';

		// Values below are resolved through the live filters, so consumer
		// overrides are reflected exactly as the PageCache module sees them.
		$rows = array(
			__( 'Page cache module', 'upsun-mu-plugin' )     => $this->state_label( $state ),
			__( 'Shared-cache TTL', 'upsun-mu-plugin' )      => $ttl > 0
				? sprintf( 's-maxage=%d', $ttl )
				: __( 'disabled (TTL <= 0)', 'upsun-mu-plugin' ),
			__( 'Bypass cookie patterns', 'upsun-mu-plugin' ) => implode( '  ', array_map( 'strval', $patterns ) ),
			__( 'Stripped cookies', 'upsun-mu-plugin' )      => $stripped
				? implode( ', ', $stripped )
				: __( '(none)', 'upsun-mu-plugin' ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width: 30%%;">%s</td><td><code>%s</code></td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';

		echo '<p>' . esc_html__( 'The Upsun router cache has no purge API: cached pages expire by TTL or on redeploy.', 'upsun-mu-plugin' ) . '</p>';
	}

	public function render_modules_panel(): void {
		$status = ModuleRegistry::status();

		if ( array() === $status ) {
			echo '<p>' . esc_html__( 'No modules booted (kill switch active or not running on Upsun).', 'upsun-mu-plugin' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Module', 'upsun-mu-plugin' ), __( 'Status', 'upsun-mu-plugin' ), __( 'Class', 'upsun-mu-plugin' ) ) as $heading ) {
			printf( '<th>%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $status as $id => $module ) {
			printf(
				'<tr><td style="width: 30%%;">%s</td><td style="width: 30%%;">%s</td><td><code>%s</code></td></tr>',
				esc_html( (string) $id ),
				esc_html( $this->state_label( $module['state'], (string) $id ) ),
				esc_html( $module['class'] )
			);
		}

		echo '</tbody></table>';
	}

	public function handle_flush_object_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'upsun-mu-plugin' ), '', 403 );
		}

		check_admin_referer( self::FLUSH_ACTION );

		$flushed = wp_cache_flush();

		wp_safe_redirect(
			add_query_arg(
				'upsun-notice',
				$flushed ? 'cache-flushed' : 'cache-flush-failed',
				admin_url( 'admin.php?page=' . self::MENU_SLUG )
			)
		);
		exit;
	}

	private function render_flush_form(): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::FLUSH_ACTION ) );
		wp_nonce_field( self::FLUSH_ACTION );
		printf(
			'<p><button type="submit" class="button button-secondary">%s</button></p>',
			esc_html__( 'Flush object cache', 'upsun-mu-plugin' )
		);
		echo '</form>';
	}

	private function render_notice(): void {
		// Set only by our own redirect after admin-post actions.
		$notice = isset( $_GET['upsun-notice'] ) ? sanitize_key( (string) $_GET['upsun-notice'] ) : '';

		if ( 'cache-flushed' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Object cache flushed.', 'upsun-mu-plugin' )
			);
		} elseif ( 'cache-flush-failed' === $notice ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'Object cache flush failed.', 'upsun-mu-plugin' )
			);
		}
	}

	private function status_badge( string $status ): string {
		$map = array(
			'pass' => array( '#00a32a', __( 'pass', 'upsun-mu-plugin' ) ),
			'warn' => array( '#dba617', __( 'warning', 'upsun-mu-plugin' ) ),
			'fail' => array( '#d63638', __( 'failure', 'upsun-mu-plugin' ) ),
		);

		list( $color, $label ) = $map[ $status ] ?? $map['warn'];

		return sprintf(
			'<span style="color: %s; font-weight: 600;">&#9679; %s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	private function state_label( string $state, string $id = '' ): string {
		switch ( $state ) {
			case 'loaded':
				return __( 'loaded', 'upsun-mu-plugin' );
			case 'constant':
				return sprintf(
					/* translators: %s: constant name. */
					__( 'disabled by the %s constant', 'upsun-mu-plugin' ),
					'' !== $id ? ModuleRegistry::disable_constant_name( $id ) : 'UPSUN_DISABLE_*'
				);
			case 'filter':
				return __( 'removed by the upsun_mu_modules filter', 'upsun-mu-plugin' );
			case 'declined':
				return __( 'inactive (should_load() returned false)', 'upsun-mu-plugin' );
			case 'missing':
				return __( 'class not found', 'upsun-mu-plugin' );
			default:
				return $state;
		}
	}
}
