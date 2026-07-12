<?php

use PHPUnit\Framework\TestCase;
use Upsun\Modules\SafePreviews;
use Upsun\Sanitizers;

/**
 * Minimal wpdb: prepare() interpolates quoted values, get_var()/query()
 * record the SQL and return a fixed row count.
 */
final class UpsunTestWpdb {

	public $users = 'wp_users';

	/** @var string[] */
	public array $queries = array();

	public int $result = 3;

	public function prepare( $query, ...$args ) {
		$query = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );

		return vsprintf( $query, array_map( static fn ( $arg ) => is_string( $arg ) ? addslashes( $arg ) : $arg, $args ) );
	}

	public function get_var( $query ) {
		$this->queries[] = $query;

		return $this->result;
	}

	public function query( $query ) {
		$this->queries[] = $query;

		return $this->result;
	}
}

final class SanitizersTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		Sanitizers::force( array() );
		$GLOBALS['upsun_test_options']        = array();
		$GLOBALS['upsun_test_active_plugins'] = array();
		unset( $GLOBALS['wpdb'] );
	}

	private function fake_preview_env(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=pr-9' );
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=development' );
		\Upsun\Environment::reset();
	}

	private function fake_wpdb(): UpsunTestWpdb {
		$GLOBALS['wpdb'] = new UpsunTestWpdb();

		return $GLOBALS['wpdb'];
	}

	/* Registry and guards. */

	public function test_built_ins_are_registered_and_disabled_by_default(): void {
		$this->fake_preview_env();

		$this->assertSame(
			array( 'anonymize-user-emails', 'anonymize-user-passwords', 'deactivate-plugins', 'scrub-options' ),
			array_keys( Sanitizers::registry() )
		);

		foreach ( Sanitizers::registry() as $id => $sanitizer ) {
			$this->assertFalse( Sanitizers::is_enabled( (string) $id, $sanitizer ), "{$id} must ship disabled" );
		}

		$this->assertSame( array(), Sanitizers::run( true ) );
	}

	public function test_registry_is_filterable(): void {
		$this->fake_preview_env();

		add_filter(
			'upsun_preview_sanitizers',
			function ( array $sanitizers ) {
				$sanitizers['custom'] = array(
					'label'   => 'Custom',
					'enabled' => '__return_true',
					'run'     => static function ( bool $dry_run ) {
						return $dry_run ? 'would do the thing' : 'did the thing';
					},
				);

				return $sanitizers;
			}
		);

		$this->assertSame( array( 'Custom: would do the thing' ), Sanitizers::run( true ) );
		$this->assertSame( array( 'Custom: did the thing' ), Sanitizers::run( false ) );
	}

	public function test_run_refuses_on_production(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		putenv( 'PLATFORM_ENVIRONMENT_TYPE=production' );
		\Upsun\Environment::reset();

		add_filter(
			'upsun_preview_sanitizers',
			function ( array $sanitizers ) {
				$sanitizers['custom'] = array(
					'label'   => 'Custom',
					'enabled' => '__return_true',
					'run'     => static fn () => 'must never appear',
				);

				return $sanitizers;
			}
		);

		$this->assertSame( array(), Sanitizers::run( false ) );
	}

	/* deactivate-plugins. */

	public function test_deactivate_plugins_dry_run_and_real_run(): void {
		$this->fake_preview_env();
		$GLOBALS['upsun_test_active_plugins'] = array( 'a/a.php', 'other/other.php' );

		add_filter(
			'upsun_sanitize_deactivate_plugins',
			static fn () => array( 'a/a.php', 'b/b.php' )
		);

		$dry = Sanitizers::run( true );
		$this->assertSame( array( 'Deactivate plugins: would deactivate: a/a.php' ), $dry );
		$this->assertContains( 'a/a.php', $GLOBALS['upsun_test_active_plugins'] );

		$run = Sanitizers::run( false );
		$this->assertSame( array( 'Deactivate plugins: deactivated: a/a.php' ), $run );
		$this->assertNotContains( 'a/a.php', $GLOBALS['upsun_test_active_plugins'] );
		$this->assertContains( 'other/other.php', $GLOBALS['upsun_test_active_plugins'] );

		// Idempotent: the second real run reports nothing.
		$this->assertSame( array(), Sanitizers::run( false ) );
	}

	/* scrub-options. */

	public function test_scrub_options_replaces_deletes_and_unsets(): void {
		$this->fake_preview_env();
		$GLOBALS['upsun_test_options']['plain']  = 'live-secret';
		$GLOBALS['upsun_test_options']['nested'] = array( 'keys' => array( 'live' => 'sk_live_x', 'label' => 'keep' ) );
		$GLOBALS['upsun_test_options']['gone']   = 'anything';

		add_filter(
			'upsun_sanitize_scrub_options',
			static fn () => array(
				'plain'            => 'scrubbed',
				'nested.keys.live' => null,
				'gone'             => null,
				'absent-option'    => 'whatever',
			)
		);

		$dry = Sanitizers::run( true );
		$this->assertSame( array( 'Scrub options: would scrub: plain, nested.keys.live, gone (deleted)' ), $dry );
		$this->assertSame( 'live-secret', $GLOBALS['upsun_test_options']['plain'] );

		$run = Sanitizers::run( false );
		$this->assertSame( array( 'Scrub options: scrubbed: plain, nested.keys.live, gone (deleted)' ), $run );
		$this->assertSame( 'scrubbed', $GLOBALS['upsun_test_options']['plain'] );
		$this->assertSame( array( 'label' => 'keep' ), $GLOBALS['upsun_test_options']['nested']['keys'] );
		$this->assertArrayNotHasKey( 'gone', $GLOBALS['upsun_test_options'] );

		// Idempotent: everything already scrubbed.
		$this->assertSame( array(), Sanitizers::run( false ) );
	}

	/* anonymize-user-emails. */

	public function test_anonymize_emails_builds_an_idempotent_update(): void {
		$this->fake_preview_env();
		$wpdb = $this->fake_wpdb();

		add_filter( 'upsun_sanitize_anonymize_user_emails', '__return_true' );
		add_filter(
			'upsun_sanitize_preserved_emails',
			static fn () => array( 'keep@example.com', '@corp.example' )
		);

		$dry = Sanitizers::run( true );
		$this->assertSame( array( 'Anonymize user emails: would anonymize 3 user email(s) to user-{ID}@upsun-preview.invalid' ), $dry );
		$this->assertStringStartsWith( 'SELECT COUNT(*)', $wpdb->queries[0] );
		$this->assertStringContainsString( "user_email NOT LIKE '%@upsun-preview.invalid'", $wpdb->queries[0] );
		$this->assertStringContainsString( "user_email != 'keep@example.com'", $wpdb->queries[0] );
		$this->assertStringContainsString( "user_email NOT LIKE '%@corp.example'", $wpdb->queries[0] );

		$run = Sanitizers::run( false );
		$this->assertSame( array( 'Anonymize user emails: anonymized 3 user email(s)' ), $run );
		$update = end( $wpdb->queries );
		$this->assertStringContainsString( "SET user_email = CONCAT('user-', ID, '@upsun-preview.invalid')", $update );
		$this->assertStringContainsString( "user_email NOT LIKE '%@upsun-preview.invalid'", $update );
	}

	/* anonymize-user-passwords. */

	public function test_shared_password_mode(): void {
		$this->fake_preview_env();
		$wpdb = $this->fake_wpdb();

		add_filter( 'upsun_sanitize_anonymize_passwords', '__return_true' );

		$run = Sanitizers::run( false );

		$this->assertSame( array( 'Anonymize user passwords: set 3 password(s) to "password"' ), $run );
		$update = end( $wpdb->queries );
		$this->assertStringContainsString( "SET user_pass = MD5('password')", $update );
		$this->assertStringContainsString( "user_pass != MD5('password')", $update );
	}

	public function test_per_user_password_template(): void {
		$this->fake_preview_env();
		$wpdb = $this->fake_wpdb();

		add_filter( 'upsun_sanitize_anonymize_passwords', static fn () => 'password-{ID}' );
		add_filter( 'upsun_sanitize_preserved_emails', static fn () => array( 'keep@example.com' ) );

		$dry = Sanitizers::run( true );
		$this->assertSame( array( 'Anonymize user passwords: would set 3 password(s) to "password-{ID}"' ), $dry );

		Sanitizers::run( false );
		$update = end( $wpdb->queries );
		$this->assertStringContainsString( "SET user_pass = MD5(CONCAT('password-', ID, ''))", $update );
		$this->assertStringContainsString( "user_pass != MD5(CONCAT('password-', ID, ''))", $update );
		$this->assertStringContainsString( "user_email != 'keep@example.com'", $update );
	}

	/* Forced enablement (the CLI --enable flag). */

	public function test_force_enables_sanitizers_and_passes_values(): void {
		$this->fake_preview_env();
		$wpdb                                 = $this->fake_wpdb();
		$GLOBALS['upsun_test_active_plugins'] = array( 'a/a.php' );

		Sanitizers::force(
			array(
				'anonymize-user-emails'    => true,
				'anonymize-user-passwords' => 'password-{ID}',
				'deactivate-plugins'       => 'a/a.php|b/b.php',
			)
		);

		$reports = Sanitizers::run( true );

		$this->assertCount( 3, $reports );
		$this->assertStringContainsString( 'would anonymize 3 user email(s)', $reports[0] );
		$this->assertStringContainsString( 'would set 3 password(s) to "password-{ID}"', $reports[1] );
		$this->assertStringContainsString( 'would deactivate: a/a.php', $reports[2] );

		// The forced template reached the SQL.
		$this->assertStringContainsString( "MD5(CONCAT('password-', ID, ''))", implode( ' ', $wpdb->queries ) );
	}

	public function test_force_is_per_run_and_clearable(): void {
		$this->fake_preview_env();
		$this->fake_wpdb();

		Sanitizers::force( array( 'anonymize-user-emails' => true ) );
		$this->assertCount( 1, Sanitizers::run( true ) );

		Sanitizers::force( array() );
		$this->assertSame( array(), Sanitizers::run( true ) );
	}

	public function test_forced_unknown_ids_are_ignored_by_the_engine(): void {
		$this->fake_preview_env();

		Sanitizers::force( array( 'does-not-exist' => true ) );

		$this->assertSame( array(), Sanitizers::run( true ) );
	}

	/* SafePreviews flow. */

	public function test_sanitizers_run_before_the_consumer_hook_and_are_reported(): void {
		$this->fake_preview_env();

		$order = array();

		add_filter(
			'upsun_preview_sanitizers',
			function ( array $sanitizers ) use ( &$order ) {
				$sanitizers['custom'] = array(
					'label'   => 'Custom',
					'enabled' => '__return_true',
					'run'     => static function () use ( &$order ) {
						$order[] = 'sanitizer';

						return 'did the thing';
					},
				);

				return $sanitizers;
			}
		);

		add_action(
			SafePreviews::SANITIZE_HOOK,
			static function () use ( &$order ) {
				$order[] = 'action';
			}
		);

		$result = ( new SafePreviews() )->run_sanitize( 'main' );

		$this->assertSame( array( 'sanitizer', 'action' ), $order );
		$this->assertSame( array( 'Custom: did the thing' ), $result['reports'] );

		$stored = $GLOBALS['upsun_test_options'][ SafePreviews::REPORT_OPTION ];
		$this->assertSame( array( 'Custom: did the thing' ), $stored['reports'] );
	}
}
