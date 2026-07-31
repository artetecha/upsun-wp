<?php
/**
 * Generate docs/api-reference.md from the source.
 *
 *   php bin/api-reference.php           # write the reference
 *   php bin/api-reference.php --check   # fail if the committed file is stale
 *
 * The reference is generated rather than hand-maintained because the docblocks
 * are already the authoritative description of every filter, and a second
 * hand-written copy of ~130 symbols would drift within a release.
 * ApiReferenceTest runs --check, so a change to the surface that skips
 * regeneration fails the suite.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root   = dirname( __DIR__ );
$check  = in_array( '--check', array_slice( $argv, 1 ), true );
$target = $root . '/docs/api-reference.md';

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Every PHP file under src/, keyed by repo-relative path. */
function sources( string $root ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

	foreach ( $iterator as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$path           = str_replace( $root . '/', '', $file->getPathname() );
			$files[ $path ] = file_get_contents( $file->getPathname() );
		}
	}

	ksort( $files );

	return $files;
}

/**
 * The docblock immediately above $offset, as raw lines without the markers.
 *
 * @return string[]
 */
function docblock_above( string $source, int $offset ): array {
	$before = substr( $source, 0, $offset );
	$open   = strrpos( $before, '/**' );
	$close  = strrpos( $before, '*/' );

	if ( false === $open || false === $close || $close < $open ) {
		return array();
	}

	// The block must be adjacent: only the opening of the statement it
	// documents may sit between them. A `;`, `{` or `}` means a complete
	// statement intervened, so the block belongs to something else.
	$between = trim( substr( $before, $close + 2 ) );

	if ( preg_match( '/[;{}]/', $between ) ) {
		return array();
	}

	$block = substr( $before, $open, $close - $open );
	$lines = array();

	foreach ( explode( "\n", $block ) as $line ) {
		$line = trim( preg_replace( '#^\s*(/\*\*|\*)#', '', $line ) );

		if ( '' !== $line ) {
			$lines[] = $line;
		}
	}

	return $lines;
}

/** The prose part of a docblock as one line, @tags dropped. */
function summary( array $lines ): string {
	$prose = array();

	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, '@' ) || 0 === strpos( $line, '##' ) ) {
			break;
		}

		$prose[] = $line;
	}

	return preg_replace( '/\s+/', ' ', trim( implode( ' ', $prose ) ) );
}

/** The "Default x." hint from an @param line, if the docblock states one. */
function default_hint( array $lines ): string {
	foreach ( $lines as $line ) {
		if ( preg_match( '/@param\s+.+?\s+\$\w+\s+Default:?\s+(.+?)\.?$/i', $line, $m ) ) {
			return trim( $m[1] );
		}
	}

	return '';
}

/** The first @param type of a docblock, as the filtered value's type. */
function value_type( array $lines ): string {
	foreach ( $lines as $line ) {
		if ( preg_match( '/@param\s+(.+?)\s+\$\w+/', $line, $m ) ) {
			return $m[1];
		}
	}

	return '';
}

function md_escape( string $text ): string {
	return str_replace( '|', '\|', $text );
}

/* -------------------------------------------------------------------------
 * Extract
 * ---------------------------------------------------------------------- */

$files   = sources( $root );
$version = '0.0.0-dev';

if ( preg_match( "/UPSUN_MU_PLUGIN_VERSION', '([^']+)'/", file_get_contents( $root . '/upsun.php' ), $m ) ) {
	$version = $m[1];
}

$filters    = array();
$actions    = array();
$deprecated = array();

foreach ( $files as $path => $source ) {
	// apply_filters( 'name', … ) including the multi-line form.
	if ( preg_match_all( "/apply_filters\(\s*\n?\s*'([a-z0-9_]+)'/", $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $match ) {
			$lines = docblock_above( $source, $match[1] );

			$filters[ $match[0] ] = array(
				'file'    => $path,
				'type'    => value_type( $lines ),
				'default' => default_hint( $lines ),
				'summary' => summary( $lines ),
			);
		}
	}

	// Deprecations::filter( 'canonical', 'old', … ): the canonical name is the
	// public one; the old name goes in the deprecation table.
	if ( preg_match_all( "/Deprecations::filter\(\s*\n?\s*'([a-z0-9_]+)',\s*\n?\s*'([a-z0-9_]+)'/", $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $i => $match ) {
			$lines = docblock_above( $source, $match[1] );

			$filters[ $match[0] ] = array(
				'file'    => $path,
				'type'    => value_type( $lines ),
				'default' => default_hint( $lines ),
				'summary' => summary( $lines ),
			);

			$deprecated[ $matches[2][ $i ][0] ] = $match[0];
		}
	}

	// do_action( self::CONST, … ) — resolve the constant to its literal.
	if ( preg_match_all( '/do_action\(\s*self::([A-Z_]+)/', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $match ) {
			if ( preg_match( "/const\s+{$match[0]}\s*=\s*'([a-z0-9_]+)'/", $source, $const ) ) {
				$lines = docblock_above( $source, $match[1] );

				$actions[ $const[1] ] = array(
					'file'    => $path,
					'summary' => summary( $lines ),
				);
			}
		}
	}
}

