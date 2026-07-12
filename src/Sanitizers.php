<?php
/**
 * Opt-in database-writing sanitizers for preview environments.
 *
 * SafePreviews' runtime protections never touch the database; this registry
 * is for state that has to change IN the data (credentials, PII, plugin
 * activation). Every built-in ships disabled and is enabled per-slug through
 * code-based filters — no toggle UI, configuration stays versioned. Writes
 * are safe by platform design: data only flows parent→child on Upsun, so
 * scrubbed preview state can never leak back, and a resync re-triggers the
 * sanitize flow. Every sanitizer must be idempotent and dry-run aware.
 */

namespace Upsun;

final class Sanitizers {

	/** RFC 2606 reserved TLD: anonymized addresses can never deliver. */
	public const ANON_EMAIL_DOMAIN = 'upsun-preview.invalid';

	/**
	 * The sanitizer registry: id => [ label, enabled, run ].
	 *
	 * enabled() is evaluated per run; run( bool $dry_run ) performs (or
	 * previews) the change and returns a one-line report, or null when
	 * there is nothing to do.
	 *
	 * @return array<string, array{label: string, enabled: callable, run: callable}>
	 */
	public static function registry(): array {
		$sanitizers = array(
			'anonymize-user-emails'    => array(
				'label'   => __( 'Anonymize user emails', 'upsun-mu-plugin' ),
				'enabled' => static function (): bool {
					/**
					 * Filters whether user emails are anonymized on sanitize.
					 *
					 * @param bool $enabled Default false.
					 */
					return (bool) apply_filters( 'upsun_sanitize_anonymize_user_emails', false );
				},
				'run'     => array( self::class, 'anonymize_user_emails' ),
			),
			'anonymize-user-passwords' => array(
				'label'   => __( 'Anonymize user passwords', 'upsun-mu-plugin' ),
				'enabled' => static function (): bool {
					return (bool) self::password_mode();
				},
				'run'     => array( self::class, 'anonymize_user_passwords' ),
			),
			'deactivate-plugins'       => array(
				'label'   => __( 'Deactivate plugins', 'upsun-mu-plugin' ),
				'enabled' => static function (): bool {
					return array() !== self::deactivation_targets();
				},
				'run'     => array( self::class, 'deactivate_listed_plugins' ),
			),
			'scrub-options'            => array(
				'label'   => __( 'Scrub options', 'upsun-mu-plugin' ),
				'enabled' => static function (): bool {
					return array() !== self::scrub_specs();
				},
				'run'     => array( self::class, 'scrub_listed_options' ),
			),
		);

		/**
		 * Filters the sanitizer registry. Consumers add their own
		 * DB-writing sanitizers (same idempotency and dry-run contract) or
		 * remove built-ins.
		 *
		 * @param array $sanitizers id => [ 'label', 'enabled', 'run' ].
		 */
		return (array) apply_filters( 'upsun_preview_sanitizers', $sanitizers );
	}

	/** @var array<string, mixed> Per-run forced enablement: id => true|string. */
	private static array $forced = array();

	/**
	 * Force sanitizers on for the current process — the CLI --enable flag,
	 * i.e. project-level policy declared in the post_deploy hook. Nothing
	 * is persisted; values are passed to built-ins that accept one (the
	 * password template, pipe-separated plugin basenames). Pass an empty
	 * array to clear.
	 *
	 * @param array<string, mixed> $forced id => true|string.
	 */
	public static function force( array $forced ): void {
		self::$forced = $forced;
	}

	public static function is_enabled( string $id, array $sanitizer ): bool {
		if ( array_key_exists( $id, self::$forced ) ) {
			return true;
		}

		return is_callable( $sanitizer['enabled'] ?? null ) && (bool) call_user_func( $sanitizer['enabled'] );
	}

	/**
	 * Run (or dry-run) every enabled sanitizer and collect report lines.
	 * Hard-refuses on production, on top of the callers' own guards.
	 *
	 * @return string[] "Label: what happened" lines.
	 */
	public static function run( bool $dry_run ): array {
		if ( Environment::is_production() ) {
			return array();
		}

		$reports = array();

		foreach ( self::registry() as $id => $sanitizer ) {
			if ( ! self::is_enabled( (string) $id, $sanitizer ) || ! is_callable( $sanitizer['run'] ?? null ) ) {
				continue;
			}

			$report = call_user_func( $sanitizer['run'], $dry_run );

			if ( is_string( $report ) && '' !== $report ) {
				$reports[] = sprintf( '%s: %s', (string) ( $sanitizer['label'] ?? $id ), $report );
			}
		}

		return $reports;
	}

