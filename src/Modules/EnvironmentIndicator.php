<?php
/**
 * Admin-bar badge and dashboard widget showing which Upsun environment
 * you are on — the main safeguard against editing a preview clone thinking
 * it is production (or vice versa).
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class EnvironmentIndicator implements Module {

	public function should_load(): bool {
		/**
		 * Filters whether the environment indicator is shown.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_environment_indicator_enabled', true );
	}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_badge' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
	}

	public function add_admin_bar_badge( \WP_Admin_Bar $admin_bar ): void {
		$type   = Environment::type() ?? 'unknown';
		$branch = Environment::branch() ?? Environment::name() ?? '?';

		$admin_bar->add_node(
			array(
				'id'    => 'upsun-environment',
				'title' => sprintf(
					'<span class="upsun-env-badge upsun-env-%s">Upsun: %s &middot; %s</span>',
					esc_attr( $type ),
					esc_html( $branch ),
					esc_html( $type )
				),
				'href'  => $this->badge_url(),
				'meta'  => array( 'title' => __( 'Upsun environment', 'upsun-mu-plugin' ) ),
			)
		);

		$hrefs = array(
			'console'   => $this->console_url(),
			'dashboard' => $this->dashboard_url(),
		);

		foreach ( $this->environment_details() as $id => $label ) {
			$admin_bar->add_node(
				array(
					'parent' => 'upsun-environment',
					'id'     => 'upsun-environment-' . $id,
					'title'  => esc_html( $label ),
					'href'   => $hrefs[ $id ] ?? false,
				)
			);
		}
	}

	public function enqueue_styles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css = file_get_contents( UPSUN_MU_PLUGIN_DIR . '/assets/environment-indicator.css' );

		if ( false !== $css && '' !== $css ) {
			// Inline on the admin-bar handle: mu-plugin subdirectories have
			// no reliable public URL to enqueue the file from.
			wp_add_inline_style( 'admin-bar', $css );
		}
	}

	public function register_dashboard_widget(): void {
		wp_add_dashboard_widget(
			'upsun_environment',
			__( 'Upsun Environment', 'upsun-mu-plugin' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget(): void {
		$rows = array(
			__( 'Environment', 'upsun-mu-plugin' )   => Environment::name(),
			__( 'Type', 'upsun-mu-plugin' )          => Environment::type(),
			__( 'Branch', 'upsun-mu-plugin' )        => Environment::branch(),
			__( 'Application', 'upsun-mu-plugin' )   => Environment::application_name(),
			__( 'Project', 'upsun-mu-plugin' )       => Environment::project(),
			__( 'Primary route', 'upsun-mu-plugin' ) => Environment::primary_route(),
			__( 'Plugin version', 'upsun-mu-plugin' ) => \Upsun\version(),
			__( 'PHP', 'upsun-mu-plugin' )           => PHP_VERSION,
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td>%s</td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $value ?? '—' )
			);
		}

		echo '</tbody></table>';

		$links = array();

		if ( null !== $this->dashboard_url() ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->dashboard_url() ),
				esc_html__( 'Open Upsun dashboard', 'upsun-mu-plugin' )
			);
		}

		if ( null !== $this->console_url() ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $this->console_url() ),
				esc_html__( 'Open in Upsun Console', 'upsun-mu-plugin' )
			);
		}

		if ( array() !== $links ) {
			echo '<p>' . implode( ' &middot; ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- links escaped above.
		}
	}

	private function console_url(): ?string {
		$project = Environment::project();

		return null === $project ? null : 'https://console.upsun.com/projects/' . rawurlencode( $project );
	}

	/**
	 * The badge links to the Upsun dashboard page when it is available to
	 * this user, falling back to the Console.
	 */
	private function badge_url(): ?string {
		return null !== $this->dashboard_url() ? $this->dashboard_url() : $this->console_url();
	}

	private function dashboard_url(): ?string {
		$dashboard_loaded = 'loaded' === ( \Upsun\ModuleRegistry::status()['dashboard']['state'] ?? '' );

		if ( ! $dashboard_loaded || ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		return admin_url( 'admin.php?page=' . Dashboard::MENU_SLUG );
	}

	/**
	 * @return array<string, string> Submenu id => label.
	 */
	private function environment_details(): array {
		$details = array();

		if ( null !== Environment::name() ) {
			$details['name'] = sprintf( __( 'Environment: %s', 'upsun-mu-plugin' ), Environment::name() );
		}

		if ( null !== Environment::project() ) {
			$details['project'] = sprintf( __( 'Project: %s', 'upsun-mu-plugin' ), Environment::project() );
		}

		if ( null !== Environment::application_name() ) {
			$details['app'] = sprintf( __( 'Application: %s', 'upsun-mu-plugin' ), Environment::application_name() );
		}

		if ( null !== $this->dashboard_url() ) {
			$details['dashboard'] = __( 'Open Upsun dashboard', 'upsun-mu-plugin' );
		}

		if ( null !== $this->console_url() ) {
			$details['console'] = __( 'Open in Upsun Console', 'upsun-mu-plugin' );
		}

		return $details;
	}
}
