<?php

use PHPUnit\Framework\TestCase;
use Upsun\Deprecations;
use Upsun\Integrations\WooCommerce;
use Upsun\Integrations\WooCommerceStripe;
use Upsun\ModuleRegistry;
use Upsun\Modules\EnvironmentIndicator;
use Upsun\Modules\MountUsage;
use Upsun\Modules\WritablePaths;
use Upsun\Sanitizers;

/**
 * 0.7 renamed seven filters and replaced eight per-module boot toggles with one
 * generic filter. Every old name keeps working until 1.0, so this suite is the
 * contract for the shim layer: old name honoured, notice emitted, new name wins
 * when both are present, and no notice for consumers already on the new name.
 */
final class DeprecationsTest extends TestCase {

	/**
	 * Renamed filters, each with something observable to assert against:
	 *
	 *   old name => [ reader method, value the filter returns, what the reader
	 *                 then reports, the default the filter would return,
	 *                 what the reader reports for that default ].
	 *
	 * Most readers return the filtered value itself. upsun_disk_usage_thresholds
	 * is applied inside verdict(), so its reader observes the verdict instead —
	 * which is the better test anyway: it proves the value is actually used.
	 */
	private const READABLE = array(
		'upsun_writable_path_requirements'     => array( 'requirements', array( 'x' => array() ), array( 'x' => array() ), array(), array() ),
		'upsun_disk_usage_thresholds'          => array( 'verdict_at_half_full', array( 10, 20 ), 'fail', array( 80, 95 ), 'pass' ),
		'upsun_sanitize_anonymize_passwords'   => array( 'password_mode', 'password-{ID}', 'password-{ID}', false, false ),
		'upsun_safe_previews_pause_webhooks'   => array( 'webhooks_paused', false, false, true, true ),
		'upsun_safe_previews_stripe_test_mode' => array( 'stripe_test_mode', false, false, true, true ),
		'upsun_login_banner'                   => array( 'login_banner_shown', false, false, true, true ),
	);

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		UpsunDeprecatedToggleModule::$registered = 0;
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	/* ---- Readers ------------------------------------------------------- */

	private function requirements() {
		return WritablePaths::requirements();
	}

	/**
	 * Half-full disk: 'pass' under the default thresholds [80, 95], 'fail'
	 * once a consumer lowers them below 50.
	 */
	private function verdict_at_half_full() {
		return MountUsage::verdict( 100, 50 );
	}

	private function password_mode() {
		return ( new ReflectionMethod( Sanitizers::class, 'password_mode' ) )->invoke( null );
	}

	private function webhooks_paused() {
		return ( new ReflectionMethod( WooCommerce::class, 'webhooks_paused' ) )->invoke( new WooCommerce() );
	}

	private function stripe_test_mode() {
		return ( new ReflectionMethod( WooCommerceStripe::class, 'test_mode_forced' ) )->invoke( new WooCommerceStripe() );
	}

	private function login_banner_shown() {
		// The banner is prepended to the login message when enabled.
		return '' !== ( new EnvironmentIndicator() )->login_banner( '' );
	}

	private function read( string $old ) {
		$reader = self::READABLE[ $old ][0];

		return $this->{$reader}();
	}

	private function on_platform(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
		\Upsun\Environment::reset();
	}

	/* ---- The renamed filters ------------------------------------------ */

	/**
	 * @dataProvider readable_renames
	 */
	public function test_the_old_name_still_decides( string $old, $override, $expected ): void {
		add_filter( $old, static fn () => $override );

		$this->assertSame( $expected, $this->read( $old ) );
	}

	/**
	 * @dataProvider readable_renames
	 */
	public function test_using_the_old_name_reports_the_deprecation( string $old, $override ): void {
		add_filter( $old, static fn () => $override );

		$this->read( $old );

		$this->assertArrayHasKey( $old, $GLOBALS['upsun_test_deprecated'] );
		$this->assertSame( Deprecations::SINCE, $GLOBALS['upsun_test_deprecated'][ $old ]['version'] );
		$this->assertSame(
			Deprecations::RENAMED[ $old ],
			$GLOBALS['upsun_test_deprecated'][ $old ]['replacement']
		);
	}

