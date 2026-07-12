<?php

namespace Upsun;

/**
 * A third-party plugin integration: the single home for everything this
 * plugin knows about one specific plugin (WooCommerce, a gateway, a
 * security plugin, ...). Integrations contribute exclusively through the
 * same public filter API consumers use — never through privileged internal
 * calls — so every built-in integration doubles as proof the public API is
 * sufficient.
 */
interface Integration {

	/**
	 * Human-readable name of the target plugin, for status reporting.
	 */
	public function label(): string;

	/**
	 * Whether the target plugin is present. Reporting only — call at render
	 * time (after plugins load), never to gate registration.
	 */
	public function is_active(): bool;

	/**
	 * Contribute through the public filters. Runs at muplugins_loaded
	 * before regular plugins load, so every hooked callback must no-op when
	 * the target plugin is absent.
	 */
	public function register(): void;
}
