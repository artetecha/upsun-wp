# Upsun WordPress mu-plugin

Platform integration for WordPress running on [Upsun](https://upsun.com): environment awareness, router-cache friendliness, read-only-filesystem UX, Upsun-specific Site Health checks, and a `wp upsun` CLI command.

The plugin detects Upsun at runtime (`PLATFORM_APPLICATION_NAME` + `PLATFORM_ENVIRONMENT`) and **fully no-ops anywhere else** — local development and CI need no special-casing. It reads platform variables directly and never defines WordPress configuration constants: your `wp-config.php` stays the single owner of database credentials, URLs, salts, and `WP_ENVIRONMENT_TYPE`.

## Installation (Composer-managed WordPress)

```jsonc
// composer.json
{
  "repositories": [
    { "type": "path", "url": "packages/upsun-mu-plugin", "options": { "symlink": false } }
  ],
  "require": {
    "upsun/wordpress-mu-plugin": "*"
  },
  "extra": {
    "installer-paths": {
      "wordpress/wp-content/mu-plugins/{$name}": ["type:wordpress-muplugin"]
    }
  }
}
```

The package installs into `mu-plugins/upsun/`. WordPress does not scan mu-plugin subdirectories, so copy the loader shim to the mu-plugins root in your build, e.g. as a Composer script:

```json
"post-install-cmd": [
  "cp wordpress/wp-content/mu-plugins/upsun/upsun-loader.php wordpress/wp-content/mu-plugins/upsun-loader.php"
]
```

**Path-repository note:** with `symlink: false` and a pinned `version`, local edits to the package only propagate after `composer reinstall upsun/wordpress-mu-plugin` (or a version bump).

## Modules

| Module | What it does |
|---|---|
| `environment-indicator` | Color-coded admin-bar badge (branch · environment type) with an Upsun Console link, plus a dashboard widget with environment metadata. |
| `page-cache` | Emits `Cache-Control: public, max-age=0, s-maxage={ttl}` on anonymous, session-free page views so the Upsun router can cache them; optionally strips configured Set-Cookie headers (e.g. LMS guest sessions) to keep responses cacheable. |
| `updates-policy` | Disables the in-app auto-update machinery (the filesystem is read-only; Composer is the update path), replaces the auto-update toggles with a note, and removes the core Site Health tests that would fail by design. |
| `site-health` | Upsun-specific Site Health checks: object cache round-trip, cron configuration, writable mounts, preview search visibility; plus an "Upsun" section in the Info tab. |
| `preview-protection` | Sends `X-Robots-Tag: noindex, nofollow` and robots meta on non-production environments, without touching the `blog_public` option (the database is a production clone). |
| `smtp` | Points PHPMailer at the on-platform relay (`PLATFORM_SMTP_HOST`, port 25) unless a mailer plugin already configured SMTP. |

## Configuration

### Constants (wp-config friendly)

- `UPSUN_MU_DISABLE` — kill switch for the whole plugin.
- `UPSUN_DISABLE_ENVIRONMENT_INDICATOR`, `UPSUN_DISABLE_PAGE_CACHE`, `UPSUN_DISABLE_UPDATES_POLICY`, `UPSUN_DISABLE_SITE_HEALTH`, `UPSUN_DISABLE_PREVIEW_PROTECTION`, `UPSUN_DISABLE_SMTP` — per-module switches.
- `UPSUN_MU_FORCE` — boot modules off-platform (testing against faked `PLATFORM_*` variables).

### Filters

Module boot is deferred to `muplugins_loaded` priority 0, so **any mu-plugin** can register these regardless of load order.

| Filter | Type | Default | Purpose |
|---|---|---|---|
| `upsun_mu_modules` | `array<string, class-string>` | all modules | Add/remove/replace modules. |
| `upsun_page_cache_ttl` | `int` | `600` | Shared-cache TTL in seconds; `<= 0` disables the header. |
| `upsun_page_cache_bypass_cookie_patterns` | `string[]` | WP/Woo/session regexes | Cookie names that mark a request personalised. |
| `upsun_page_cache_strip_cookies` | `string[]` | `[]` | Cookie-name prefixes whose Set-Cookie headers are stripped from anonymous responses. |
| `upsun_page_cache_skip` | `bool` | `false` | Skip cache headers for the current request (plugin-specific dynamic pages). |
| `upsun_page_cache_debug_headers` | `bool` | `false` | Emit `X-Upsun-MU: page-cache` on cacheable responses. |
| `upsun_environment_indicator_enabled` | `bool` | `true` | Hide the admin-bar badge and widget. |
| `upsun_updates_notice_text` | `string` | "Updates are managed with Composer on Upsun." | The replacement auto-update copy. |
| `upsun_site_health_tests` | `array` | built-in checks | Add/remove health checks (shared with `wp upsun doctor`). |
| `upsun_preview_noindex` | `bool` | `true` | Disable noindex on non-production (e.g. an indexable staging domain). |
| `upsun_configure_smtp` | `bool` | `true` | Keep the plugin away from PHPMailer (a mailer plugin owns SMTP). |

### Helper functions

`Upsun\is_upsun()`, `Upsun\environment_name()`, `Upsun\environment_type()`, `Upsun\is_production()`, `Upsun\is_preview_environment()`, `Upsun\branch()`, `Upsun\project_id()`, `Upsun\application_name()`, `Upsun\primary_route()`, `Upsun\routes()`, `Upsun\relationship( string $name )`, `Upsun\version()` — all safe to call off-platform.

## WP-CLI

```
wp upsun info            # project / environment / branch / routes
wp upsun doctor          # health checks; exits 1 on failure (deploy-hook friendly)
wp upsun relationships   # service relationships (credentials never printed)
wp upsun cache flush     # object cache only — the router cache has no purge API
```

All commands print "Not running on Upsun." and exit 0 off-platform.

## Development

```
composer install
composer test   # PHPUnit, no WordPress install required
```

PHP floor is **8.1** (enforced in CI); tests are standalone with minimal WordPress function stubs.

## Roadmap (not in v0.1)

- Router/CDN cache purge helpers — blocked on a router purge API.
- Multisite awareness (see [upsun/wp-ms-dbu](https://github.com/upsun/wp-ms-dbu) for domain rewriting).
- Plugin compatibility layer; maintenance mode; deploy/build introspection.