	/**
	 * @dataProvider readable_renames
	 */
	public function test_the_canonical_name_works_and_is_silent( string $old, $override, $expected ): void {
		add_filter( Deprecations::RENAMED[ $old ], static fn () => $override );

		$this->assertSame( $expected, $this->read( $old ) );
		$this->assertSame( array(), $GLOBALS['upsun_test_deprecated'] );
	}

	/**
	 * The canonical filter runs last, so it wins a disagreement — otherwise a
	 * consumer could not migrate off a callback registered by other code.
	 *
	 * @dataProvider readable_renames
	 */
	public function test_the_canonical_name_wins_when_both_are_registered( string $old, $override, $expected, $default_value, $expected_default ): void {
		add_filter( $old, static fn () => $override );
		add_filter( Deprecations::RENAMED[ $old ], static fn () => $default_value );

		$this->assertSame( $expected_default, $this->read( $old ) );
	}

	public static function readable_renames(): array {
		$cases = array();

		foreach ( self::READABLE as $old => $spec ) {
			$cases[ $old ] = array( $old, $spec[1], $spec[2], $spec[3], $spec[4] );
		}

		return $cases;
	}

	/* ---- The eight replaced boot toggles ------------------------------ */

	/**
	 * @dataProvider replaced_toggles
	 */
	public function test_a_deprecated_module_toggle_still_prevents_boot( string $old ): void {
		$this->on_platform();

		add_filter( 'upsun_modules', static fn () => array( 'cloudflare' => UpsunDeprecatedToggleModule::class ) );
		add_filter( $old, '__return_false' );

		ModuleRegistry::boot();

		// Only the toggle mapped to the 'cloudflare' id can stop this module;
		// the rest must leave it alone (they belong to other ids).
		$expected = 'upsun_cloudflare_enabled' === $old ? 0 : 1;

		$this->assertSame( $expected, UpsunDeprecatedToggleModule::$registered );

		if ( 0 === $expected ) {
			$this->assertSame( 'disabled', ModuleRegistry::status()['cloudflare']['state'] );
			$this->assertArrayHasKey( $old, $GLOBALS['upsun_test_deprecated'] );
		}
	}

	public static function replaced_toggles(): array {
		$cases = array();

		foreach ( Deprecations::RENAMED as $old => $new ) {
			if ( 'upsun_module_enabled' === $new ) {
				$cases[ $old ] = array( $old );
			}
		}

		return $cases;
	}

	/* ---- Completeness -------------------------------------------------- */

	/**
	 * Every entry in RENAMED is covered by one of the two groups above, so a
	 * shim cannot be added without a test exercising it.
	 */
	public function test_every_renamed_filter_is_covered_by_this_suite(): void {
		$covered = array_merge(
			array_keys( self::READABLE ),
			array_keys( self::replaced_toggles() ),
			// Exercised in ModuleRegistryTest, which owns the module map.
			array( 'upsun_mu_modules' )
		);

		$this->assertSame(
			array(),
			array_diff( array_keys( Deprecations::RENAMED ), $covered ),
			'A renamed filter has no deprecation test.'
		);
	}

	/**
	 * Structural guard: each canonical name has exactly one shimmed
	 * application point, so a second one cannot creep in (the same invariant
	 * FilterApplicationTest enforces for the centralized accessors).
	 */
	public function test_each_shim_is_applied_in_exactly_one_place(): void {
		$sources = '';

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' )
		);

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$sources .= file_get_contents( $file->getPathname() );
			}
		}

		foreach ( array_unique( array_values( Deprecations::RENAMED ) ) as $canonical ) {
			if ( 'upsun_module_enabled' === $canonical ) {
				continue; // Applied once by the registry, for every module id.
			}

			$found = preg_match_all(
				'/Deprecations::filter\(\s*\n?\s*\'' . preg_quote( $canonical, '/' ) . '\'/',
				$sources
			);

			$this->assertSame( 1, $found, sprintf( '%s must have exactly one shimmed application point.', $canonical ) );
		}
	}
}

final class UpsunDeprecatedToggleModule implements Upsun\Module {

	public static int $registered = 0;

	public function should_load(): bool {
		return true;
	}

	public function register(): void {
		self::$registered++;
	}
}
