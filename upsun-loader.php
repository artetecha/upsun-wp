<?php
/**
 * Plugin Name: Upsun
 * Plugin URI: https://upsun.artetecha.com/
 * Description: Platform integration for WordPress on Upsun. Loads the Upsun must-use plugin from mu-plugins/upsun/. The plugin version lives in the package composer.json / UPSUN_MU_PLUGIN_VERSION.
 * Author: Vince Russo
 * Author URI: https://artetecha.com
 * License: MIT
 *
 * WordPress does not scan mu-plugin subdirectories, so this shim must sit at
 * the mu-plugins root while the package itself is installed by Composer into
 * mu-plugins/upsun/. Copy it there in your build, e.g. in composer.json:
 *
 *   "cp wordpress/wp-content/mu-plugins/upsun/upsun-loader.php wordpress/wp-content/mu-plugins/upsun-loader.php"
 */

defined( 'ABSPATH' ) || exit;

if ( is_readable( __DIR__ . '/upsun/upsun.php' ) ) {
	require_once __DIR__ . '/upsun/upsun.php';
}
