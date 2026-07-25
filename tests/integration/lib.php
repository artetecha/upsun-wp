<?php
/**
 * Assertion helpers for the integration scripts, which run inside a real
 * WordPress through `wp eval-file`.
 *
 * Deliberately dependency-free: the consumer project these run in has no
 * PHPUnit, and the scripts must work on every WordPress version in the
 * matrix. Each script ends with it_done(), which exits non-zero on any
 * failure so the shell runner (and CI) fails.
 */

$GLOBALS['upsun_it'] = array(
	'passed' => 0,
	'failed' => array(),
);

function it_suite( string $name ): void {
	printf( "\n%s\n%s\n", $name, str_repeat( '=', strlen( $name ) ) );
}

function it_section( string $name ): void {
	printf( "\n  %s\n", $name );
}

function it_ok( string $what, bool $condition, string $detail = '' ): void {
	if ( $condition ) {
		++$GLOBALS['upsun_it']['passed'];
		printf( "    ok    %s\n", $what );

		return;
	}

	$GLOBALS['upsun_it']['failed'][] = $what;
	printf( "    FAIL  %s%s\n", $what, '' !== $detail ? ' — ' . $detail : '' );
}

function it_same( string $what, $expected, $actual ): void {
	it_ok(
		$what,
		$expected === $actual,
		sprintf( 'expected %s, got %s', it_export( $expected ), it_export( $actual ) )
	);
}

function it_contains( string $what, string $needle, string $haystack ): void {
	it_ok(
		$what,
		false !== strpos( $haystack, $needle ),
		sprintf( '%s not present in %s', it_export( $needle ), it_export( $haystack ) )
	);
}

function it_export( $value ): string {
	$encoded = is_string( $value ) ? $value : (string) json_encode( $value );

	return strlen( $encoded ) > 300 ? substr( $encoded, 0, 297 ) . '...' : $encoded;
}

/**
 * The Upsun test ids core Site Health would run, resolved through the real
 * filter. Shared by every suite so the hook name and the id prefix live in one
 * place rather than three.
 *
 * @return string[]
 */
function it_site_health_test_ids(): array {
	$tests = apply_filters(
		'site_status_tests',
		array(
			'direct' => array(),
			'async'  => array(),
		)
	);

	return array_values(
		array_filter(
			array_keys( (array) ( $tests['direct'] ?? array() ) ),
			static fn ( $key ) => 0 === strpos( (string) $key, 'upsun_' )
		)
	);
}

/**
 * Whether the dashboard module registered its admin menu.
 *
 * Dashboard hooks admin_menu with an instance callback, so has_action() cannot
 * be given a comparable callable from outside (there is no public accessor for
 * module instances). Walking the hook and matching on the object's class is the
 * only probe that answers true when the module is booted and false when it is
 * not — which is what makes the same helper usable by all three suites.
 */
function it_dashboard_menu_registered(): bool {
	global $wp_filter;

	if ( ! isset( $wp_filter['admin_menu'] ) ) {
		return false;
	}

	foreach ( $wp_filter['admin_menu'] as $callbacks ) {
		foreach ( (array) $callbacks as $registered ) {
			$callback = $registered['function'] ?? null;

			if ( is_array( $callback ) && ( $callback[0] ?? null ) instanceof \Upsun\Modules\Dashboard ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Print the WordPress/PHP context the assertions ran against, so a matrix
 * failure in CI is attributable without re-reading the job matrix.
 */
function it_context(): void {
	printf(
		"  WordPress %s / PHP %s / plugin %s\n",
		get_bloginfo( 'version' ),
		PHP_VERSION,
		\Upsun\version()
	);
}

function it_done(): void {
	$failed = $GLOBALS['upsun_it']['failed'];

	printf( "\n  %d passed, %d failed\n", $GLOBALS['upsun_it']['passed'], count( $failed ) );

	foreach ( $failed as $what ) {
		printf( "    failed: %s\n", $what );
	}

	exit( array() === $failed ? 0 : 1 );
}
