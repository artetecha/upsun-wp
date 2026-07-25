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
it_same( 'no admin menu registered', false, it_dashboard_menu_registered() );
it_same( 'no Site Health tests added', array(), it_site_health_test_ids() );

it_section( 'The API is still loaded and the environment still readable' );

// The switch stops modules, not the helper facade: consumer code calling
// Upsun\environment_name() must not fatal because an operator disabled the
// modules.
it_ok( 'helper functions are defined', function_exists( 'Upsun\\is_upsun' ) );
it_same( 'is_upsun() still reports the platform', true, Upsun\is_upsun() );
it_same( 'environment_name() still resolves', 'pr-42', Upsun\environment_name() );
it_same( 'version() still reports the plugin constant', UPSUN_MU_PLUGIN_VERSION, Upsun\version() );

it_done();