// Deprecated names, when there are any: the per-registry toggle predecessors in
// ModuleRegistry::TOGGLES and the rename map in Deprecations::RENAMED. Both are
// optional — 1.0 removed the shim layer, and a release with nothing deprecated
// simply omits the table.
if ( isset( $files['src/ModuleRegistry.php'] )
	&& preg_match_all( "/'([a-z-]+)'\s*=>\s*'(upsun_[a-z_]+_enabled)'/", $files['src/ModuleRegistry.php'], $matches, PREG_SET_ORDER ) ) {
	foreach ( $matches as $match ) {
		$deprecated[ $match[2] ] = 'upsun_module_enabled';
	}
}

if ( isset( $files['src/Deprecations.php'] )
	&& preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*'(upsun_[a-z0-9_]+)',/", $files['src/Deprecations.php'], $matches, PREG_SET_ORDER ) ) {
	foreach ( $matches as $match ) {
		$deprecated[ $match[1] ] = $match[2];
	}
}

ksort( $filters );
ksort( $actions );
ksort( $deprecated );

/** Registry ids, so the enumerated constant names follow the code. */
function registry_ids( string $source ): array {
	$ids = array();

	if ( preg_match( '/private const (?:MODULES|INTEGRATIONS)\s*=\s*array\((.*?)\);/s', $source, $m )
		&& preg_match_all( "/'([a-z0-9-]+)'\s*=>/", $m[1], $found ) ) {
		$ids = $found[1];
	}

	return $ids;
}

$modules      = registry_ids( $files['src/ModuleRegistry.php'] );
$integrations = registry_ids( $files['src/IntegrationRegistry.php'] );
$fetchers     = array( 'thimpress', 'transient' );

/* Interfaces: methods and their one-line summaries. */
$interfaces = array();

foreach ( array( 'Module', 'Integration', 'Fetcher', 'FetcherStatus' ) as $name ) {
	$source  = $files[ "src/{$name}.php" ];
	$methods = array();

	if ( preg_match_all( '/public function (\w+\([^)]*\)(?::\s*\S+)?);/', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $match ) {
			$methods[] = array(
				'signature' => preg_replace( '/\s+/', ' ', $match[0] ),
				'summary'   => summary( docblock_above( $source, $match[1] ) ),
			);
		}
	}

	$interfaces[ $name ] = array(
		'summary' => summary( docblock_above( $source, strpos( $source, "interface {$name}" ) ) ),
		'methods' => $methods,
	);
}

/* Helper functions. */
$helpers = array();

if ( preg_match_all( '/\tfunction (\w+\([^)]*\)(?::\s*\S+)?)\s*\{/', $files['src/helpers.php'], $matches, PREG_OFFSET_CAPTURE ) ) {
	foreach ( $matches[1] as $match ) {
		$helpers[] = array(
			'signature' => 'Upsun\\' . preg_replace( '/\s+/', ' ', $match[0] ),
			'summary'   => summary( docblock_above( $files['src/helpers.php'], $match[1] ) ),
		);
	}
}

/* WP-CLI: subcommand, summary, flags, and the --format=json field list. */
$cli    = array();
$source = $files['src/Cli/UpsunCommand.php'];

