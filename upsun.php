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
define( 'UPSUN_MU_PLUGIN_VERSION', '0.4.2' );

require_once __DIR__ . '/src/Environment.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/CacheCheck.php';
require_once __DIR__ . '/src/Sanitizers.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/RelationshipHealth.php';
require_once __DIR__ . '/src/Module.php';
require_once __DIR__ . '/src/ModuleRegistry.php';
require_once __DIR__ . '/src/Integration.php';
require_once __DIR__ . '/src/IntegrationRegistry.php';
require_once __DIR__ . '/src/Integrations/WooCommerce.php';
require_once __DIR__ . '/src/Integrations/WooCommerceStripe.php';
require_once __DIR__ . '/src/Integrations/Wordfence.php';
require_once __DIR__ . '/src/Integrations/UpdraftPlus.php';
require_once __DIR__ . '/src/Integrations/WpRocket.php';
require_once __DIR__ . '/src/Modules/Cloudflare.php';
require_once __DIR__ . '/src/Modules/SecurityHeaders.php';
require_once __DIR__ . '/src/Modules/EnvironmentIndicator.php';
require_once __DIR__ . '/src/Modules/PageCache.php';
require_once __DIR__ . '/src/Modules/UpdatesPolicy.php';
require_once __DIR__ . '/src/Modules/SiteHealth.php';
require_once __DIR__ . '/src/Modules/PreviewProtection.php';
require_once __DIR__ . '/src/Modules/Smtp.php';
require_once __DIR__ . '/src/Modules/Dashboard.php';
require_once __DIR__ . '/src/Modules/CronHeartbeat.php';
require_once __DIR__ . '/src/Modules/SafePreviews.php';
require_once __DIR__ . '/src/Modules/WritablePaths.php';
require_once __DIR__ . '/src/Modules/MountUsage.php';

// The CLI command exists even off-platform so `wp upsun info` can report
// "Not running on Upsun" instead of erroring in local/CI environments.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/Cli/UpsunCommand.php';
	\WP_CLI::add_command( 'upsun', \Upsun\Cli\UpsunCommand::class );
}

// Integrations boot first (same hook and priority, registered earlier) so
// their filter contributions are in place when the modules read them.
add_action( 'muplugins_loaded', array( \Upsun\IntegrationRegistry::class, 'boot' ), 0 );
add_action( 'muplugins_loaded', array( \Upsun\ModuleRegistry::class, 'boot' ), 0 );
