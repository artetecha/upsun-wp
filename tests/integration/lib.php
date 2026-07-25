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
