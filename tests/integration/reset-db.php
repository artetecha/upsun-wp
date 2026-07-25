<?php
/**
 * Drop and recreate the harness database through mysqli.
 *
 * `wp db reset` shells out to the mysql client binary, which is not present on
 * every developer machine (or in a minimal CI image). PHP's mysqli is already
 * a hard requirement for WordPress itself, so using it keeps the harness
 * dependency-free.
 *
 * Usage: php reset-db.php <host> <name> <user> <pass>
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

list( , $host, $name, $user, $pass ) = array_pad( $argv, 5, '' );

$port = 3306;

if ( str_contains( $host, ':' ) ) {
	list( $host, $port ) = explode( ':', $host, 2 );
	$port                = (int) $port;
}

mysqli_report( MYSQLI_REPORT_OFF );

$db = @new mysqli( $host, $user, $pass, '', $port );

if ( $db->connect_errno ) {
	fwrite( STDERR, sprintf( "Cannot connect to %s:%d as %s — %s\n", $host, $port, $user, $db->connect_error ) );
	exit( 1 );
}

// The name comes from the harness's own configuration, never user input, but
// backtick-quote it anyway so an unusual name cannot break the statement.
$quoted = '`' . str_replace( '`', '``', $name ) . '`';

foreach ( array(
	'DROP DATABASE IF EXISTS ' . $quoted,
	'CREATE DATABASE ' . $quoted . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
) as $sql ) {
	if ( true !== $db->query( $sql ) ) {
		fwrite( STDERR, sprintf( "Failed: %s — %s\n", $sql, $db->error ) );
		exit( 1 );
	}
}

printf( "Database %s reset.\n", $name );
