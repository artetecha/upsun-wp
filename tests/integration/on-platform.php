<?php
/**
 * On-platform contract, against real WordPress and a real database: the
 * registries boot, the shared check registry runs, and the two DB-writing
 * subsystems (deploy migrations, preview sanitizers) do what the unit tests
 * can only assert against stubs.
 *
 * Run with faked PLATFORM_* variables describing a non-production
 * environment (see tests/integration/run.sh).
 */

require_once __DIR__ . '/lib.php';

it_suite( 'On-platform: boot, checks, and the DB-writing subsystems' );
it_context();

it_section( 'Environment decoding' );

it_same( 'is_upsun()', true, Upsun\is_upsun() );
it_same( 'environment_name()', 'pr-42', Upsun\environment_name() );
it_same( 'environment_type()', 'staging', Upsun\environment_type() );
it_same( 'branch()', 'feature/harness', Upsun\branch() );
it_same( 'project_id()', 'abcdef123456', Upsun\project_id() );
it_same( 'application_name()', 'app', Upsun\application_name() );
it_same( 'is_production()', false, Upsun\is_production() );
it_same( 'is_preview_environment()', true, Upsun\is_preview_environment() );
it_same( 'primary_route()', getenv( 'UPSUN_IT_SITE_URL' ) . '/', Upsun\primary_route() );

$database = Upsun\relationship( 'database' );

it_ok( 'relationship( database ) decodes to an instance', is_array( $database ) );
it_same( 'relationship carries the host', '127.0.0.1', $database['host'] ?? null );
it_same( 'an absent relationship is null', null, Upsun\relationship( 'nope' ) );

it_section( 'Module boot under real WordPress' );

$status = Upsun\ModuleRegistry::status();

it_same( 'every built-in module is accounted for', 13, count( $status ) );

foreach ( array(
	'cloudflare',
	'security-headers',
	'environment-indicator',
	'page-cache',
	'updates-policy',
	'site-health',
	'preview-protection',
	'dashboard',
	'cron-heartbeat',
	'safe-previews',
	'writable-paths',
	'mount-usage',
) as $id ) {
	it_same( sprintf( 'module %s loaded', $id ), 'loaded', $status[ $id ]['state'] ?? 'absent' );
}

// Smtp::should_load() requires PLATFORM_SMTP_HOST, which the harness leaves
// unset — so this asserts the declined path as much as the module.
it_same( 'module smtp declined without PLATFORM_SMTP_HOST', 'declined', $status['smtp']['state'] ?? 'absent' );

it_same( 'every built-in integration is accounted for', 5, count( Upsun\IntegrationRegistry::status() ) );
it_ok( 'an integration instance is retrievable', Upsun\IntegrationRegistry::instance( 'woocommerce' ) instanceof Upsun\Integration );

it_section( 'Hooks landed on real WordPress' );

it_ok( 'Site Health tests filter hooked', false !== has_filter( 'site_status_tests' ) );
it_ok( 'debug information filter hooked', false !== has_filter( 'debug_information' ) );
it_ok( 'admin menu hooked', false !== has_action( 'admin_menu' ) );
it_ok( 'send_headers hooked (security headers)', false !== has_action( 'send_headers' ) );
it_ok( 'template_redirect hooked (page cache)', false !== has_action( 'template_redirect' ) );

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

it_ok(
	'Upsun tests are registered with core Site Health',
	count( $upsun_tests ) >= 5,
	sprintf( 'found %d: %s', count( $upsun_tests ), implode( ', ', $upsun_tests ) )
);

it_section( 'The shared check registry runs against the real install' );

// The same registry backs Site Health, `wp upsun doctor`, and the dashboard.
// Running every callback here is what proves the checks survive contact with
// a real database, real options, and real HTTP — the unit tests stub all three.
$checks = Upsun\Modules\SiteHealth::checks();

it_ok( 'the registry is populated', count( $checks ) >= 5, sprintf( '%d checks', count( $checks ) ) );

foreach ( $checks as $id => $check ) {
	$result = call_user_func( $check['callback'] );
	$status_value = (string) ( $result['status'] ?? '' );

	it_ok(
		sprintf( 'check %s returns a valid verdict', $id ),
		in_array( $status_value, array( 'pass', 'warn', 'fail' ), true )
			&& '' !== (string) ( $result['message'] ?? '' ),
		sprintf( 'got %s', it_export( $result ) )
	);
}

