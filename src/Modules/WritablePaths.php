<?php
/**
 * Advises on the writable-path requirements of known plugins.
 *
 * On Upsun the code tree is read-only and writable directories are declared
 * as mounts in .upsun/config.yaml — unlike platforms with a fixed set of
 * writable paths, the correct fix for a plugin that writes into wp-content
 * is a three-line mount, not a runtime path redirection. What's missing is
 * discovery: plugins fail or nag before anyone knows which directory they
 * wanted. This module closes that gap: Integrations declare where known
 * plugins write (upsun_writable_path_requirements), the shared check
 * compares that against the mounts in PLATFORM_APPLICATION, and
 * doctor/Site Health/`wp upsun mounts` print the exact YAML to add.
 *
 * Deliberately advisory-only ("honest about the platform"): runtime path
 * redirection would paper over what config should express. True residual
 * gaps — wp-content-root drop-ins that cannot be mounted — are surfaced as
 * notes, to be handled at build time.
 */

namespace Upsun\Modules;

use Upsun\Environment;
use Upsun\Module;

class WritablePaths implements Module {

	public function should_load(): bool {
		/**
		 * Filters whether the writable-path advisor is active.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'upsun_writable_paths_enabled', true );
	}

	public function register(): void {
		add_filter( 'upsun_site_health_tests', array( $this, 'add_check' ) );
	}

	/**
	 * The requirements registry: id => [ label, active, paths, note ].
	 * Paths are relative to wp-content; active is a callable evaluated at
	 * check time (plugins loaded). Built-in entries come from the
	 * Integrations classes; consumers add their own plugins here.
	 *
	 * @return array<string, array{label: string, active: callable, paths: string[], note?: string}>
	 */
	public static function requirements(): array {
		/**
		 * Filters the writable-path requirements registry.
		 *
		 * @param array $requirements id => [ 'label', 'active', 'paths', 'note' ].
		 */
		return (array) apply_filters( 'upsun_writable_path_requirements', array() );
	}

	public function add_check( array $checks ): array {
		$checks['writable_paths'] = array(
			'label'    => __( 'Plugin writable paths', 'upsun-mu-plugin' ),
			'callback' => array( self::class, 'check' ),
		);

		return $checks;
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public static function check(): array {
		$missing   = array();
		$notes     = array();
		$evaluated = 0;

		foreach ( self::requirements() as $id => $requirement ) {
			if ( ! self::requirement_active( $requirement ) ) {
				continue;
			}

			$evaluated++;

			foreach ( self::requirement_paths( $requirement ) as $path ) {
				if ( ! self::is_path_writable( $path ) ) {
					$missing[] = sprintf( '%s: wp-content/%s', (string) ( $requirement['label'] ?? $id ), $path );
				}
			}

			if ( ! empty( $requirement['note'] ) ) {
				$notes[] = (string) $requirement['note'];
			}
		}

		$notes_suffix = array() !== $notes ? ' ' . implode( ' ', $notes ) : '';

		if ( array() !== $missing ) {
			return array(
				'status'  => 'warn',
				'message' => sprintf(
					/* translators: %s: list of plugin: path pairs. */
					__( 'Active plugins write to directories that are not mounts: %s. Run "wp upsun mounts" for the exact YAML to add to your Upsun configuration.', 'upsun-mu-plugin' ),
					implode( '; ', $missing )
				) . $notes_suffix,
			);
		}

		if ( 0 === $evaluated ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'No known plugins with writable-path requirements are active.', 'upsun-mu-plugin' ),
			);
		}

		return array(
			'status'  => 'pass',
			'message' => sprintf(
				/* translators: %d: number of plugins checked. */
				__( 'All writable paths required by known active plugins (%d) are covered by mounts.', 'upsun-mu-plugin' ),
				$evaluated
			) . $notes_suffix,
		);
	}

