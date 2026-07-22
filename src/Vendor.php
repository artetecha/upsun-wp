<?php
/**
 * Premium plugin/theme vendoring toolkit.
 *
 * Read-only filesystems plus DISALLOW_FILE_MODS mean premium plugins and
 * themes cannot self-update, so every WP-on-Upsun project vendors them as
 * Composer path packages. This engine automates the two mechanical parts of
 * that workflow:
 *
 *  - export(): turn an installed plugin/theme into a Composer-ready package
 *    (a composer.json generated from its header, source copied to a target),
 *    the step everyone does by hand when first onboarding a premium plugin;
 *  - available_updates()/check(): read the update_plugins/update_themes
 *    transients and surface packages whose own updaters advertise a newer
 *    version, flagging the premium/external ones (which Composer will not
 *    catch) through the shared check registry.
 *
 * The pure pieces (build_composer_json, classify_updates, copy_tree) carry
 * the logic and the tests; export() and available_updates() are thin glue
 * over WordPress' own plugin/theme APIs. Unlike the environment-introspection
 * commands, vendoring is useful off-platform too (you vendor a plugin from a
 * local checkout), so nothing here gates on Environment::is_upsun().
 */

namespace Upsun;

final class Vendor {

	/**
	 * Build a Composer package manifest from a normalized plugin/theme
	 * header. Pure: no WordPress, no filesystem.
	 *
	 * @param array{name?:string,uri?:string,version?:string,description?:string,author?:string,author_uri?:string,license?:string} $header
	 * @param string $type   'plugin' | 'theme' (anything else treated as plugin).
	 * @param string $slug   Package short-name (the install directory slug).
	 * @param string $vendor Composer vendor namespace. Default 'private'.
	 * @param string $license SPDX id override; empty means header-or-proprietary.
	 * @return array The composer.json structure.
	 */
	public static function build_composer_json( array $header, string $type, string $slug, string $vendor = 'private', string $license = '' ): array {
		$type   = ( 'theme' === $type ) ? 'theme' : 'plugin';
		$vendor = self::clean( '' !== $vendor ? $vendor : 'private' );
		$slug   = self::clean( $slug );

		$composer = array(
			'name' => strtolower( $vendor . '/' . $slug ),
		);

		$description = self::clean( $header['description'] ?? '' );
		if ( '' !== $description ) {
			$composer['description'] = $description;
		}

		$composer['type'] = 'wordpress-' . $type;

		$license = self::clean( $license );
		$composer['license'] = '' !== $license
			? $license
			: ( self::clean( $header['license'] ?? '' ) ?: 'proprietary' );

		$version = self::clean( $header['version'] ?? '' );
		if ( '' !== $version ) {
			$composer['version'] = $version;
		}

		$uri = self::clean( $header['uri'] ?? '' );
		if ( '' !== $uri ) {
			$composer['homepage'] = $uri;
		}

		$author = self::clean( $header['author'] ?? '' );
		if ( '' !== $author ) {
			$entry     = array( 'name' => $author );
			$author_uri = self::clean( $header['author_uri'] ?? '' );
			if ( '' !== $author_uri ) {
				$entry['homepage'] = $author_uri;
			}
			$composer['authors'] = array( $entry );
		}

		// The installer that routes wordpress-plugin/theme types into place.
		$composer['require']  = array( 'composer/installers' => '^1.0 || ^2.0' );
		$composer['keywords'] = array( 'wordpress', 'wordpress-' . $type, $slug );

		return $composer;
	}

	/**
	 * Classify the pending updates in the update transients as wordpress.org
	 * (Composer/wpackagist handles these via version bumps) or external
	 * (premium/vendored — Composer will not catch them). Pure over the
	 * transient objects.
	 *
	 * @param object|false $plugins update_plugins transient.
	 * @param object|false $themes  update_themes transient.
	 * @return array<int, array{slug:string,type:string,new:string,source:string,package:string}>
	 */
	public static function classify_updates( $plugins, $themes ): array {
		$rows = array();

		$plugin_response = is_object( $plugins ) && isset( $plugins->response ) && is_array( $plugins->response )
			? $plugins->response
			: array();

		foreach ( $plugin_response as $file => $entry ) {
			$slug = self::entry_value( $entry, 'slug' );
			if ( '' === $slug ) {
				$slug = dirname( (string) $file );
				$slug = ( '.' === $slug || '' === $slug ) ? basename( (string) $file, '.php' ) : $slug;
			}

			$rows[] = array(
				'slug'    => $slug,
				'type'    => 'plugin',
				'new'     => self::entry_value( $entry, 'new_version' ),
				'source'  => self::update_source( $entry ),
				'package' => self::entry_value( $entry, 'package' ),
			);
		}

		$theme_response = is_object( $themes ) && isset( $themes->response ) && is_array( $themes->response )
			? $themes->response
			: array();

		foreach ( $theme_response as $slug => $entry ) {
			$rows[] = array(
				'slug'    => (string) $slug,
				'type'    => 'theme',
				'new'     => self::entry_value( $entry, 'new_version' ),
				'source'  => self::update_source( $entry ),
				'package' => self::entry_value( $entry, 'package' ),
			);
		}

		return $rows;
	}

