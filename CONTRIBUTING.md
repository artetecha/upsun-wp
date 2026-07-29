# Contributing

Thanks for looking. This is a small, opinionated package: a **generic** platform
plugin for WordPress on Upsun. The most useful contributions are ones that keep
it generic.

- **Security issues**: do not open an issue — see [SECURITY.md](SECURITY.md).
- **The public API**: [`docs/api-reference.md`](docs/api-reference.md) is
  generated from the source and is authoritative.
- **Design decisions and accepted risks**:
  [`docs/threat-model.md`](docs/threat-model.md),
  [`docs/api-audit-1.0.md`](docs/api-audit-1.0.md), [ROADMAP.md](ROADMAP.md).

## The one rule

**Site-specific behaviour does not belong here.** This package is consumed by
unrelated WordPress projects on Upsun; anything true of only one site belongs in
that site's own mu-plugin, wired through the public filters. If you need
something this plugin does not expose, the contribution is usually *a filter*,
not a special case.

Two corollaries that shape the codebase:

- **Every built-in integration contributes exclusively through the public
  filters** — never through privileged internal calls. That is deliberate: each
  one doubles as proof the public API is sufficient for a consumer to do the same.
- **Honest about the platform.** No feature that pretends an API exists.
  Limitations get documented (see the router-purge story in the roadmap) rather
  than papered over with a runtime workaround.

## Getting set up

```bash
composer install
composer test          # unit suite, no WordPress needed
```

The integration suite needs a database; the quickest one is a container:

```bash
docker run --rm -d -p 3306:3306 -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE=wp --name upsun-wp-db mariadb:11
bash tests/integration/run.sh
docker rm -f upsun-wp-db
```

`WP_CORE` picks the WordPress version (`6.0.*`, `^7.0`, …) and `KEEP=1` leaves
the install in place for inspection. CI runs the unit suite on PHP 8.1–8.5 and
the harness across five curated PHP × WordPress corners, including the 6.0/8.1
floor.

## Adding a module

A module is a self-contained platform behaviour. Implement
[`Upsun\Module`](src/Module.php) and register it in
[`ModuleRegistry`](src/ModuleRegistry.php)'s map.

```php
namespace Upsun\Modules;

use Upsun\Module;

class Example implements Module {

	public function should_load(): bool {
		// Runs at muplugins_loaded priority 0 — before regular plugins load.
		// Pluggable functions are NOT available here. Boot gating by constant
		// and by the upsun_module_enabled filter is the registry's job, so
		// return true unless the module has an intrinsic reason not to load
		// (PreviewProtection, for instance, returns false on production).
		return true;
	}

	public function register(): void {
		// Register hooks only. Behaviour goes in the callbacks, so it runs
		// after all plugins have loaded.
		add_action( 'send_headers', array( $this, 'send' ) );
		add_filter( 'upsun_site_health_tests', array( $this, 'add_check' ) );
		add_filter( 'upsun_dashboard_panels', array( $this, 'add_panel' ) );
	}
}
```

Then add `'example' => Modules\Example::class` to `ModuleRegistry::MODULES`. The
id is the public handle: it produces `UPSUN_DISABLE_EXAMPLE` and is what
`upsun_module_enabled` receives. Register the file in
[`upsun.php`](upsun.php)'s require list.

What a module should also do:

- **Contribute a check** to `upsun_site_health_tests` if it can be
  misconfigured. One registry backs Site Health, `wp upsun doctor`, and the
  dashboard, so one callback gets you all three.
- **Keep decisions pure.** The pattern throughout is a `static` function over
  `$_SERVER`-shaped input (`SecurityHeaders::headers()`,
  `PageCache::is_cacheable_request()`) with the module doing the I/O around it.
  That is what makes the unit suite possible without WordPress.
- **Never write options for configuration.** Configuration lives in code —
  constants and filters — so it survives an environment clone and is versioned.

## Adding an integration

An integration is everything this plugin knows about **one** third-party plugin.
Implement [`Upsun\Integration`](src/Integration.php).

```php
class ExamplePlugin implements Integration {

	public function label(): string {
		return 'Example Plugin';
	}

	public function is_active(): bool {
		// Reporting only — call at render time, never to gate registration.
		return class_exists( 'Example_Plugin' );
	}

	public function register(): void {
		// Runs before regular plugins load, so every callback must no-op when
		// the target plugin is absent. Contribute through public filters only.
		add_filter( 'upsun_page_cache_bypass_cookie_patterns', array( $this, 'cookies' ) );
		add_filter( 'upsun_writable_paths_requirements', array( $this, 'paths' ) );
	}
}
```

