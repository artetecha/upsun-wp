<?php
/**
 * Upsun must-use plugin entry point.
 *
 * Loaded via the upsun-loader.php shim at the mu-plugins root (WordPress does
 * not scan mu-plugin subdirectories). Module boot is deferred to
 * muplugins_loaded priority 0 so any other mu-plugin can register
 * upsun_* filters first, regardless of alphabetical load order.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'UPSUN_MU_PLUGIN_DIR' ) ) {
	return; // Already loaded.
}

define( 'UPSUN_MU_PLUGIN_DIR', __DIR__ );
define( 'UPSUN_MU_PLUGIN_VERSION', '0.2.0' );

require_once __DIR__ . '/src/Environment.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/Module.php';
require_once __DIR__ . '/src/ModuleRegistry.php';
require_once __DIR__ . '/src/Modules/EnvironmentIndicator.php';
require_once __DIR__ . '/src/Modules/PageCache.php';
require_once __DIR__ . '/src/Modules/UpdatesPolicy.php';
require_once __DIR__ . '/src/Modules/SiteHealth.php';
require_once __DIR__ . '/src/Modules/PreviewProtection.php';
require_once __DIR__ . '/src/Modules/Smtp.php';
require_once __DIR__ . '/src/Modules/Dashboard.php';

// The CLI command exists even off-platform so `wp upsun info` can report
// "Not running on Upsun" instead of erroring in local/CI environments.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/Cli/UpsunCommand.php';
	\WP_CLI::add_command( 'upsun', \Upsun\Cli\UpsunCommand::class );
}

add_action( 'muplugins_loaded', array( \Upsun\ModuleRegistry::class, 'boot' ), 0 );
