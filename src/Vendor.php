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
			self::ensure_dir( dirname( $dest ) );
			if ( ! @copy( $src, $dest ) ) {
				throw new \RuntimeException( sprintf( 'Failed to copy %s to %s.', $src, $dest ) );
			}
			return 1;
		}

		if ( ! is_dir( $src ) ) {
			return 0;
		}

		self::ensure_dir( $dest );

		$count    = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$target = $dest . '/' . $iterator->getSubPathName();

			if ( $item->isDir() ) {
				self::ensure_dir( $target );
			} else {
				self::ensure_dir( dirname( $target ) );
				// A swallowed copy failure would report a partial tree as
				// success; fail loudly so callers can roll back instead.
				if ( ! @copy( $item->getPathname(), $target ) ) {
					throw new \RuntimeException( sprintf( 'Failed to copy %s.', $item->getPathname() ) );
				}
				$count++;
			}
		}

		return $count;
	}

	private static function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( sprintf( 'Failed to create directory %s.', $dir ) );
		}
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

	/* ---------------------------------------------------------------------
	 * Update fetching (v0.5). The engine: dispatch to a Fetcher for
	 * discovery, then download / extract / re-vendor. Fetchers supply only
	 * the authenticated download; everything else is here and generic.
	 * ------------------------------------------------------------------- */

	/**
	 * Registered fetchers, most specific first, with the built-in
	 * TransientFetcher appended as the universal fallback.
	 *
	 * @return Fetcher[]
	 */
	public static function fetchers(): array {
		/**
		 * Filters the vendored-update fetcher list. Register vendor-specific
		 * fetchers (e.g. a ThimPress fetcher gated on thim-core); the first
		 * whose supports() matches wins. The built-in transient fetcher is
		 * always tried last.
		 *
		 * @param Fetcher[] $fetchers
		 */
		$fetchers = (array) apply_filters( 'upsun_vendor_fetchers', array() );

		$fetchers   = array_values( array_filter( $fetchers, static fn ( $f ) => $f instanceof Fetcher ) );
		$fetchers[] = new Fetchers\TransientFetcher();

		return $fetchers;
	}

	/**
	 * The first fetcher that supports the package.
	 */
	public static function pick_fetcher( string $slug, string $type ): ?Fetcher {
		foreach ( self::fetchers() as $fetcher ) {
			if ( $fetcher->supports( $slug, $type ) ) {
				return $fetcher;
			}
		}

		return null;
	}

	/**
	 * Re-vendor manifest: merge our pin fields over the upstream
	 * composer.json (from the new version's source), stripping keys that
	 * would leak into the consumer's dependency resolution or autoloading.
	 * Everything else upstream ships — notably `extra` — is preserved,
	 * because some plugins read it at runtime (e.g. fluentcampaign-pro's
	 * extra.wpfluent.namespace). Pure.
	 *
	 * @param array<string,mixed> $upstream Decoded upstream composer.json (or empty).
	 * @return array<string,mixed>
	 */
	public static function merge_composer_json( array $upstream, string $name, string $type, string $version ): array {
		$strip = array(
			'require',
			'require-dev',
			'repositories',
			'scripts',
			'config',
			'autoload',
			'autoload-dev',
			'minimum-stability',
			'prefer-stable',
			'provide',
			'replace',
			'conflict',
			'suggest',
			'bin',
		);

		foreach ( $strip as $key ) {
			unset( $upstream[ $key ] );
		}

		$upstream['name'] = strtolower( $name );
		$upstream['type'] = 'wordpress-' . ( 'theme' === $type ? 'theme' : 'plugin' );

		if ( '' !== $version ) {
			$upstream['version'] = $version;
		}

		return $upstream;
	}

	/**
	 * Discovery only (no download): what a re-vendor of this slug would do.
	 * Returns null when there is no newer version to fetch.
	 *
	 * @return array{slug:string,type:string,from:string,to:string,url:string,headers:array,fetcher:string}|null
	 */
	public static function resolve_update( string $slug, ?string $type = null ): ?array {
		$type = in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : self::detect_type( $slug );

		if ( null === $type ) {
			return null;
		}

		$fetcher = self::pick_fetcher( $slug, $type );

		if ( null === $fetcher ) {
			return null;
		}

		$update = $fetcher->available_update( $slug, $type );

		if ( ! is_array( $update ) || empty( $update['url'] ) || empty( $update['version'] ) ) {
			return null;
		}

		$current = self::installed_version( $slug, $type );

		// Only act on a strictly newer version (the transient only lists
		// pending updates, but a custom fetcher might not).
		if ( '' !== $current && version_compare( $update['version'], $current, '<=' ) ) {
			return null;
		}

		return array(
			'slug'    => $slug,
			'type'    => $type,
			'from'    => $current,
			'to'      => (string) $update['version'],
			'url'     => (string) $update['url'],
			'headers' => (array) ( $update['headers'] ?? array() ),
			'fetcher' => $fetcher->id(),
		);
	}

	/**
	 * Fetch and re-vendor a pending update into <to>/<slug>/. Throws on any
	 * failure. Returns null when there is nothing newer to fetch.
	 *
	 * @param array{type?:string,to?:string,vendor?:string} $opts
	 * @return array{slug:string,type:string,from:string,to:string,path:string,files:int,fetcher:string}|null
	 */
	public static function update( string $slug, array $opts = array(), ?array $plan = null ): ?array {
		// Accept an already-resolved plan (the CLI computes one for its
		// dry-run/reporting) so discovery isn't run twice and there is no
		// resolve-then-re-resolve window.
		if ( null === $plan ) {
			$plan = self::resolve_update( $slug, ( $opts['type'] ?? '' ) ?: null );
		}

		if ( null === $plan ) {
			return null;
		}

		$dest   = rtrim( '' !== ( $opts['to'] ?? '' ) ? $opts['to'] : '.', '/' ) . '/' . $slug;
		$parent = dirname( $dest );

		// Preserve the existing vendored package's Composer name (its vendor
		// namespace) across the update; fall back to <vendor>/<slug>.
		$name = '';
		if ( is_file( $dest . '/composer.json' ) ) {
			$existing = json_decode( (string) file_get_contents( $dest . '/composer.json' ), true );
			$name     = is_array( $existing ) ? (string) ( $existing['name'] ?? '' ) : '';
		}
		if ( '' === $name ) {
			$name = strtolower( ( ( $opts['vendor'] ?? '' ) ?: 'private' ) . '/' . $slug );
		}

		$work    = self::temp_dir();
		self::ensure_dir( $parent );
		$staging = $parent . '/.upsun-vendor-' . uniqid( '', true );
		$backup  = null;

		try {
			$zip = $work . '/package.zip';

			if ( ! self::fetch_zip( $plan['url'], $plan['headers'], $zip ) ) {
				throw new \RuntimeException( sprintf( 'Download failed for %s (%s) — is the URL https and licensed?', $slug, $plan['fetcher'] ) );
			}

			$root = self::extract_zip( $zip, $work . '/src' );

			if ( null === $root ) {
				throw new \RuntimeException( sprintf( 'Could not extract the %s package (empty, unreadable, or unsafe archive).', $slug ) );
			}

			$upstream = array();
			if ( is_file( $root . '/composer.json' ) ) {
				$decoded  = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
				$upstream = is_array( $decoded ) ? $decoded : array();
			}

			$composer = self::merge_composer_json( $upstream, $name, $plan['type'], $plan['to'] );

			// Build the complete package in a staging sibling first; the
			// destination is only ever touched by atomic renames, so a
			// failure here leaves the existing package fully intact.
			$files = self::copy_tree( $root, $staging );

			$json = wp_json_encode( $composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json || false === file_put_contents( $staging . '/composer.json', $json . "\n" ) ) {
				throw new \RuntimeException( sprintf( 'Failed to write composer.json for %s.', $slug ) );
			}

			// Swap: set the old package aside, move the staged one in, then
			// drop the backup. On a failed swap, restore the backup.
			if ( is_dir( $dest ) ) {
				$backup = $parent . '/.upsun-vendor-old-' . uniqid( '', true );
				if ( ! @rename( $dest, $backup ) ) {
					throw new \RuntimeException( sprintf( 'Failed to set aside the existing package at %s.', $dest ) );
				}
			}

			if ( ! @rename( $staging, $dest ) ) {
				if ( null !== $backup ) {
					@rename( $backup, $dest ); // Restore; leave the old package in place.
				}
				throw new \RuntimeException( sprintf( 'Failed to place the vendored package at %s.', $dest ) );
			}
		} finally {
			self::remove_tree( $work );
			self::remove_tree( $staging ); // No-op once renamed into place.
			if ( null !== $backup ) {
				self::remove_tree( $backup ); // No-op if restored or already dropped.
			}
		}

		return array(
			'slug'    => $slug,
			'type'    => $plan['type'],
			'from'    => $plan['from'],
			'to'      => $plan['to'],
			'path'    => $dest,
			'files'   => $files,
			'fetcher' => $plan['fetcher'],
		);
	}

	/**
	 * Every installed plugin/theme with a pending update a fetcher can
	 * resolve — the discovery pass behind --update-all and --check-updates
	 * for premium packages.
	 *
	 * @return array<int, array{slug:string,type:string,from:string,to:string,url:string,headers:array,fetcher:string}>
	 */
	public static function resolvable_updates(): array {
		$plans = array();

		foreach ( self::available_updates() as $row ) {
			// Only premium/external packages are vendored. wordpress.org
			// updates flow through Composer/wpackagist and must never be
			// re-vendored (the transient fetcher would happily resolve them).
			if ( 'external' !== $row['source'] ) {
				continue;
			}

			$plan = self::resolve_update( $row['slug'], $row['type'] );

			if ( null !== $plan ) {
				$plans[] = $plan;
			}
		}

		return $plans;
	}

	/**
	 * Download a URL (or copy a local/file:// path, for artifacts and tests)
	 * to a destination file.
	 */
	public static function fetch_zip( string $url, array $headers, string $dest ): bool {
		self::ensure_dir( dirname( $dest ) );

		// The download becomes committed, later-executed code and no
		// checksum/signature is available from these sources, so an http (or
		// on-path-downgraded) URL is an injection sink: require https for
		// remote fetches. file:// and local paths are for artifacts/tests.
		if ( preg_match( '#^http://#i', $url ) ) {
			return false;
		}

		if ( preg_match( '#^https://#i', $url ) ) {
			if ( ! function_exists( 'wp_remote_get' ) ) {
				return false;
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'  => 120,
					'headers'  => $headers,
					'stream'   => true,
					'filename' => $dest,
				)
			);

			return ! is_wp_error( $response )
				&& (int) wp_remote_retrieve_response_code( $response ) < 400
				&& is_file( $dest );
		}

		$path = preg_replace( '#^file://#', '', $url );

		return is_file( $path ) && @copy( $path, $dest );
	}

	/**
	 * Extract a zip and return the directory to vendor from — the single
	 * top-level directory the archive wraps its files in, if any, else the
	 * extraction root. Null on failure.
	 */
	public static function extract_zip( string $zip, string $dest ): ?string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return null;
		}

		$archive = new \ZipArchive();

		if ( true !== $archive->open( $zip ) ) {
			return null;
		}

		// Zip-slip guard: refuse archives with absolute or parent-traversing
		// entries before extracting anything. libzip mostly sanitizes these,
		// but the archive is external input, so validate explicitly rather
		// than rely on version-dependent behavior.
		for ( $i = 0; $i < $archive->numFiles; $i++ ) {
			$entry = $archive->getNameIndex( $i );

			if ( false === $entry ) {
				continue;
			}

			$normalized = str_replace( '\\', '/', $entry );

			if ( '' !== $normalized
				&& ( '/' === $normalized[0]
					|| preg_match( '#^[A-Za-z]:#', $normalized )
					|| preg_match( '#(^|/)\.\.(/|$)#', $normalized ) ) ) {
				$archive->close();
				return null;
			}
		}

		self::ensure_dir( $dest );

		$archive->extractTo( $dest );
		$archive->close();

		$entries = array_values( array_diff( scandir( $dest ) ?: array(), array( '.', '..' ) ) );

		// A single wrapping directory (plugin-name/…) is the vendor root.
		if ( 1 === count( $entries ) && is_dir( $dest . '/' . $entries[0] ) ) {
			return $dest . '/' . $entries[0];
		}

		return $dest;
	}

	/**
	 * Recursively delete a path (used to replace a package on re-vendor and
	 * to clean the work directory).
	 */
	public static function remove_tree( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( array_diff( scandir( $path ) ?: array(), array( '.', '..' ) ) as $item ) {
			self::remove_tree( $path . '/' . $item );
		}

		rmdir( $path );
	}

	private static function temp_dir(): string {
		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/upsun-vendor-' . uniqid( '', true );
		mkdir( $dir, 0755, true );

		return $dir;
	}
}