Add it to `IntegrationRegistry::INTEGRATIONS`. The id yields
`UPSUN_DISABLE_INTEGRATION_EXAMPLE_PLUGIN` and is passed to
`upsun_integration_enabled`.

An integration is worth adding here when the plugin is common across
WordPress-on-Upsun sites *and* the knowledge is generic (its cookie names, where
it writes, what must be neutered on a preview). Anything about how *your* site
configures it is site-specific.

## Adding a fetcher

A fetcher answers one question for the vendoring engine: for this slug, is there
a newer version, and where is its authenticated download? Implement
[`Upsun\Fetcher`](src/Fetcher.php), and optionally
[`Upsun\FetcherStatus`](src/FetcherStatus.php) so it can describe itself in
`doctor`/Site Health/the dashboard.

```php
add_filter( 'upsun_vendor_fetchers', function ( array $fetchers ) {
	$fetchers[] = new My_Vendor_Fetcher();   // first supports() match wins
	return $fetchers;
} );
```

**The load-bearing contract:** a fetcher reads credentials from the site's own
stored state — the update transient a licensed updater already populated, or the
vendor's registration row — **never** from an environment variable or committed
config. That is what makes "tokens never leave the container" true for free: the
secret is already in the (cloned) database and nothing is injected from outside.

Gate `supports()` narrowly, so an unrelated slug falls through to the built-in
`TransientFetcher`. And read
[the vendoring section of the threat model](docs/threat-model.md#privileged-surface-1--the-vendoring-engine)
first: this is the one path where hostile input becomes committed, executed code.

## Tests

- **Unit** (`tests/`): one file per module or subsystem, against the WordPress
  stubs in `tests/bootstrap.php`. Add stubs there rather than in a test file —
  a guarded per-file stub silently stops taking effect once the bootstrap grows
  one, which has already happened once.
- **Integration** (`tests/integration/`): assertions that need real WordPress —
  DB writes, hook registration under real core, deprecation notices, response
  headers. Dependency-free helpers in `lib.php`; the scripts run through
  `wp eval-file`.
- **Structural guards** are used where a convention matters more than any single
  case: `FilterApplicationTest` (each centralized filter applied exactly once) and
  `ApiReferenceTest` (the reference is not stale). If you add a convention, add
  its guard.

New behaviour needs a test that fails without it. If a bug is fixed, the test
should be one that would have caught it — and it is worth checking that it does,
by stashing the fix.

## Changing the public API

The surface is documented in [`docs/api-reference.md`](docs/api-reference.md) and
frozen at 1.0. Before then:

1. **Adding** a filter, an array key, a JSON field, or an optional flag is
   additive and fine.
2. **Renaming or removing** one is a **major-version change** now that the API is
   frozen. The mechanism, when that time comes, is the one 0.7 used and 1.0
   removed: apply the canonical name, feed it the result of
   `apply_filters_deprecated( 'old_name', … )` so an existing callback still
   decides, and keep the pair in one place so there is a single file to delete at
   the removal. Look at the 0.7 tag for the shape (`src/Deprecations.php`), and
   read the [policy](docs/api-reference.md#deprecation-policy) for the timing.
3. **Regenerate the reference** — `php bin/api-reference.php` — and commit it.
   Document every filter with a docblock stating its `@param` type and
   `Default …`; the reference is built from those, so an undocumented filter
   shows up as an empty row.

## Pull requests

- Branch from `main`; CI must be green (unit matrix + the five integration
  corners).
- Explain **why** in the commit message and the PR body, not just what. If you
  made a judgement call, say what you rejected and why — that is the part a
  reviewer cannot reconstruct.
- Keep unrelated changes out. A drive-by fix in the same PR is fine if you name
  it; a drive-by refactor is not.
- Update the docs in the same PR: [README](README.md) for consumer-facing
  behaviour, [CHANGELOG.md](CHANGELOG.md) under the unreleased heading, and the
  generated reference if the API moved.

Releases are tags on `main` (`0.x.y`, annotated, with a summary), picked up
automatically by Packagist. The version lives in `UPSUN_MU_PLUGIN_VERSION` and
must be bumped in the same PR as the change it describes.
