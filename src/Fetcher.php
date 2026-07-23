<?php

namespace Upsun;

/**
 * A strategy for finding the authenticated download of a premium
 * plugin/theme update. The generic vendoring engine (Upsun\Vendor) owns
 * download, extraction, and re-vendoring; a Fetcher answers only the one
 * question that varies per vendor: given a slug, is there a newer version,
 * and where is its (already-authenticated) download?
 *
 * Load-bearing contract: a Fetcher reads its credentials from the site's own
 * stored state — the WordPress update transient a licensed updater already
 * populated, or the vendor's registration record in the database — never from
 * an environment variable or committed config. The "tokens never leave the
 * container" guarantee then holds for free, because the secret is already in
 * the (cloned) database and nothing is injected from outside. A Fetcher must
 * therefore run on an environment whose database carries the activation.
 *
 * Fetchers register through the upsun_vendor_fetchers filter; the first one
 * whose supports() returns true wins, with the built-in TransientFetcher as
 * the universal fallback.
 */
interface Fetcher {

	/**
	 * Short identifier, for reporting and dispatch (e.g. 'transient').
	 */
	public function id(): string;

	/**
	 * Whether this fetcher handles the given package. Consumer/vendor
	 * fetchers should gate narrowly (e.g. a ThimPress fetcher only when
	 * thim-core is present); the built-in fallback returns true.
	 */
	public function supports( string $slug, string $type ): bool;

	/**
	 * The available update for the package, or null when none is pending
	 * (or the package is unlicensed / not resolvable). Reads auth from site
	 * state, never from env.
	 *
	 * @return array{version: string, url: string, headers?: array<string,string>}|null
	 */
	public function available_update( string $slug, string $type ): ?array;
}
