<?php
/**
 * Renamed and replaced public filters, kept working for one cycle.
 *
 * 0.7 is the last pre-1.0 breaking-change window (see docs/api-audit-1.0.md),
 * so the names that were wrong were fixed here rather than frozen. Every old
 * name still works: it is applied first, and its result becomes the input to
 * the canonical filter, so a consumer that only registered the old name keeps
 * winning unless something also registers the new one.
 *
 * WordPress emits the deprecation notice through _deprecated_hook(), and only
 * when a callback is actually attached to the old name — so a site that never
 * used them sees nothing.
 *
 * This whole class is scheduled for deletion at 1.0, together with the RENAMED
 * map. That is the entire cost of the rename, and it lives in one file.
 */

namespace Upsun;

/**
 * @internal
 */
final class Deprecations {

	/** The release that deprecated these names. */
	public const SINCE = '0.7.0';

	/**
	 * Old filter name => canonical replacement. Documentation for consumers
	 * and the fixture the deprecation tests iterate, so a shim cannot be
	 * added without a test covering it.
	 *
	 * @var array<string, string>
	 */
	public const RENAMED = array(
		// Registry naming: "mu" is a delivery detail, not a concept in the
		// module API, and it was the only registry filter carrying it.
		'upsun_mu_modules'                      => 'upsun_modules',

		// Singular/plural mismatch against its own module and sibling filter.
		'upsun_writable_path_requirements'      => 'upsun_writable_paths_requirements',

		// Prefix named neither its module (mount-usage) nor a shared concept.
		'upsun_disk_usage_thresholds'           => 'upsun_mount_usage_thresholds',

		// Bare name read as global; owned by the environment-indicator module.
		'upsun_login_banner'                    => 'upsun_environment_indicator_login_banner',

		// Sibling is ..._anonymize_user_emails, and the sanitizer id is
		// already anonymize-user-passwords.
		'upsun_sanitize_anonymize_passwords'    => 'upsun_sanitize_anonymize_user_passwords',

		// Declared by the WooCommerce integrations, not the safe-previews
		// module: the old prefix misattributed ownership, so removing
		// safe-previews looked like it would take these with it.
		'upsun_safe_previews_pause_webhooks'    => 'upsun_woocommerce_pause_webhooks',
		'upsun_safe_previews_stripe_test_mode'  => 'upsun_woocommerce_stripe_test_mode',

		// The eight per-module boot toggles, replaced by one generic filter
		// that covers all thirteen modules instead of eight (see
		// ModuleRegistry::TOGGLES).
		'upsun_cloudflare_enabled'              => 'upsun_module_enabled',
		'upsun_security_headers_enabled'        => 'upsun_module_enabled',
		'upsun_environment_indicator_enabled'   => 'upsun_module_enabled',
		'upsun_dashboard_enabled'               => 'upsun_module_enabled',
		'upsun_cron_heartbeat_enabled'          => 'upsun_module_enabled',
		'upsun_safe_previews_enabled'           => 'upsun_module_enabled',
		'upsun_writable_paths_enabled'          => 'upsun_module_enabled',
		'upsun_mount_usage_enabled'             => 'upsun_module_enabled',
	);

	/**
	 * Apply a renamed filter: old name first, canonical name last.
	 *
	 * @param string $hook       Canonical filter name.
	 * @param string $deprecated The name it replaced.
	 * @param mixed  $value      Default value.
	 * @param mixed  ...$args    Extra filter arguments, passed to both names.
	 * @return mixed
	 */
	public static function filter( string $hook, string $deprecated, $value, ...$args ) {
		$value = apply_filters_deprecated(
			$deprecated,
			array_merge( array( $value ), $args ),
			self::SINCE,
			$hook
		);

		return apply_filters( $hook, $value, ...$args );
	}
}
