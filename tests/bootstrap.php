<?php
/**
 * Standalone test bootstrap: loads the plugin sources with a minimal set of
 * WordPress function stubs — enough to exercise environment parsing, module
 * gating, and the PageCache decision logic without a WordPress install.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit; // Test harness only; never run via the web server.
}

// Intercept-mail logging must not pollute the PHPUnit output stream.
ini_set( 'error_log', sys_get_temp_dir() . '/upsun-mu-plugin-tests.log' );

// Minimal hook system: enough for apply_filters/add_filter round-trips.
$GLOBALS['upsun_test_filters'] = array();
$GLOBALS['upsun_test_actions'] = array();
$GLOBALS['upsun_test_fired']   = array();

function upsun_test_reset_hooks(): void {
	$GLOBALS['upsun_test_filters'] = array();
	$GLOBALS['upsun_test_actions'] = array();
	$GLOBALS['upsun_test_fired']   = array();
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

function do_action( $hook, ...$args ) {
	$GLOBALS['upsun_test_fired'][ $hook ][] = $args;

	foreach ( $GLOBALS['upsun_test_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}

function has_action( $hook, $callback = false ) {
	return ! empty( $GLOBALS['upsun_test_actions'][ $hook ] );
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}

// Admin/rendering stubs, enough to smoke-render the dashboard page.
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return (string) $text;
}

function esc_attr( $text ) {
	return (string) $text;
}

function esc_url( $url ) {
	return (string) $url;
}

function current_user_can( $capability ) {
	return true;
}

function is_admin() {
	return false;
}

function is_user_logged_in() {
	return false;
}

function admin_url( $path = '' ) {
	return 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
}

function wp_nonce_field( ...$args ) {
	echo '<input type="hidden" name="_wpnonce" value="test">';
}

function wp_using_ext_object_cache() {
	return false;
}

// Option store: tests may seed $GLOBALS['upsun_test_options']; unseeded
// options return 1 (truthy) to preserve pre-existing test expectations.
$GLOBALS['upsun_test_options'] = array();

function get_option( $name, $default_value = false ) {
	return $GLOBALS['upsun_test_options'][ $name ] ?? 1;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['upsun_test_options'][ $name ] = $value;

	return true;
}

// Cron stubs: a flat store of scheduled hooks.
$GLOBALS['upsun_test_cron'] = array();

function wp_next_scheduled( $hook, $args = array() ) {
	return $GLOBALS['upsun_test_cron'][ $hook ] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['upsun_test_cron'][ $hook ] = $timestamp;

	return true;
}

function wp_get_schedules() {
	return array(
		'hourly'     => array( 'interval' => 3600 ),
		'twicedaily' => array( 'interval' => 43200 ),
		'daily'      => array( 'interval' => 86400 ),
	);
}

function wp_get_ready_cron_jobs() {
	return array();
}

function human_time_diff( $from, $to = 0 ) {
	$to = $to ? $to : time();

	return sprintf( '%d mins', max( 1, round( abs( $to - $from ) / 60 ) ) );
}

function wp_upload_dir() {
	return array(
		'basedir' => sys_get_temp_dir(),
		'error'   => false,
	);
}

function wp_is_writable( $path ) {
	return is_writable( (string) $path );
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
require_once dirname( __DIR__ ) . '/src/Modules/Dashboard.php';
require_once dirname( __DIR__ ) . '/src/Modules/CronHeartbeat.php';
require_once dirname( __DIR__ ) . '/src/Modules/SafePreviews.php';

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
