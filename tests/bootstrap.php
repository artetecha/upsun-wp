<?php
/**
 * Standalone test bootstrap: loads the plugin sources with a minimal set of
 * WordPress function stubs — enough to exercise environment parsing, module
 * gating, and the PageCache decision logic without a WordPress install.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit; // Test harness only; never run via the web server.
}

// Minimal hook system: enough for apply_filters/add_filter round-trips.
$GLOBALS['upsun_test_filters'] = array();
$GLOBALS['upsun_test_actions'] = array();

function upsun_test_reset_hooks(): void {
	$GLOBALS['upsun_test_filters'] = array();
	$GLOBALS['upsun_test_actions'] = array();
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['upsun_test_filters'][ $hook ][] = $callback;
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['upsun_test_actions'][ $hook ][] = $callback;
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['upsun_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}

require_once dirname( __DIR__ ) . '/src/Environment.php';
require_once dirname( __DIR__ ) . '/src/helpers.php';
require_once dirname( __DIR__ ) . '/src/Module.php';
require_once dirname( __DIR__ ) . '/src/ModuleRegistry.php';
require_once dirname( __DIR__ ) . '/src/Modules/EnvironmentIndicator.php';
require_once dirname( __DIR__ ) . '/src/Modules/PageCache.php';
require_once dirname( __DIR__ ) . '/src/Modules/UpdatesPolicy.php';
require_once dirname( __DIR__ ) . '/src/Modules/SiteHealth.php';
require_once dirname( __DIR__ ) . '/src/Modules/PreviewProtection.php';
require_once dirname( __DIR__ ) . '/src/Modules/Smtp.php';

/**
 * Unset every PLATFORM_* variable a test may have set, and clear caches.
 */
function upsun_test_clear_env(): void {
	foreach ( array(
		'PLATFORM_APPLICATION_NAME',
		'PLATFORM_ENVIRONMENT',
		'PLATFORM_ENVIRONMENT_TYPE',
		'PLATFORM_BRANCH',
		'PLATFORM_PROJECT',
		'PLATFORM_ROUTES',
		'PLATFORM_RELATIONSHIPS',
		'PLATFORM_SMTP_HOST',
	) as $name ) {
		putenv( $name );
	}

	\Upsun\Environment::reset();
}
