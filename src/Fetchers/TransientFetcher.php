<?php
/**
 * The built-in, universal fetcher: the WordPress update transient.
 *
 * Standard licensed updaters (Fluent pro, Paid Memberships Pro, most
 * EDD-based plugins) inject an already-authenticated `package` download URL
 * into update_plugins/update_themes once their license is active on the
 * site. This fetcher reads that — no credentials of its own, exactly the
 * mechanism KEDS's premium-update.sh uses. It supports every slug (it is the
 * fallback), returning null when the transient carries no downloadable URL
 * for the package (up to date, or unlicensed).
 */

namespace Upsun\Fetchers;

use Upsun\Fetcher;
use Upsun\Vendor;

class TransientFetcher implements Fetcher {

	public function id(): string {
		return 'transient';
	}

	public function supports( string $slug, string $type ): bool {
		return true;
	}

	public function available_update( string $slug, string $type ): ?array {
		$rows = Vendor::classify_updates(
			get_site_transient( 'update_plugins' ),
			get_site_transient( 'update_themes' )
		);

		foreach ( $rows as $row ) {
			if ( $row['slug'] !== $slug || $row['type'] !== $type ) {
				continue;
			}

			// No package URL => the updater has no authenticated download
			// (unlicensed, or nothing pending): nothing to fetch.
			if ( '' === $row['package'] ) {
				return null;
			}

			return array(
				'version' => $row['new'],
				'url'     => $row['package'],
				'headers' => array(),
			);
		}

		return null;
	}
}