	/**
	 * Recursively copy a file or directory tree, returning the number of
	 * files written. Pure filesystem; used by export().
	 */
	public static function copy_tree( string $src, string $dest ): int {
		if ( is_file( $src ) ) {
			$dir = dirname( $dest );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			return copy( $src, $dest ) ? 1 : 0;
		}

		if ( ! is_dir( $src ) ) {
			return 0;
		}

		if ( ! is_dir( $dest ) ) {
			mkdir( $dest, 0755, true );
		}

		$count    = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$target = $dest . '/' . $iterator->getSubPathName();

			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					mkdir( $target, 0755, true );
				}
			} elseif ( copy( $item->getPathname(), $target ) ) {
				$count++;
			}
		}

		return $count;
	}

	/* ---------------------------------------------------------------------
	 * WordPress glue (exercised by the CLI, not the unit tests).
	 * ------------------------------------------------------------------- */

	/**
	 * Export an installed plugin/theme as a Composer package under
	 * <to>/<slug>/. Throws on any problem so the CLI can surface it.
	 *
	 * @param array{type?:string,to?:string,vendor?:string,license?:string} $opts
	 * @return array{name:string,version:string,path:string,files:int,type:string}
	 */
	public static function export( string $slug, array $opts = array() ): array {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			throw new \RuntimeException( 'No plugin or theme slug given.' );
		}

		$type = in_array( $opts['type'] ?? '', array( 'plugin', 'theme' ), true )
			? $opts['type']
			: self::detect_type( $slug );

		if ( null === $type ) {
			throw new \RuntimeException( sprintf( "No installed plugin or theme found for slug '%s'.", $slug ) );
		}

		list( $source, $header ) = self::locate( $slug, $type );

		$composer = self::build_composer_json(
			$header,
			$type,
			$slug,
			(string) ( $opts['vendor'] ?? 'private' ),
			(string) ( $opts['license'] ?? '' )
		);

		$dest = rtrim( '' !== ( $opts['to'] ?? '' ) ? $opts['to'] : '.', '/' ) . '/' . $slug;

		if ( file_exists( $dest ) ) {
			throw new \RuntimeException( sprintf( 'Target already exists: %s (remove it, or pick another --to).', $dest ) );
		}

		$files = self::copy_tree( is_dir( $source ) ? $source : $source, $dest );

		file_put_contents(
			$dest . '/composer.json',
			wp_json_encode( $composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);

		return array(
			'name'    => $composer['name'],
			'version' => $composer['version'] ?? '',
			'path'    => $dest,
			'files'   => $files,
			'type'    => $type,
		);
	}

	/**
	 * Pending updates from the live transients, enriched with the installed
	 * version where available.
	 *
	 * @return array<int, array{slug:string,type:string,current:string,new:string,source:string,package:string}>
	 */
	public static function available_updates(): array {
		$rows = self::classify_updates(
			get_site_transient( 'update_plugins' ),
			get_site_transient( 'update_themes' )
		);

		foreach ( $rows as &$row ) {
			$row['current'] = self::installed_version( $row['slug'], $row['type'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Shared health check: warn when premium/external packages have a
	 * pending update (they will not flow through Composer automatically).
	 * wordpress.org updates are intentionally not flagged here.
	 *
	 * @return array{status:string, message:string}
	 */
	public static function check(): array {
		$external = array_values( array_filter(
			self::available_updates(),
			static function ( array $row ) {
				return 'external' === $row['source'] && '' !== $row['new'];
			}
		) );

		if ( array() === $external ) {
			return array(
				'status'  => 'pass',
				'message' => __( 'No premium/vendored plugin or theme updates are pending.', 'upsun-mu-plugin' ),
			);
		}

		$parts = array();
		foreach ( $external as $row ) {
			$parts[] = sprintf( '%s (%s → %s)', $row['slug'], $row['current'] ?: '?', $row['new'] );
		}

		return array(
			'status'  => 'warn',
			'message' => sprintf(
				/* translators: 1: count, 2: list of slug (current -> new). */
				__( '%1$d premium/vendored update(s) pending — Composer will not catch these; re-vendor with "wp upsun vendor <slug>": %2$s', 'upsun-mu-plugin' ),
				count( $external ),
				implode( ', ', $parts )
			),
		);
	}

	/**
	 * Whether the slug resolves to an installed plugin or theme.
	 */
	public static function detect_type( string $slug ): ?string {
		if ( defined( 'WP_PLUGIN_DIR' ) && ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) || is_file( WP_PLUGIN_DIR . '/' . $slug . '.php' ) ) ) {
			return 'plugin';
		}

		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				return 'theme';
			}
		}

		return null;
	}

	/**
	 * Resolve a slug to [ source path, normalized header ].
	 *
	 * @return array{0:string,1:array}
	 */
	private static function locate( string $slug, string $type ): array {
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				throw new \RuntimeException( sprintf( "Theme '%s' is not installed.", $slug ) );
			}

			return array(
				$theme->get_stylesheet_directory(),
				array(
					'name'        => (string) $theme->get( 'Name' ),
					'uri'         => (string) $theme->get( 'ThemeURI' ),
					'version'     => (string) $theme->get( 'Version' ),
					'description' => (string) $theme->get( 'Description' ),
					'author'      => (string) $theme->get( 'Author' ),
					'author_uri'  => (string) $theme->get( 'AuthorURI' ),
					'license'     => (string) $theme->get( 'License' ),
				),
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$dir = WP_PLUGIN_DIR . '/' . $slug;

		if ( is_dir( $dir ) ) {
			$plugins = get_plugins( '/' . $slug );
			if ( array() === $plugins ) {
				throw new \RuntimeException( sprintf( "No plugin header found in '%s'.", $dir ) );
			}
			$data = reset( $plugins );

			return array( $dir, self::normalize_plugin_header( $data ) );
		}

		$file = WP_PLUGIN_DIR . '/' . $slug . '.php';
		if ( is_file( $file ) ) {
			$data = get_plugin_data( $file, false, false );

			return array( $file, self::normalize_plugin_header( $data ) );
		}

		throw new \RuntimeException( sprintf( "Plugin '%s' is not installed.", $slug ) );
	}

	/**
	 * @param array<string,mixed> $data get_plugin_data()/get_plugins() row.
	 * @return array<string,string>
	 */
	private static function normalize_plugin_header( array $data ): array {
		return array(
			'name'        => (string) ( $data['Name'] ?? '' ),
			'uri'         => (string) ( $data['PluginURI'] ?? '' ),
			'version'     => (string) ( $data['Version'] ?? '' ),
			'description' => (string) ( $data['Description'] ?? '' ),
			'author'      => (string) ( $data['AuthorName'] ?? ( $data['Author'] ?? '' ) ),
			'author_uri'  => (string) ( $data['AuthorURI'] ?? '' ),
			'license'     => '',
		);
	}

	private static function installed_version( string $slug, string $type ): string {
		if ( 'theme' === $type && function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $slug );
			return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
		}

		if ( 'plugin' === $type && function_exists( 'get_plugins' ) && defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
			$plugins = get_plugins( '/' . $slug );
			$data    = is_array( $plugins ) ? reset( $plugins ) : false;
			return is_array( $data ) ? (string) ( $data['Version'] ?? '' ) : '';
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------- */

	private static function update_source( $entry ): string {
		$package = self::entry_value( $entry, 'package' );
		$id      = self::entry_value( $entry, 'id' );

		if ( false !== stripos( $package, '//downloads.wordpress.org' ) ) {
			return 'wporg';
		}

		if ( 0 === strpos( $id, 'w.org/' ) ) {
			return 'wporg';
		}

		return 'external';
	}

	/**
	 * Read a field from an update entry, which is an object for plugins and
	 * an array for themes.
	 */
	private static function entry_value( $entry, string $key ): string {
		if ( is_object( $entry ) ) {
			return isset( $entry->$key ) ? (string) $entry->$key : '';
		}

		if ( is_array( $entry ) ) {
			return isset( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
		}

		return '';
	}

	private static function clean( $value ): string {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) );
	}
}
