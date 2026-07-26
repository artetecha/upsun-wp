<?php

use PHPUnit\Framework\TestCase;

/**
 * docs/api-reference.md is generated from the source, so it can only stay true
 * if regenerating it is a no-op. This runs the generator's --check mode, which
 * fails when the committed file differs from what the current source produces —
 * the drift guard that makes a generated reference worth more than a
 * hand-written one.
 */
final class ApiReferenceTest extends TestCase {

	public function test_the_committed_reference_matches_the_source(): void {
		$root = dirname( __DIR__ );

		$output = array();
		$status = 0;

		exec(
			sprintf( '%s %s --check 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $root . '/bin/api-reference.php' ) ),
			$output,
			$status
		);

		$this->assertSame(
			0,
			$status,
			"docs/api-reference.md is stale. Run: php bin/api-reference.php\n" . implode( "\n", $output )
		);
	}

	/**
	 * The reference is only authoritative if it covers the whole surface, so
	 * spot-check the counts it claims against the source rather than trusting
	 * the generator's own arithmetic.
	 */
	public function test_it_documents_every_filter_applied_in_src(): void {
		$root      = dirname( __DIR__ );
		$reference = file_get_contents( $root . '/docs/api-reference.md' );
		$sources   = '';

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$sources .= file_get_contents( $file->getPathname() );
			}
		}

		preg_match_all( "/apply_filters\(\s*\n?\s*'([a-z0-9_]+)'/", $sources, $applied );
		preg_match_all( "/Deprecations::filter\(\s*\n?\s*'([a-z0-9_]+)'/", $sources, $shimmed );

		$names   = array_unique( array_merge( $applied[1], $shimmed[1] ) );
		$missing = array();

		foreach ( $names as $name ) {
			// apply_filters_deprecated() call sites carry the old name, which
			// belongs in the deprecation table rather than the filter table.
			if ( false === strpos( $reference, '`' . $name . '`' ) ) {
				$missing[] = $name;
			}
		}

		$this->assertSame( array(), $missing, 'Filters applied in src/ but absent from the reference.' );
	}

	/**
	 * The version comes from upsun.php rather than the constant: the unit
	 * bootstrap deliberately does not define UPSUN_MU_PLUGIN_VERSION (so
	 * Upsun\version() has an untagged-checkout path to exercise).
	 */
	public function test_it_states_the_version_it_documents(): void {
		$root      = dirname( __DIR__ );
		$reference = file_get_contents( $root . '/docs/api-reference.md' );

		$this->assertSame(
			1,
			preg_match( "/UPSUN_MU_PLUGIN_VERSION', '([^']+)'/", file_get_contents( $root . '/upsun.php' ), $matches )
		);

		$this->assertStringContainsString(
			'upsun-wp ' . $matches[1],
			$reference,
			'The reference header must name the current plugin version.'
		);
	}
}
