<?php
/**
 * UPSUN_MU_DISABLE contract, on-platform: the documented kill switch must
 * stop both registries from booting while leaving the public API loaded and
 * the environment still readable.
 *
 * Run with the PLATFORM_* variables present and the constant defined before
 * mu-plugins load (see tests/integration/run.sh).
 */

require_once __DIR__ . '/lib.php';

it_suite( 'Kill switch: UPSUN_MU_DISABLE on-platform' );
it_context();

it_section( 'The switch is actually set' );

it_ok( 'UPSUN_MU_DISABLE is defined', defined( 'UPSUN_MU_DISABLE' ) && UPSUN_MU_DISABLE );

it_section( 'Nothing booted' );

it_same( 'no modules registered', array(), Upsun\ModuleRegistry::status() );
it_same( 'no integrations registered', array(), Upsun\IntegrationRegistry::status() );
it_same( 'no admin menu registered', false, has_action( 'admin_menu', array( 'Upsun\\Modules\\Dashboard', 'register_menu' ) ) );

$tests = apply_filters(
	'site_status_tests',
	array(
		'direct' => array(),
		'async'  => array(),
	)
);

$upsun_tests = array_filter(
	array_keys( (array) ( $tests['direct'] ?? array() ) ),
	static fn ( $key ) => 0 === strpos( (string) $key, 'upsun_' )
);

it_same( 'no Site Health tests added', array(), array_values( $upsun_tests ) );

it_section( 'The API is still loaded and the environment still readable' );

// The switch stops modules, not the helper facade: consumer code calling
// Upsun\environment_name() must not fatal because an operator disabled the
// modules.
it_ok( 'helper functions are defined', function_exists( 'Upsun\\is_upsun' ) );
it_same( 'is_upsun() still reports the platform', true, Upsun\is_upsun() );
it_same( 'environment_name() still resolves', 'pr-42', Upsun\environment_name() );
it_same( 'version() still reports the plugin constant', UPSUN_MU_PLUGIN_VERSION, Upsun\version() );

it_done();