	/**
	 * Whether a wp-content-relative path is writable: covered by a declared
	 * mount (or a subdirectory of one), or — fallback for layouts the mount
	 * comparison cannot resolve — an existing writable directory.
	 */
	public static function is_path_writable( string $relative ): bool {
		$absolute = self::content_path( $relative );

		foreach ( array_keys( Environment::mounts() ) as $mount ) {
			$mount_path = self::mount_path( (string) $mount );

			if ( null !== $mount_path
				&& ( $absolute === $mount_path || 0 === strpos( $absolute, $mount_path . '/' ) ) ) {
				return true;
			}
		}

		return is_dir( $absolute ) && wp_is_writable( $absolute );
	}

	/**
	 * Missing writable paths of active known plugins, as mount suggestions.
	 *
	 * @return array<int, array{label: string, path: string, mount: ?string, source_path: string}>
	 */
	public static function suggestions(): array {
		$suggestions = array();

		foreach ( self::requirements() as $id => $requirement ) {
			if ( ! self::requirement_active( $requirement ) ) {
				continue;
			}

			foreach ( self::requirement_paths( $requirement ) as $path ) {
				if ( self::is_path_writable( $path ) ) {
					continue;
				}

				$suggestions[] = array(
					'label'       => (string) ( $requirement['label'] ?? $id ),
					'path'        => 'wp-content/' . $path,
					'mount'       => self::app_relative_content_path( $path ),
					'source_path' => str_replace( '/', '-', $path ),
				);
			}
		}

		return $suggestions;
	}

	/**
	 * Render suggestions as the mounts YAML to paste into the Upsun config.
	 */
	public static function suggestions_yaml( array $suggestions ): string {
		$lines = array( 'mounts:' );

		foreach ( $suggestions as $suggestion ) {
			if ( empty( $suggestion['mount'] ) ) {
				$lines[] = sprintf( '  # %s needs a mount for %s (path could not be resolved relative to the app dir)', $suggestion['label'], $suggestion['path'] );
				continue;
			}

			$lines[] = sprintf( '  # %s', $suggestion['label'] );
			$lines[] = sprintf( '  "%s":', $suggestion['mount'] );
			$lines[] = '    source: storage';
			$lines[] = sprintf( '    source_path: "%s"', $suggestion['source_path'] );
		}

		return implode( "\n", $lines );
	}

	private static function requirement_active( $requirement ): bool {
		return is_array( $requirement )
			&& is_callable( $requirement['active'] ?? null )
			&& (bool) call_user_func( $requirement['active'] );
	}

	/**
	 * @return string[] Normalized wp-content-relative paths.
	 */
	private static function requirement_paths( array $requirement ): array {
		$paths = array();

		foreach ( (array) ( $requirement['paths'] ?? array() ) as $path ) {
			$path = trim( (string) $path, '/' );

			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	private static function content_path( string $relative ): string {
		return rtrim( WP_CONTENT_DIR, '/' ) . '/' . trim( $relative, '/' );
	}

	/**
	 * A mount key made absolute against the app dir, or null off-platform.
	 */
	private static function mount_path( string $mount ): ?string {
		$app_dir = Environment::app_dir();

		if ( null === $app_dir || '' === $app_dir ) {
			return null;
		}

		return rtrim( $app_dir, '/' ) . '/' . trim( $mount, '/' );
	}

	/**
	 * The app-relative mount key for a wp-content-relative path (what goes
	 * in the YAML), or null when wp-content is not under the app dir.
	 */
	private static function app_relative_content_path( string $relative ): ?string {
		$app_dir = Environment::app_dir();

		if ( null === $app_dir || '' === $app_dir ) {
			return null;
		}

		$app_dir = rtrim( $app_dir, '/' );
		$content = rtrim( WP_CONTENT_DIR, '/' );

		if ( $content !== $app_dir && 0 !== strpos( $content, $app_dir . '/' ) ) {
			return null;
		}

		$content_relative = ltrim( substr( $content, strlen( $app_dir ) ), '/' );

		return ( '' === $content_relative ? '' : $content_relative . '/' ) . trim( $relative, '/' );
	}
}
