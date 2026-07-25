<?php
/**
 * Off-platform contract: the plugin loads inside real WordPress, exposes its
 * public API, and fully no-ops. Run with no PLATFORM_* variables set.
 *
 * This is the case every consumer hits locally, so a fatal here would break
 * development installs of any site using the plugin.
 */

require_once __DIR__ . '/lib.php';

it_suite( 'Off-platform: loaded, inert, and fatal-free' );
it_context();

it_section( 'Public API is present' );

it_ok( 'helper functions are defined', function_exists( 'Upsun\\is_upsun' ) );
it_ok( 'the loader defined the plugin directory', defined( 'UPSUN_MU_PLUGIN_DIR' ) );
it_same( 'version() reports the plugin constant', UPSUN_MU_PLUGIN_VERSION, Upsun\version() );

it_section( 'Every helper is safe off-platform' );

it_same( 'is_upsun()', false, Upsun\is_upsun() );
it_same( 'is_production()', false, Upsun\is_production() );
it_same( 'is_preview_environment()', false, Upsun\is_preview_environment() );
it_same( 'environment_name()', null, Upsun\environment_name() );
it_same( 'environment_type()', null, Upsun\environment_type() );
it_same( 'branch()', null, Upsun\branch() );
it_same( 'project_id()', null, Upsun\project_id() );
it_same( 'application_name()', null, Upsun\application_name() );
it_same( 'primary_route()', null, Upsun\primary_route() );
it_same( 'routes()', array(), Upsun\routes() );
it_same( 'relationship( database )', null, Upsun\relationship( 'database' ) );

it_section( 'Nothing booted' );

it_same( 'no modules registered', array(), Upsun\ModuleRegistry::status() );
it_same( 'no integrations registered', array(), Upsun\IntegrationRegistry::status() );

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
it_same( 'no admin menu registered', false, has_action( 'admin_menu', array( 'Upsun\\Modules\\Dashboard', 'register_menu' ) ) );

it_section( 'Cache-header path stays out of the way' );

// The module is not booted, so its accessors are the only thing reachable —
// they must still answer with documented defaults rather than fataling.
it_same( 'page-cache TTL default', 600, Upsun\Modules\PageCache::ttl() );
it_same( 'stripped-cookie default', array(), Upsun\Modules\PageCache::stripped_cookies() );

it_done();
