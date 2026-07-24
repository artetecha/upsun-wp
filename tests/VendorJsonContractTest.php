<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Cli/UpsunCommand.php';

/**
 * The --format=json vendor output is a security boundary: it must never carry
 * the resolved download url/headers (they hold the license token). These lock
 * the reducers to an explicit field whitelist so a future rename or an
 * accidental secret-field addition fails the suite instead of leaking.
 */
final class VendorJsonContractTest extends TestCase {

	private function reduce( string $method, array $arg ): array {
		// Reflection can invoke private methods directly since PHP 8.1 (the
		// plugin's floor), so no setAccessible() is needed.
		$m = new ReflectionMethod( \Upsun\Cli\UpsunCommand::class, $method );

		return (array) $m->invoke( null, $arg );
	}

	public function test_plan_public_whitelists_fields_and_drops_secrets(): void {
		$out = $this->reduce(
			'plan_public',
			array(
				'slug'    => 'eduma',
				'type'    => 'theme',
				'from'    => '5.9.3',
				'to'      => '5.9.4',
				'fetcher' => 'thimpress',
				'url'     => 'https://updates.thimpress.com/download?token=SECRET',
				'headers' => array( 'Authorization' => 'Bearer SECRET' ),
			)
		);

		$this->assertSame( array( 'slug', 'type', 'from', 'to', 'fetcher' ), array_keys( $out ) );
		$this->assertArrayNotHasKey( 'url', $out );
		$this->assertArrayNotHasKey( 'headers', $out );
		$this->assertStringNotContainsString( 'SECRET', (string) json_encode( $out ) );
	}

	public function test_result_public_whitelists_fields_and_drops_secrets(): void {
		$out = $this->reduce(
			'result_public',
			array(
				'slug'    => 'eduma',
				'type'    => 'theme',
				'from'    => '5.9.3',
				'to'      => '5.9.4',
				'fetcher' => 'thimpress',
				'files'   => 692,
				'path'    => '/tmp/keds-vendor/eduma',
				// Defensive: even if a secret ever reached a result, it must not survive.
				'url'     => 'https://x/download?token=SECRET',
				'headers' => array( 'Authorization' => 'SECRET' ),
			)
		);

		$this->assertSame( array( 'slug', 'type', 'from', 'to', 'fetcher', 'files' ), array_keys( $out ) );
		$this->assertSame( 692, $out['files'] );
		$this->assertStringNotContainsString( 'SECRET', (string) json_encode( $out ) );
	}
}
