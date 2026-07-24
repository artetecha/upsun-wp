<?php

namespace Upsun;

/**
 * Optional reporting companion to Fetcher. A fetcher that also implements
 * this can describe itself for `wp upsun doctor`, Site Health, and the
 * dashboard: a human label, and whether its backing source is present on
 * this environment (e.g. a ThimPress fetcher is "available" only when
 * thim-core is active).
 *
 * Reporting only — is_available() must never be consulted to gate
 * resolution; that is supports()'s job. A fetcher that does not implement
 * this interface is still reported, under its id() and assumed available.
 */
interface FetcherStatus {

	/**
	 * Human-readable name, for reporting (e.g. "ThimPress").
	 */
	public function label(): string;

	/**
	 * Whether this fetcher's backing source is present, so it could resolve
	 * something on this environment. Reporting only.
	 */
	public function is_available(): bool;
}
