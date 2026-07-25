<?php
/**
 * The deprecation notices themselves, which need real WordPress twice over:
 * apply_filters_deprecated() is core, and _deprecated_hook() only calls
 * trigger_error() when WP_DEBUG is on. run.sh enables WP_DEBUG for this phase
 * only — which is also the honest consumer story: you see these notices on a
 * debug environment, never on production.
 */

require_once __DIR__ . '/lib.php';

it_suite( 'Deprecations: the notices core actually emits' );
it_context();

it_ok( 'WP_DEBUG is on for this phase', defined( 'WP_DEBUG' ) && WP_DEBUG );

$notices = array();

set_error_handler(
	static function ( $errno, $errstr ) use ( &$notices ) {
		$notices[] = $errstr;

		return true;
	},
	E_USER_DEPRECATED
);

it_section( 'A callback on a canonical name is silent' );

add_filter( 'upsun_mount_usage_thresholds', static fn () => array( 1, 2 ) );
it_same( 'value applied', 'fail', Upsun\Modules\MountUsage::verdict( 100, 50 ) );
it_same( 'no notice', array(), $notices );
remove_all_filters( 'upsun_mount_usage_thresholds' );

it_section( 'A callback on a deprecated name works and says so' );

add_filter( 'upsun_disk_usage_thresholds', static fn () => array( 1, 2 ) );
it_same( 'value still applied', 'fail', Upsun\Modules\MountUsage::verdict( 100, 50 ) );

$reported = implode( ' | ', $notices );

it_contains( 'the notice names the deprecated hook', 'upsun_disk_usage_thresholds', $reported );
it_contains( 'and the replacement', 'upsun_mount_usage_thresholds', $reported );
it_contains( 'and the version', Upsun\Deprecations::SINCE, $reported );
remove_all_filters( 'upsun_disk_usage_thresholds' );

it_section( 'Every shimmed name is reachable through core' );

// One pass over the whole map: registering each old name must produce a notice
// naming its replacement. Cheap, and it means a new shim cannot be added
// without this phase covering it.
foreach ( Upsun\Deprecations::RENAMED as $old => $new ) {
	$notices = array();

	add_filter( $old, '__return_true' );

	// apply_filters_deprecated() is what every shim funnels through; calling it
	// directly is what makes this loop generic over names we cannot all read
	// back through an accessor.
	apply_filters_deprecated( $old, array( true ), Upsun\Deprecations::SINCE, $new );

	it_ok(
		sprintf( '%s reports %s', $old, $new ),
		array() !== $notices && false !== strpos( implode( ' ', $notices ), $new ),
		it_export( $notices )
	);

	remove_all_filters( $old );
}

restore_error_handler();

it_done();
