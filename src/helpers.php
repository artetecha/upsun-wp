<?php
/**
 * Public helper functions for themes, plugins, and project code.
 *
 * All helpers are safe to call off-platform: they return false/null when the
 * PLATFORM_* environment variables are absent.
 */

namespace Upsun;

if ( ! function_exists( __NAMESPACE__ . '\\is_upsun' ) ) {

	function is_upsun(): bool {
		return Environment::is_upsun();
	}

	function environment_name(): ?string {
		return Environment::name();
	}

	/**
	 * production | staging | development, from PLATFORM_ENVIRONMENT_TYPE.
	 */
	function environment_type(): ?string {
		return Environment::type();
	}

	function is_production(): bool {
		return Environment::is_production();
	}

	function is_preview_environment(): bool {
		return Environment::is_upsun() && ! Environment::is_production();
	}

	function branch(): ?string {
		return Environment::branch();
	}

	function project_id(): ?string {
		return Environment::project();
	}

	function application_name(): ?string {
		return Environment::application_name();
	}

	function primary_route(): ?string {
		return Environment::primary_route();
	}

	/**
	 * @return array<string, array> URL => route definition.
	 */
	function routes(): array {
		return Environment::routes();
	}

	/**
	 * The first instance of a named relationship (host, port, etc.), or null.
	 */
	function relationship( string $name ): ?array {
		return Environment::relationship( $name );
	}

	function version(): string {
		return defined( 'UPSUN_MU_PLUGIN_VERSION' ) ? UPSUN_MU_PLUGIN_VERSION : '0.0.0-dev';
	}
}
