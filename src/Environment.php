<?php
/**
 * Direct PLATFORM_* environment variable access.
 *
 * Deliberately has no dependency on platformsh/config-reader so the plugin
 * works in any consumer project. All decoding is defensive: malformed base64
 * or JSON degrades to an empty array, never a fatal.
 */

namespace Upsun;

final class Environment {

	/** @var array<string, array> */
	private static array $decoded_cache = array();

	/**
	 * Clear the decoded-variable cache (tests, long-running processes).
	 */
	public static function reset(): void {
		self::$decoded_cache = array();
	}

	/**
	 * Whether we are running on Upsun at runtime.
	 *
	 * PLATFORM_ENVIRONMENT is absent during build hooks, which never run
	 * WordPress anyway, so requiring both vars means "Upsun runtime".
	 */
	public static function is_upsun(): bool {
		return false !== getenv( 'PLATFORM_APPLICATION_NAME' )
			&& false !== getenv( 'PLATFORM_ENVIRONMENT' );
	}

	public static function name(): ?string {
		return self::env( 'PLATFORM_ENVIRONMENT' );
	}

	/**
	 * Environment type: production | staging | development.
	 */
	public static function type(): ?string {
		return self::env( 'PLATFORM_ENVIRONMENT_TYPE' );
	}

	public static function branch(): ?string {
		return self::env( 'PLATFORM_BRANCH' );
	}

	public static function project(): ?string {
		return self::env( 'PLATFORM_PROJECT' );
	}

	public static function application_name(): ?string {
		return self::env( 'PLATFORM_APPLICATION_NAME' );
	}

	/**
	 * The on-platform SMTP relay host, or null when mail is disabled
	 * (Upsun sets the variable to an empty string in that case).
	 */
	public static function smtp_host(): ?string {
		$host = self::env( 'PLATFORM_SMTP_HOST' );

		return ( null === $host || '' === $host ) ? null : $host;
	}

	public static function is_production(): bool {
		return 'production' === self::type();
	}

	/**
	 * Decoded PLATFORM_ROUTES: URL => route definition.
	 *
	 * @return array<string, array>
	 */
	public static function routes(): array {
		return self::decoded( 'PLATFORM_ROUTES' );
	}

	/**
	 * Decoded PLATFORM_APPLICATION: the app configuration as deployed.
	 *
	 * @return array
	 */
	public static function application(): array {
		return self::decoded( 'PLATFORM_APPLICATION' );
	}

	/**
	 * Absolute path of the application directory (PLATFORM_APP_DIR).
	 */
	public static function app_dir(): ?string {
		return self::env( 'PLATFORM_APP_DIR' );
	}

	/**
	 * Declared writable mounts: app-relative path => definition.
	 *
	 * @return array<string, array>
	 */
	public static function mounts(): array {
		$mounts = self::application()['mounts'] ?? array();

		return is_array( $mounts ) ? $mounts : array();
	}

	/**
	 * Decoded PLATFORM_RELATIONSHIPS: name => list of instances.
	 *
	 * @return array<string, array>
	 */
	public static function relationships(): array {
		return self::decoded( 'PLATFORM_RELATIONSHIPS' );
	}

	/**
	 * The first instance of a named relationship, or null.
	 */
	public static function relationship( string $name ): ?array {
		$relationships = self::relationships();

		if ( empty( $relationships[ $name ][0] ) || ! is_array( $relationships[ $name ][0] ) ) {
			return null;
		}

		return $relationships[ $name ][0];
	}

	/**
	 * The URL of the primary route, preferring upstream routes over
	 * redirects when both are flagged primary.
	 */
	public static function primary_route(): ?string {
		$routes = self::routes();

		foreach ( $routes as $url => $route ) {
			if ( ! empty( $route['primary'] ) && 'upstream' === ( $route['type'] ?? '' ) ) {
				return (string) $url;
			}
		}

		foreach ( $routes as $url => $route ) {
			if ( ! empty( $route['primary'] ) ) {
				return (string) $url;
			}
		}

		return null;
	}

	private static function env( string $name ): ?string {
		$value = getenv( $name );

		return false === $value ? null : $value;
	}

	/**
	 * Decode a base64-encoded JSON platform variable, caching the result.
	 *
	 * @return array Decoded data, or an empty array on missing/malformed input.
	 */
	private static function decoded( string $name ): array {
		if ( isset( self::$decoded_cache[ $name ] ) ) {
			return self::$decoded_cache[ $name ];
		}

		$decoded = array();
		$raw     = self::env( $name );

		if ( null !== $raw && '' !== $raw ) {
			$json = base64_decode( $raw, true );

			if ( false !== $json ) {
				$data = json_decode( $json, true );

				if ( is_array( $data ) ) {
					$decoded = $data;
				}
			}
		}

		self::$decoded_cache[ $name ] = $decoded;

		return $decoded;
	}
}
