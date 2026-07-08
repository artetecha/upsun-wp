<?php
/**
 * Read-only filesystem UX: on Upsun the application tree is immutable and
 * updates flow through Composer + Git. This module disables the in-app
 * auto-update machinery cleanly and removes the core Site Health tests that
 * would otherwise fail-by-design forever.
 *
 * It does not suppress third-party "directory not writable" notices — those
 * are vendor-specific and belong in project code.
 */

namespace Upsun\Modules;

use Upsun\Module;

class UpdatesPolicy implements Module {

	public function should_load(): bool {
		return true;
	}

	public function register(): void {
		add_filter( 'automatic_updater_disabled', '__return_true', 99 );
		add_filter( 'auto_update_core', '__return_false', 99 );
		add_filter( 'auto_update_plugin', '__return_false', 99 );
		add_filter( 'auto_update_theme', '__return_false', 99 );
		// Translation updates also write to wp-content/languages.
		add_filter( 'auto_update_translation', '__return_false', 99 );

		add_filter( 'site_status_tests', array( $this, 'remove_update_tests' ), 99 );
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_setting_html' ), 99 );
		add_filter( 'theme_auto_update_setting_html', array( $this, 'auto_update_setting_html' ), 99 );
	}

	/**
	 * Remove core tests that always fail on an immutable filesystem.
	 */
	public function remove_update_tests( array $tests ): array {
		unset( $tests['async']['background_updates'], $tests['direct']['plugin_theme_auto_updates'] );

		return $tests;
	}

	/**
	 * Replace the per-plugin/theme auto-update toggles with a static note.
	 */
	public function auto_update_setting_html(): string {
		return '<em>' . esc_html( $this->notice_text() ) . '</em>';
	}

	private function notice_text(): string {
		/**
		 * Filters the "updates are managed elsewhere" message.
		 *
		 * @param string $text
		 */
		return (string) apply_filters(
			'upsun_updates_notice_text',
			__( 'Updates are managed with Composer on Upsun.', 'upsun-mu-plugin' )
		);
	}
}