it_section( 'Deploy migrations against the real database' );

$fixtures = __DIR__ . '/fixtures/migrations';
add_filter( 'upsun_migrations_dir', static fn () => $fixtures );

// Self-contained: run.sh resets the database, but the script must also be
// re-runnable by hand against a kept install (WORK_DIR with KEEP=1).
delete_option( Upsun\Migrations::OPTION_PREFIX . '20260101_0001_seed_option' );
delete_option( 'upsun_harness_probe' );

it_same( 'the fixture directory resolves', $fixtures, Upsun\Migrations::directory() );
it_same( 'one migration pending', 1, count( Upsun\Migrations::pending() ) );
it_same( 'no invalid filenames in the fixture', array(), Upsun\Migrations::invalid() );

$run = Upsun\Migrations::run();

it_same( 'the migration applied', array( '20260101_0001_seed_option' ), $run['applied'] );
it_same( 'no error reported', null, $run['error'] );
it_same( 'its side effect is in the database', 'harness', get_option( 'upsun_harness_probe' ) );
it_ok(
	'the ledger option was written',
	is_string( get_option( Upsun\Migrations::OPTION_PREFIX . '20260101_0001_seed_option' ) )
);
it_same( 'nothing pending on a second pass', 0, count( Upsun\Migrations::pending() ) );
it_same( 'a second run is a no-op', array(), Upsun\Migrations::run()['applied'] );

it_section( 'Preview sanitizers against the real database' );

$preserved = 'admin@example.invalid';
$existing  = get_user_by( 'login', 'harness' );

if ( $existing instanceof WP_User ) {
	// Re-run against a kept install: put the address back so the anonymizer
	// has something to change.
	$user_id = (int) $existing->ID;
	wp_update_user(
		array(
			'ID'         => $user_id,
			'user_email' => 'harness@example.test',
		)
	);
} else {
	$user_id = wp_insert_user(
		array(
			'user_login' => 'harness',
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => 'harness@example.test',
		)
	);
}

it_ok( 'a test user exists', is_int( $user_id ) && $user_id > 0, it_export( $user_id ) );

if ( ! is_int( $user_id ) || $user_id <= 0 ) {
	// Everything below dereferences the user; a clear stop beats a fatal.
	it_done();
}

add_filter( 'upsun_sanitize_anonymize_user_emails', '__return_true' );
add_filter( 'upsun_sanitize_preserved_emails', static fn () => array( $preserved ) );

$registry = Upsun\Sanitizers::registry();

it_ok( 'the email sanitizer reports enabled', Upsun\Sanitizers::is_enabled( 'anonymize-user-emails', $registry['anonymize-user-emails'] ) );

$dry = Upsun\Sanitizers::run( true );

it_contains( 'dry run reports what it would do', 'would anonymize', implode( ' | ', $dry ) );
it_same( 'dry run wrote nothing', 'harness@example.test', get_userdata( $user_id )->user_email );

$wet = Upsun\Sanitizers::run( false );

it_contains( 'the run reports what it did', 'anonymized', implode( ' | ', $wet ) );

clean_user_cache( $user_id );

it_same(
	'the test user email is anonymized in the database',
	sprintf( 'user-%d@%s', $user_id, Upsun\Sanitizers::ANON_EMAIL_DOMAIN ),
	get_userdata( $user_id )->user_email
);

$admin = get_user_by( 'email', $preserved );

it_ok( 'the preserved address was left alone', $admin instanceof WP_User, 'preserved user not found by email' );

$again = Upsun\Sanitizers::run( false );

it_contains( 'a second run anonymizes nothing', 'anonymized 0', implode( ' | ', $again ) );

it_section( 'Preview mail interception' );

// Real wp_mail with no transport configured returns false; the safe-previews
// short-circuit reports success without sending. Only true with the module on.
it_same(
	'wp_mail is intercepted, not sent',
	true,
	wp_mail( 'nobody@example.invalid', 'harness', 'body' )
);

it_done();
