<?php
/**
 * Shared-cache purge dispatch behind Upsun\purge_paths().
 *
 * The Upsun router cache has no purge API — pages expire by TTL or on
 * redeploy — so for a long time this plugin had nothing to offer here and said
 * so. What changed is that a site fronted by Cloudflare *does* have an
 * invalidation path, and consumer code should not have to know which one it is:
 * a theme that just published a page wants "purge these paths", not "detect
 * whether Cloudflare is in front of me, then call its module".
 *
 * So this is a facade over pluggable backends. The Cloudflare module registers
 * one when it is configured; a consumer can register Fastly, Varnish, or the
 * router itself if Upsun ever ships purging. With no backend available the call
 * reports that honestly rather than pretending to have worked — the same
 * "honest about the platform" rule the rest of the plugin follows.
 *
 * @internal The dispatch. Upsun\purge_paths() is the public entry point.
 */

namespace Upsun;

final class Purge {

	/**
	 * Run a purge through the first available backend.
	 *
	 * @param string[] $paths Paths (`/about/`) or absolute URLs. Empty means
	 *                        everything the backend can invalidate.
	 * @return array{purged: bool, backend: ?string, urls: string[], message: string}
	 */
	public static function run( array $paths ): array {
		$urls     = self::resolve( $paths );
		$backends = self::backends();

		if ( array() !== $paths && array() === $urls ) {
			return array(
				'purged'  => false,
				'backend' => null,
				'urls'    => array(),
				'message' => __( 'No purgeable URL could be resolved: pass absolute URLs, or run where PLATFORM_ROUTES gives a primary route.', 'upsun-mu-plugin' ),
			);
		}

		foreach ( $backends as $id => $backend ) {
			if ( ! is_callable( $backend ) ) {
				continue;
			}

			$result = call_user_func( $backend, $urls );

			// A backend returns true on success, or false/WP_Error to decline
			// or fail. Declining passes the request to the next one, so a
			// consumer can register a fallback behind Cloudflare.
			if ( true === $result ) {
				return array(
					'purged'  => true,
					'backend' => (string) $id,
					'urls'    => $urls,
					'message' => array() === $urls
						? sprintf( __( 'Purged everything via %s.', 'upsun-mu-plugin' ), $id )
						: sprintf(
							/* translators: 1: number of URLs, 2: backend id. */
							__( 'Purged %1$d URL(s) via %2$s.', 'upsun-mu-plugin' ),
							count( $urls ),
							$id
						),
				);
			}

			if ( is_wp_error( $result ) ) {
				return array(
					'purged'  => false,
					'backend' => (string) $id,
					'urls'    => $urls,
					'message' => $result->get_error_message(),
				);
			}
		}

		// Both paths carry the platform explanation, because they need the same
		// action from the reader. "Every backend declined" on its own is the
		// common case on a site with the cloudflare module loaded and no purge
		// credentials — and it tells an operator nothing they can act on.
		$explanation = __( 'The Upsun router cache has no purge API — pages expire by TTL or on redeploy. Set Cloudflare purge credentials (CLOUDFLARE_ZONE_ID / CLOUDFLARE_API_TOKEN) so the cloudflare module can purge the edge, or register a backend through upsun_purge_backends.', 'upsun-mu-plugin' );

		return array(
			'purged'  => false,
			'backend' => null,
			'urls'    => $urls,
			'message' => array() === $backends
				? __( 'No shared-cache purge backend is registered.', 'upsun-mu-plugin' ) . ' ' . $explanation
				: sprintf(
					/* translators: %d: number of registered backends. */
					_n(
						'%d registered purge backend declined the request (not configured).',
						'All %d registered purge backends declined the request (none configured).',
						count( $backends ),
						'upsun-mu-plugin'
					),
					count( $backends )
				) . ' ' . $explanation,
		);
	}

	/**
	 * The registered backends, in dispatch order.
	 *
	 * @return array<string, callable> id => callable( string[] $urls ): true|false|\WP_Error
	 */
	public static function backends(): array {
		/**
		 * Filters the shared-cache purge backends, in dispatch order: the
		 * first one to return true wins, and returning false passes the
		 * request along. A backend receives absolute URLs, or an empty array
		 * meaning "everything you can invalidate".
		 *
		 * @param array<string, callable> $backends id => callable.
		 */
		$backends = (array) apply_filters( 'upsun_purge_backends', array() );

		return array_filter( $backends, 'is_callable' );
	}

	/**
	 * Turn paths into absolute URLs against the primary route, passing through
	 * anything already absolute. Unresolvable entries are dropped rather than
	 * guessed at, so a backend never gets a half-built URL.
	 *
	 * @param string[] $paths
	 * @return string[]
	 */
	private static function resolve( array $paths ): array {
		$primary = Environment::primary_route();
		$urls    = array();

		foreach ( $paths as $path ) {
			$path = trim( (string) $path );

			if ( '' === $path ) {
				continue;
			}

			if ( preg_match( '#^https?://#i', $path ) ) {
				$urls[] = $path;
				continue;
			}

			if ( null === $primary ) {
				continue;
			}

			$urls[] = rtrim( $primary, '/' ) . '/' . ltrim( $path, '/' );
		}

		return array_values( array_unique( $urls ) );
	}
}