	/* ---------------------------------------------------------------------
	 * anonymize-user-emails
	 * ------------------------------------------------------------------- */

	/**
	 * Rewrite every email to user-{ID}@upsun-preview.invalid in one
	 * idempotent UPDATE (the WHERE excludes already-anonymized rows and the
	 * preserved list). Usernames keep working for login; email login for
	 * anonymized users stops — that is the point.
	 *
	 * @return string|null
	 */
	public static function anonymize_user_emails( bool $dry_run ): ?string {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$clauses = array_merge(
			array( $wpdb->prepare( 'user_email NOT LIKE %s', '%@' . self::ANON_EMAIL_DOMAIN ) ),
			self::preserved_email_clauses()
		);
		$where   = implode( ' AND ', $clauses );

		if ( $dry_run ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE {$where}" );

			return sprintf( 'would anonymize %d user email(s) to user-{ID}@%s', $count, self::ANON_EMAIL_DOMAIN );
		}

		$changed = (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$wpdb->users} SET user_email = CONCAT('user-', ID, %s) WHERE ", '@' . self::ANON_EMAIL_DOMAIN ) . $where
		);

		if ( $changed > 0 && function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush(); // User rows are object-cached.
		}

		return sprintf( 'anonymized %d user email(s)', $changed );
	}

	/* ---------------------------------------------------------------------
	 * anonymize-user-passwords
	 * ------------------------------------------------------------------- */

	/**
	 * Set every password to a known value in one idempotent UPDATE. The
	 * hashes are legacy MD5, which WordPress accepts and transparently
	 * rehashes on first login — per-user passwords cost one SQL statement
	 * instead of N bcrypt rounds, and `user_pass != MD5(...)` gives exact
	 * idempotency with no stored state. Existing auth cookies invalidate
	 * (they embed a hash fragment) — desirable on a clone. Known passwords
	 * on a reachable preview are a door: pair this with Upsun's HTTP access
	 * control.
	 *
	 * @return string|null
	 */
	public static function anonymize_user_passwords( bool $dry_run ): ?string {
		global $wpdb;

		$mode = self::password_mode();

		if ( ! $mode || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$template = is_string( $mode ) && '' !== $mode ? $mode : 'password';

		if ( false !== strpos( $template, '{ID}' ) ) {
			list( $before, $after ) = explode( '{ID}', $template, 2 );

			$plain_sql = $wpdb->prepare( 'CONCAT(%s, ID, %s)', $before, $after );
		} else {
			$plain_sql = $wpdb->prepare( '%s', $template );
		}

		$clauses = array_merge(
			array( "user_pass != MD5({$plain_sql})" ),
			self::preserved_email_clauses()
		);
		$where   = implode( ' AND ', $clauses );

		if ( $dry_run ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE {$where}" );

			return sprintf( 'would set %d password(s) to "%s"', $count, $template );
		}

		$changed = (int) $wpdb->query( "UPDATE {$wpdb->users} SET user_pass = MD5({$plain_sql}) WHERE {$where}" );

		if ( $changed > 0 && function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return sprintf( 'set %d password(s) to "%s"', $changed, $template );
	}

	/* ---------------------------------------------------------------------
	 * deactivate-plugins
	 * ------------------------------------------------------------------- */

	/**
	 * @return string|null
	 */
	public static function deactivate_listed_plugins( bool $dry_run ): ?string {
		$targets = self::deactivation_targets();

		if ( array() === $targets ) {
			return null;
		}

		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			return null;
		}

		$active = array_values( array_filter( $targets, 'is_plugin_active' ) );

		if ( array() === $active ) {
			return $dry_run ? __( 'nothing to deactivate (targets already inactive)', 'upsun-mu-plugin' ) : null;
		}

		if ( $dry_run ) {
			return 'would deactivate: ' . implode( ', ', $active );
		}

		deactivate_plugins( $active, true );

		return 'deactivated: ' . implode( ', ', $active );
	}

	/* ---------------------------------------------------------------------
	 * scrub-options
	 * ------------------------------------------------------------------- */

	/**
	 * @return string|null
	 */
	public static function scrub_listed_options( bool $dry_run ): ?string {
		$specs = self::scrub_specs();

		if ( array() === $specs ) {
			return null;
		}

		$changed = array();
		$missing = new \stdClass();

		foreach ( $specs as $key => $replacement ) {
			$key    = (string) $key;
			$path   = explode( '.', $key );
			$option = array_shift( $path );
			$value  = get_option( $option, $missing );

			if ( $value === $missing ) {
				continue; // Absent options have nothing to scrub.
			}

			if ( array() === $path ) {
				if ( null === $replacement ) {
					$changed[] = $key . ' (deleted)';

					if ( ! $dry_run ) {
						delete_option( $option );
					}
				} elseif ( $value !== $replacement ) {
					$changed[] = $key;

					if ( ! $dry_run ) {
						update_option( $option, $replacement );
					}
				}

				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$did     = false;
			$updated = self::replace_path( $value, $path, $replacement, $did );

			if ( $did ) {
				$changed[] = $key;

				if ( ! $dry_run ) {
					update_option( $option, $updated );
				}
			}
		}

		if ( array() === $changed ) {
			return $dry_run ? __( 'nothing to scrub (targets already clean or absent)', 'upsun-mu-plugin' ) : null;
		}

		return ( $dry_run ? 'would scrub: ' : 'scrubbed: ' ) . implode( ', ', $changed );
	}

	/**
	 * Set (or unset, when $replacement is null) a dotted path inside an
	 * array; missing intermediate keys mean nothing to do.
	 */
	private static function replace_path( array $data, array $path, $replacement, bool &$changed ): array {
		$changed = false;
		$cursor  =& $data;
		$last    = array_pop( $path );

		foreach ( $path as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				return $data;
			}

			$cursor =& $cursor[ $segment ];
		}

		if ( ! array_key_exists( $last, $cursor ) ) {
			return $data;
		}

		if ( null === $replacement ) {
			unset( $cursor[ $last ] );
			$changed = true;
		} elseif ( $cursor[ $last ] !== $replacement ) {
			$cursor[ $last ] = $replacement;
			$changed         = true;
		}

		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Shared configuration filters.
	 * ------------------------------------------------------------------- */

	/**
	 * The password mode: false (disabled), true ("password" for everyone),
	 * or a template string where {ID} becomes the user id.
	 *
	 * @return bool|string
	 */
	private static function password_mode() {
		if ( array_key_exists( 'anonymize-user-passwords', self::$forced ) ) {
			return self::$forced['anonymize-user-passwords']; // true or a template.
		}

		/**
		 * Filters whether (and how) user passwords are anonymized on
		 * sanitize.
		 *
		 * @param bool|string $mode Default false. true = "password" for
		 *                          everyone; a string like 'password-{ID}'
		 *                          gives per-user passwords.
		 */
		return apply_filters( 'upsun_sanitize_anonymize_passwords', false );
	}

	/**
	 * @return string[] Plugin basenames to deactivate on previews.
	 */
	private static function deactivation_targets(): array {
		$forced = self::$forced['deactivate-plugins'] ?? null;

		if ( is_string( $forced ) && '' !== $forced ) {
			// --enable value: pipe-separated basenames.
			$targets = explode( '|', $forced );
		} else {
			/**
			 * Filters the plugin basenames deactivated on sanitize.
			 *
			 * @param string[] $plugins Default empty (sanitizer disabled).
			 */
			$targets = (array) apply_filters( 'upsun_sanitize_deactivate_plugins', array() );
		}

		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $targets ) ), 'strlen' ) );
	}

	/**
	 * @return array<string, mixed> Option name (with optional dotted
	 *                              sub-key path) => replacement (null
	 *                              deletes/unsets).
	 */
	private static function scrub_specs(): array {
		/**
		 * Filters the option scrub list applied on sanitize.
		 *
		 * @param array $specs Default empty (sanitizer disabled).
		 */
		return (array) apply_filters( 'upsun_sanitize_scrub_options', array() );
	}

	/**
	 * WHERE clauses excluding preserved users, shared by both anonymizers.
	 *
	 * @return string[]
	 */
	private static function preserved_email_clauses(): array {
		global $wpdb;

		/**
		 * Filters emails exempt from BOTH anonymizers: exact addresses or
		 * '@domain' suffixes.
		 *
		 * @param string[] $preserved Default empty.
		 */
		$preserved = (array) apply_filters( 'upsun_sanitize_preserved_emails', array() );
		$clauses   = array();

		foreach ( $preserved as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			$clauses[] = '@' === $entry[0]
				? $wpdb->prepare( 'user_email NOT LIKE %s', '%' . $entry )
				: $wpdb->prepare( 'user_email != %s', $entry );
		}

		return $clauses;
	}
}
