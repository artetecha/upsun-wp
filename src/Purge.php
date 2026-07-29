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

		$declined = array();
		$threw    = array();

		foreach ( $backends as $id => $backend ) {
			if ( ! is_callable( $backend ) ) {
				continue;
			}

			// A backend is consumer code reaching a third-party API, so it may
			// throw where its author meant to return false. Letting that
			// propagate would take down whatever called us — a post-publish
			// hook, typically — and skip any fallback registered behind it,
			// which is exactly what the dispatch chain exists to allow. Treated
			// as a failed attempt: carry on, and report it if nothing else
			// succeeds.
			try {
				$result = call_user_func( $backend, $urls );
			} catch ( \Throwable $exception ) {
				$threw[ (string) $id ] = $exception->getMessage();
				continue;
			}

			// true on success; false to decline (pass it along); WP_Error to
			// fail loudly and stop.
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

			$declined[] = (string) $id;
		}

		return array(
			'purged'  => false,
			'backend' => null,
			'urls'    => $urls,
			'message' => self::nothing_purged_message( $declined, $threw ),
		);
	}

	/**
	 * Explain a purge that found no backend willing to do it.
	 *
	 * Names the backends involved rather than counting them, and only mentions
	 * Cloudflare's environment variables when Cloudflare is actually one of
	 * them — a consumer running a Fastly backend should not be told to
	 * configure a CDN they do not use. The router limitation is stated either
	 * way, because it is the reason this can happen at all.
	 *
	 * @param string[]              $declined Backend ids that passed the request along.
	 * @param array<string, string> $threw    Backend id => exception message.
	 */
	private static function nothing_purged_message( array $declined, array $threw ): string {
		$parts = array();

		if ( array() === $declined && array() === $threw ) {
			$parts[] = __( 'No shared-cache purge backend is registered.', 'upsun-mu-plugin' );
		}

		if ( array() !== $declined ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated backend ids. */
				__( 'These purge backends declined the request, so none is configured to run it: %s.', 'upsun-mu-plugin' ),
				implode( ', ', $declined )
			);
		}

		foreach ( $threw as $id => $message ) {
			$parts[] = sprintf(
				/* translators: 1: backend id, 2: error message. */
				__( 'The %1$s purge backend errored: %2$s', 'upsun-mu-plugin' ),
				$id,
				$message
			);
		}

		$parts[] = __( 'The Upsun router cache has no purge API — pages expire by TTL or on redeploy.', 'upsun-mu-plugin' );

		if ( in_array( 'cloudflare', $declined, true ) ) {
			$parts[] = __( 'Set CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN so the cloudflare module can purge the edge.', 'upsun-mu-plugin' );
		} elseif ( array() === $declined && array() === $threw ) {
			$parts[] = __( 'Front the site with Cloudflare, or register a backend through upsun_purge_backends.', 'upsun-mu-plugin' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * The registered backends, in dispatch order.
	 *
	 * @return array<string, callable> id => callable( string[] $urls ): true|false|\WP_Error
	 */
	public static function backends(): array {
		/**
		 * Filters the shared-cache purge backends, in dispatch order: the
		 * first one to return true wins, returning false passes the request
		 * along, and a WP_Error stops the chain and is reported. A backend
		 * receives absolute URLs, or an empty array meaning "everything you can
		 * invalidate". A backend that throws is treated as a failed attempt —
		 * the exception is caught and reported, never propagated to the caller,
		 * and the next backend still gets its turn.
		 *
		 * @param array<string, callable> $backends id => callable( string[] $urls ): true|false|WP_Error.
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
