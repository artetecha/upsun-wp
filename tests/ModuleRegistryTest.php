<?php

use PHPUnit\Framework\TestCase;
use Upsun\Module;
use Upsun\ModuleRegistry;

final class UpsunTestModule implements Module {

	public static int $registered = 0;

	public function should_load(): bool {
		return true;
	}

	public function register(): void {
		self::$registered++;
	}
}

final class UpsunTestDisabledModule implements Module {

	public static int $registered = 0;

	public function should_load(): bool {
		return false;
	}

	public function register(): void {
		self::$registered++;
	}
}

final class ModuleRegistryTest extends TestCase {

	protected function setUp(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
		UpsunTestModule::$registered         = 0;
		UpsunTestDisabledModule::$registered = 0;
	}

	protected function tearDown(): void {
		upsun_test_clear_env();
		upsun_test_reset_hooks();
	}

	private function on_platform(): void {
		putenv( 'PLATFORM_APPLICATION_NAME=app' );
		putenv( 'PLATFORM_ENVIRONMENT=main' );
	}

	public function test_boot_is_a_noop_off_platform(): void {
		add_filter(
			'upsun_mu_modules',
			function () {
				return array( 'test' => UpsunTestModule::class );
			}
		);

		ModuleRegistry::boot();

		$this->assertSame( 0, UpsunTestModule::$registered );
	}

	public function test_boot_registers_modules_on_platform(): void {
		$this->on_platform();

		add_filter(
			'upsun_mu_modules',
			function () {
				return array( 'test' => UpsunTestModule::class );
			}
		);

		ModuleRegistry::boot();

		$this->assertSame( 1, UpsunTestModule::$registered );
	}

	public function test_should_load_false_prevents_registration(): void {
		$this->on_platform();

		add_filter(
			'upsun_mu_modules',
			function () {
				return array( 'disabled' => UpsunTestDisabledModule::class );
			}
		);

		ModuleRegistry::boot();

		$this->assertSame( 0, UpsunTestDisabledModule::$registered );
	}

	public function test_filter_can_remove_all_modules(): void {
		$this->on_platform();

		add_filter(
			'upsun_mu_modules',
			function () {
				return array();
			}
		);

		ModuleRegistry::boot();

		$this->assertSame( 0, UpsunTestModule::$registered );
	}

	public function test_status_reports_boot_outcomes(): void {
		$this->on_platform();

		add_filter(
			'upsun_mu_modules',
			function () {
				return array(
					'test'     => UpsunTestModule::class,
					'declined' => UpsunTestDisabledModule::class,
					'ghost'    => 'Upsun\\Does\\Not\\Exist',
				);
			}
		);

		ModuleRegistry::boot();
		$status = ModuleRegistry::status();

		$this->assertSame( 'loaded', $status['test']['state'] );
		$this->assertSame( 'declined', $status['declined']['state'] );
		$this->assertSame( 'missing', $status['ghost']['state'] );
		// Defaults absent from the filtered map are reported as removed.
		$this->assertSame( 'filter', $status['page-cache']['state'] );
	}

	/**
	 * The generic toggle 0.7 added. Its point is coverage: before it, only
	 * eight of thirteen modules had a filter-based off switch, so a consumer
	 * needing a conditional (per-environment) toggle for one of the other five
	 * had to fall back to a wp-config constant.
	 */
	public function test_module_enabled_filter_disables_by_id(): void {
		$this->on_platform();

		add_filter( 'upsun_modules', fn () => array( 'test' => UpsunTestModule::class ) );
		add_filter( 'upsun_module_enabled', fn ( $enabled, $id ) => 'test' !== $id, 10, 2 );

		ModuleRegistry::boot();

		$this->assertSame( 0, UpsunTestModule::$registered );
		$this->assertSame( 'disabled', ModuleRegistry::status()['test']['state'] );
	}

	public function test_module_enabled_filter_leaves_other_ids_alone(): void {
		$this->on_platform();

		add_filter( 'upsun_modules', fn () => array( 'test' => UpsunTestModule::class ) );
		add_filter( 'upsun_module_enabled', fn ( $enabled, $id ) => 'something-else' !== $id, 10, 2 );

		ModuleRegistry::boot();

		$this->assertSame( 1, UpsunTestModule::$registered );
	}

	/**
	 * One of the five modules that never had a per-module toggle, so this is
	 * the gap the generic filter closes.
	 */
	public function test_a_module_without_a_legacy_toggle_can_now_be_gated(): void {
		$this->on_platform();

		add_filter( 'upsun_module_enabled', fn ( $enabled, $id ) => 'page-cache' !== $id, 10, 2 );

		ModuleRegistry::boot();

		$this->assertSame( 'disabled', ModuleRegistry::status()['page-cache']['state'] );
		$this->assertSame( 'loaded', ModuleRegistry::status()['site-health']['state'] );
	}

	/**
	 * Documented precedence: the constant is read first and cannot be
	 * overridden by a filter that tries to switch the module back on.
	 */
	public function test_the_disable_constant_beats_the_enabled_filter(): void {
		$this->on_platform();

		define( 'UPSUN_DISABLE_FAKE_PRECEDENCE', true );

		add_filter( 'upsun_modules', fn () => array( 'fake-precedence' => UpsunTestModule::class ) );
		add_filter( 'upsun_module_enabled', '__return_true' );

		ModuleRegistry::boot();

		$this->assertSame( 0, UpsunTestModule::$registered );
		$this->assertSame( 'constant', ModuleRegistry::status()['fake-precedence']['state'] );
	}

	/**
	 * The renamed map filter: both names work, canonical last.
	 */
	public function test_the_module_map_filter_accepts_both_names(): void {
		$this->on_platform();

		add_filter( 'upsun_mu_modules', fn () => array( 'test' => UpsunTestModule::class ) );

		ModuleRegistry::boot();

		$this->assertSame( 1, UpsunTestModule::$registered );
		$this->assertArrayHasKey( 'upsun_mu_modules', $GLOBALS['upsun_test_deprecated'] );
	}

	public function test_status_reports_constant_disabled_modules(): void {
		$this->on_platform();

		define( 'UPSUN_DISABLE_FAKE_MODULE', true );

		add_filter(
			'upsun_mu_modules',
			function () {
				return array( 'fake-module' => UpsunTestModule::class );
			}
		);

		ModuleRegistry::boot();

		$this->assertSame( 0, UpsunTestModule::$registered );
		$this->assertSame( 'constant', ModuleRegistry::status()['fake-module']['state'] );
	}

	public function test_status_is_empty_when_boot_noops(): void {
		ModuleRegistry::boot(); // Off-platform.

		$this->assertSame( array(), ModuleRegistry::status() );
	}

	public function test_unknown_classes_are_skipped(): void {
		$this->on_platform();

		add_filter(
			'upsun_mu_modules',
			function () {
				return array(
					'ghost' => 'Upsun\\Does\\Not\\Exist',
					'test'  => UpsunTestModule::class,
				);
			}
		);

		ModuleRegistry::boot();

		$this->assertSame( 1, UpsunTestModule::$registered );
	}
}