if ( preg_match_all( '/\tpublic function (\w+)\( \$args, \$assoc_args \)/', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
	$offsets = array();

	foreach ( $matches[1] as $i => $match ) {
		$offsets[] = array( 'name' => $match[0], 'offset' => $match[1] );
	}

	foreach ( $offsets as $i => $method ) {
		$lines = docblock_above( $source, $method['offset'] );
		$block = implode( "\n", $lines );
		$next  = $offsets[ $i + 1 ]['offset'] ?? strlen( $source );
		$body  = substr( $source, $method['offset'], $next - $method['offset'] );

		// @subcommand wins; otherwise underscores become dashes.
		$name = preg_match( '/@subcommand\s+(\S+)/', $block, $m )
			? $m[1]
			: str_replace( '_', '-', $method['name'] );

		preg_match_all( '/^\[?(--[a-z][a-z-]*)/m', $block, $flags );
		preg_match_all( '/^(<[a-z-]+>|\[<[a-z-]+>\])$/m', $block, $args );

		$fields = array();

		if ( preg_match_all( "/format_items\(\s*[^;]*?array\(\s*((?:'[a-z_]+',?\s*)+)\)/s", $body, $found ) ) {
			foreach ( $found[1] as $list ) {
				preg_match_all( "/'([a-z_]+)'/", $list, $keys );
				$fields = array_merge( $fields, $keys[1] );
			}
		}

		// The vendor command emits whitelisted payloads instead of a table.
		if ( 'vendor' === $name ) {
			$fields = array( 'slug', 'type', 'from', 'to', 'fetcher', 'files (update path only)' );
		}

		$cli[ $name ] = array(
			'summary' => summary( $lines ),
			'args'    => $args[1],
			'flags'   => array_values( array_unique( $flags[1] ) ),
			'json'    => array_values( array_unique( $fields ) ),
		);
	}
}

ksort( $cli );

/* -------------------------------------------------------------------------
 * Render
 * ---------------------------------------------------------------------- */

$out   = array();
$out[] = '<!-- Generated by bin/api-reference.php. Do not edit by hand. -->';
$out[] = '';
$out[] = '# API reference';
$out[] = '';
$out[] = sprintf(
	'The public surface of **upsun-wp %s**, generated from the source. Everything listed here is API: it does not change without a deprecation cycle (see [the policy](#deprecation-policy)). Anything in `src/` **not** listed here is `@internal` and may change in any release.',
	$version
);
$out[] = '';
$out[] = sprintf( '%d filters · %d action · %d helper functions · %d interfaces · %d WP-CLI subcommands', count( $filters ), count( $actions ), count( $helpers ), count( $interfaces ), count( $cli ) );
$out[] = '';

$out[] = '## Filters';
$out[] = '';
$out[] = '| Filter | Value | Default | Purpose |';
$out[] = '|---|---|---|---|';

foreach ( $filters as $name => $filter ) {
	$out[] = sprintf(
		'| `%s` | %s | %s | %s |',
		$name,
		'' !== $filter['type'] ? '`' . md_escape( $filter['type'] ) . '`' : '—',
		'' !== $filter['default'] ? md_escape( $filter['default'] ) : '—',
		md_escape( $filter['summary'] )
	);
}

$out[] = '';
$out[] = '## Actions';
$out[] = '';
$out[] = '| Action | When it fires |';
$out[] = '|---|---|';

foreach ( $actions as $name => $action ) {
	$out[] = sprintf( '| `%s` | %s |', $name, md_escape( $action['summary'] ) );
}

$out[] = '';
$out[] = '## Constants';
$out[] = '';
$out[] = 'Read at boot, so they belong in `wp-config.php`. A constant is read before its filter counterpart and wins.';
$out[] = '';
$out[] = '| Constant | Effect |';
$out[] = '|---|---|';
$out[] = '| `UPSUN_MU_DISABLE` | Kill switch: neither registry boots, the helper API stays loaded. |';
$out[] = '| `UPSUN_MU_FORCE` | Boot off-platform, against faked `PLATFORM_*` variables (local and CI). |';
$out[] = '| `UPSUN_MIGRATIONS_DIR` | Directory of deploy migrations; unset leaves the feature idle. |';
$out[] = '| `UPSUN_MU_PLUGIN_DIR` | Defined by the plugin: its own directory. |';
$out[] = '| `UPSUN_MU_PLUGIN_VERSION` | Defined by the plugin: its version (also `Upsun\version()`). |';
$out[] = '';
$out[] = sprintf( '**`UPSUN_DISABLE_{MODULE}`** — one per module: %s.', '`' . implode( '`, `', array_map( static fn ( $id ) => 'UPSUN_DISABLE_' . strtoupper( str_replace( '-', '_', $id ) ), $modules ) ) . '`' );
$out[] = '';
$out[] = sprintf( '**`UPSUN_DISABLE_INTEGRATION_{ID}`** — one per integration: %s.', '`' . implode( '`, `', array_map( static fn ( $id ) => 'UPSUN_DISABLE_INTEGRATION_' . strtoupper( str_replace( '-', '_', $id ) ), $integrations ) ) . '`' );
$out[] = '';
$out[] = sprintf( '**`UPSUN_DISABLE_FETCHER_{ID}`** — one per built-in fetcher: %s. Disabling `TRANSIENT` removes the universal fallback, so only packages a specific fetcher claims can be resolved.', '`' . implode( '`, `', array_map( static fn ( $id ) => 'UPSUN_DISABLE_FETCHER_' . strtoupper( $id ), $fetchers ) ) . '`' );
$out[] = '';

$out[] = '## Helper functions';
$out[] = '';
$out[] = 'The documented facade over the environment. All safe to call off-platform, where they return `false`/`null`/`array()`. `Upsun\Environment` itself is `@internal` — these are the contract.';
$out[] = '';
$out[] = '| Function | Returns |';
$out[] = '|---|---|';

foreach ( $helpers as $helper ) {
	$out[] = sprintf( '| `%s` | %s |', $helper['signature'], '' !== $helper['summary'] ? md_escape( $helper['summary'] ) : '—' );
}

$out[] = '';
$out[] = '## Extension interfaces';
$out[] = '';

foreach ( $interfaces as $name => $interface ) {
	$out[] = sprintf( '### `Upsun\%s`', $name );
	$out[] = '';

	if ( '' !== $interface['summary'] ) {
		$out[] = md_escape( $interface['summary'] );
		$out[] = '';
	}

	foreach ( $interface['methods'] as $method ) {
		$out[] = sprintf( '- `%s` — %s', $method['signature'], '' !== $method['summary'] ? $method['summary'] : 'see the source.' );
	}

	$out[] = '';
}

$out[] = 'Registering an implementation: `upsun_modules`, `upsun_integrations`, `upsun_vendor_fetchers`. See [CONTRIBUTING.md](../CONTRIBUTING.md) for worked examples.';
$out[] = '';

$out[] = '## WP-CLI';
$out[] = '';
$out[] = 'The subcommand names, their positional arguments and flags, and the field names emitted by `--format=json`. **Field names are contract; new keys may be added in a minor release, so consumers must tolerate unknown keys.**';
$out[] = '';

foreach ( $cli as $name => $command ) {
	$signature = trim( 'wp upsun ' . $name . ' ' . implode( ' ', $command['args'] ) );

	$out[] = sprintf( '### `%s`', $signature );
	$out[] = '';

	if ( '' !== $command['summary'] ) {
		$out[] = md_escape( $command['summary'] );
		$out[] = '';
	}

	if ( array() !== $command['flags'] ) {
		$out[] = sprintf( 'Flags: %s', '`' . implode( '`, `', $command['flags'] ) . '`' );
		$out[] = '';
	}

	if ( array() !== $command['json'] ) {
		$out[] = sprintf( 'JSON fields: %s', '`' . implode( '`, `', $command['json'] ) . '`' );
		$out[] = '';
	}
}

$out[] = '## Deprecation policy';
$out[] = '';
$out[] = 'Pre-1.0, the newest minor carries fixes and breaking changes are documented per release. From 1.0:';
$out[] = '';
$out[] = '- a public symbol is **deprecated in a minor**, never removed in one;';
$out[] = '- removal happens in the **next major**, with at least two minors of overlap;';
$out[] = '- filters deprecate through `apply_filters_deprecated()`, actions through `do_action_deprecated()`, functions and methods through `_deprecated_function()`, and constants by continuing to be honoured plus a `wp upsun doctor` notice (core has no helper for constants);';
$out[] = '- **additive and therefore not breaking:** new filters, new keys in a filtered array, new keys in `--format=json` output, new optional CLI flags, new modules/integrations/fetchers in the default registries;';
$out[] = '- **breaking, therefore major-only:** removing or renaming a listed symbol, changing a filter\'s parameter order or types, changing a default in a way that changes behaviour, removing a JSON key, raising the PHP or WordPress floor.';
$out[] = '';

if ( array() === $deprecated ) {
	$out[] = '### Currently deprecated';
	$out[] = '';
	$out[] = 'Nothing. The pre-1.0 renames were removed at 1.0 and no symbol is deprecated in this release.';
	$out[] = '';
} else {
	$out[] = '### Currently deprecated';
	$out[] = '';
	$out[] = 'Still honoured, removed at 1.0. Each emits a notice through `_deprecated_hook()` when a callback is attached and `WP_DEBUG` is on.';
	$out[] = '';
	$out[] = '| Deprecated | Replacement |';
	$out[] = '|---|---|';

	foreach ( $deprecated as $old => $new ) {
		$out[] = sprintf( '| `%s` | `%s` |', $old, $new );
	}

	$out[] = '';
}

$out[] = '## Supported versions';
$out[] = '';
$out[] = 'PHP **8.1–8.5** × WordPress **6.0+**, enforced in CI. Raising either floor is a breaking change.';
$out[] = '';
$out[] = 'Security reporting: [SECURITY.md](../SECURITY.md). Privileged surfaces and accepted risks: [threat-model.md](threat-model.md).';

$rendered = implode( "\n", $out ) . "\n";

if ( $check ) {
	$current = is_file( $target ) ? file_get_contents( $target ) : '';

	if ( $current === $rendered ) {
		echo "docs/api-reference.md is up to date.\n";
		exit( 0 );
	}

	fwrite( STDERR, "docs/api-reference.md is stale — run: php bin/api-reference.php\n" );
	exit( 1 );
}

file_put_contents( $target, $rendered );

printf(
	"Wrote %s — %d filters, %d action(s), %d helpers, %d interfaces, %d subcommands, %d deprecated names.\n",
	'docs/api-reference.md',
	count( $filters ),
	count( $actions ),
	count( $helpers ),
	count( $interfaces ),
	count( $cli ),
	count( $deprecated )
);
