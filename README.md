# Upsun WordPress mu-plugin

Platform integration for WordPress running on [Upsun](https://upsun.com): environment awareness, router-cache friendliness, read-only-filesystem UX, Upsun-specific Site Health checks, and a `wp upsun` CLI command.

The plugin detects Upsun at runtime (`PLATFORM_APPLICATION_NAME` + `PLATFORM_ENVIRONMENT`) and **fully no-ops anywhere else** — local development and CI need no special-casing. It reads platform variables directly and never defines WordPress configuration constants: your `wp-config.php` stays the single owner of database credentials, URLs, salts, and `WP_ENVIRONMENT_TYPE`.

This is a generic plugin for any WordPress project on Upsun. It currently lives inside the KEDS repository, which acts as its first consumer and test bed, and will be extracted to an independent repository as it stabilizes (see [ROADMAP.md](ROADMAP.md)). Site-specific behavior belongs in the consuming project via the filters below — never in this package.

## Installation (Composer-managed WordPress)

Three steps: require the package, copy the loader shim, and wire the
post_deploy hook.

**1. Require the package**

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

**2. Copy the loader shim.** The package installs into `mu-plugins/upsun/`, and WordPress does not scan mu-plugin subdirectories, so copy the shim to the mu-plugins root in your build, e.g. as a Composer script:

```json
"post-install-cmd": [
  "cp wordpress/wp-content/mu-plugins/upsun/upsun-loader.php wordpress/wp-content/mu-plugins/upsun-loader.php"
]
```

**Path-repository note:** with `symlink: false` and a pinned `version`, local edits to the package only propagate after `composer reinstall upsun/wordpress-mu-plugin` (or a version bump).

**3. Wire preview sanitize into the post_deploy hook.** Data syncs redeploy an environment **without a code change, so only the `post_deploy` hook runs** — `deploy` does not, which makes `post_deploy` the only hook that can catch every clone and resync. Add one line to `.upsun/config.yaml` that is safe on every environment (production refreshes the stamp that makes its clones detectable; already-sanitized previews no-op):

```yaml
hooks:
  post_deploy: |
    wp upsun sanitize --if-needed
```

Skipping this step does **not** weaken the runtime preview protections (mail interception, payment test mode, webhook pausing are active on every preview request from boot) — it only means the one-time `upsun_preview_sanitize` consumer actions never fire. The "Preview safety" health check (Site Health, the Upsun dashboard, `wp upsun doctor`) warns on every environment until the wiring is in place. If you cannot edit your hooks, enable the per-boot fallback via the `upsun_safe_previews_boot_check` filter.

## Modules

| Module | What it does |
|---|---|
| `environment-indicator` | Color-coded admin-bar badge (branch · environment type) with an Upsun Console link, a dashboard widget with environment metadata, and a matching banner on the login screen. |
| `page-cache` | Emits `Cache-Control: public, max-age=0, s-maxage={ttl}` on anonymous, session-free page views so the Upsun router can cache them; optionally strips configured Set-Cookie headers (e.g. LMS guest sessions) to keep responses cacheable. Built-in bypass patterns cover core session cookies; commerce patterns come from the Integrations layer. `wp upsun cache-check <url>` (also a form in the dashboard Caching panel) explains any page's verdict: effective TTL, Set-Cookie spoilers, bypass-pattern matches, the route cookie allowlist (declared via `upsun_cache_check_route_cache` — Upsun does not expose it at runtime), and whether the fetch was a router HIT/MISS/BYPASS. |
| `updates-policy` | Disables the in-app auto-update machinery (the filesystem is read-only; Composer is the update path), replaces the auto-update toggles with a note, and removes the core Site Health tests that would fail by design. |
| `site-health` | Upsun-specific Site Health checks: object cache round-trip, cron configuration, writable mounts, preview search visibility; plus an "Upsun" section in the Info tab. |
| `preview-protection` | Sends `X-Robots-Tag: noindex, nofollow` and robots meta on non-production environments, without touching the `blog_public` option (the database is a production clone). |
| `smtp` | Points PHPMailer at the on-platform relay (`PLATFORM_SMTP_HOST`, port 25) unless a mailer plugin already configured SMTP. |
| `dashboard` | A top-level "Upsun" page in wp-admin (`manage_options`) styled like the WP Dashboard: panels are real meta boxes in the core dashboard grid — collapsible, draggable between columns, layout persisted per user. Panels: environment, services (credentials never rendered), health checks, resolved caching config, module status; plus operational actions (flush object cache). Extensible via `upsun_dashboard_panels`; deliberately actions-not-settings — configuration stays in code. |
| `cron-heartbeat` | Proves cron *executes*, not just that it is configured: schedules a recurring event that stamps a timestamp option, and reports staleness (plus overdue-event counts) through Site Health, the dashboard, and `wp upsun doctor`. |
| `safe-previews` | Neuters live outbound integrations on preview clones, runtime-only (never DB writes): intercepts `wp_mail` (or redirects it) built-in; the WooCommerce integrations contribute Stripe test-mode forcing and webhook pausing through the same registry. Fresh clones and data syncs are detected via an environment stamp and sanitized by `wp upsun sanitize --if-needed` in the post_deploy hook (installation step 3), which fires `upsun_preview_sanitize` so consumers can scrub their own integrations; registry extensible via `upsun_safe_previews_actions`. Adds a "Preview safety" health check and dashboard panel that warn when the hook wiring is missing. |

## Integrations

Everything the plugin knows about one specific third-party plugin lives in a
dedicated class under `src/Integrations/` — the single place to answer "what
does this plugin do about X?". Integrations contribute **exclusively through
the same public filters consumers use** (never privileged internal calls), so
every built-in integration doubles as proof the public API is sufficient.
They register at `muplugins_loaded` before regular plugins load; every
contribution is a dormant no-op when its target plugin is absent, and the
dashboard's Modules panel reports each integration's boot state plus whether
the target was detected.

| Integration | Target | Contributions |
|---|---|---|
| `woocommerce` | WooCommerce | Session/cart cookies as page-cache bypass patterns; cart/checkout/account pages as page-cache skips; webhook-delivery pause as a SafePreviews protection. |
| `woocommerce-stripe` | WooCommerce Stripe gateway | Test mode forced at option-read time on previews as a SafePreviews protection (cloned live keys stay untouched and unused). |

Toggles mirror modules: the `upsun_integrations` filter, or
`UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE` / `UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE_STRIPE`
constants. To support a plugin the package doesn't know, use the public
filters directly from your own mu-plugin — that is exactly what the built-in
integrations do.

## Configuration

### Constants (wp-config friendly)

- `UPSUN_MU_DISABLE` — kill switch for the whole plugin.
- `UPSUN_DISABLE_ENVIRONMENT_INDICATOR`, `UPSUN_DISABLE_PAGE_CACHE`, `UPSUN_DISABLE_UPDATES_POLICY`, `UPSUN_DISABLE_SITE_HEALTH`, `UPSUN_DISABLE_PREVIEW_PROTECTION`, `UPSUN_DISABLE_SMTP`, `UPSUN_DISABLE_DASHBOARD`, `UPSUN_DISABLE_CRON_HEARTBEAT`, `UPSUN_DISABLE_SAFE_PREVIEWS` — per-module switches.
- `UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE`, `UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE_STRIPE` — per-integration switches.
- `UPSUN_MU_FORCE` — boot modules and integrations off-platform (testing against faked `PLATFORM_*` variables).

### Filters

Module boot is deferred to `muplugins_loaded` priority 0, so **any mu-plugin** can register these regardless of load order.

| Filter | Type | Default | Purpose |
|---|---|---|---|
| `upsun_mu_modules` | `array<string, class-string>` | all modules | Add/remove/replace modules. |
| `upsun_integrations` | `array<string, class-string>` | all integrations | Add/remove/replace third-party plugin integrations. |
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
| `upsun_dashboard_enabled` | `bool` | `true` | Hide the "Upsun" wp-admin page. |
| `upsun_dashboard_panels` | `array<string, {title, render, context?}>` | 5 built-in panels | Add/remove dashboard panels. `context` (`normal` \| `side` \| `column3` \| `column4`, default `normal`) sets the initial column; users can drag panels anywhere and their layout persists. |
| `upsun_dashboard_menu_position` | `int` | `2` | Admin-menu position. The default is *pinned* directly below Dashboard after all plugins register (several plugins squat the top slot, and core breaks the ties with a hash lottery); any other value is passed to `add_menu_page` unpinned. |
| `upsun_dashboard_menu_icon` | `string` | Upsun mark (base64 SVG) | Menu icon: a data URI, image URL, or dashicon class. |
| `upsun_login_banner` | `bool` | `true` | Hide the login-screen environment banner. |
| `upsun_cron_heartbeat_enabled` | `bool` | `true` | Disable the heartbeat event and its check. |
| `upsun_cron_heartbeat_schedule` | `string` | `'hourly'` | WP-Cron schedule for the heartbeat event (staleness thresholds scale with it). |
| `upsun_safe_previews_enabled` | `bool` | `true` | Disable preview safety entirely (protections, stamp, check, panel). |
| `upsun_safe_previews_mail` | `string` | `'intercept'` | Preview mail policy: `intercept` (log, never send), `allow`, or `redirect:qa@example.com`. Malformed values fail safe to intercept. Complements (does not replace) the platform's own "Outgoing emails" toggle: Upsun blocks its SMTP proxy on previews by default, but that toggle never reaches external SMTP/API mailer plugins configured in the cloned data — `wp_mail` interception covers those too. The Preview safety status reports both layers. |
| `upsun_safe_previews_stripe_test_mode` | `bool` | `true` | Stop forcing WooCommerce Stripe into test mode on previews. |
| `upsun_safe_previews_pause_webhooks` | `bool` | `true` | Stop pausing WooCommerce webhook deliveries on previews. |
| `upsun_safe_previews_actions` | `array<string, {label, register, status}>` | 3 built-in protections | Add protections for your own integrations (CRMs, other gateways) or remove built-ins. `register` runs at `muplugins_loaded` on previews; `status` at render time. |
| `upsun_safe_previews_boot_check` | `bool` | `false` | Fallback for projects that cannot edit their hooks: check the environment stamp on every boot and sanitize inline when it is stale. Prefer the post_deploy hook. |
| `upsun_cache_check_route_cache` | `array{enabled, default_ttl, cookies, known}` | documented router defaults | Mirror your route's cache block from `.upsun/config.yaml` (set `known: true`) so `wp upsun cache-check` reports your real cookie allowlist — Upsun does not expose it at runtime. |

### Actions

| Action | When it fires |
|---|---|
| `upsun_preview_sanitize` (`?string $previous, string $current`) | When `wp upsun sanitize` runs (typically `--if-needed` from the post_deploy hook after a clone or data sync, detected via the `upsun_environment_stamp` option), from the dashboard "Run sanitize actions now" button, or at boot if `upsun_safe_previews_boot_check` is enabled. Scrub or reconfigure site-specific integrations here; callbacks must be idempotent. |

### Helper functions

`Upsun\is_upsun()`, `Upsun\environment_name()`, `Upsun\environment_type()`, `Upsun\is_production()`, `Upsun\is_preview_environment()`, `Upsun\branch()`, `Upsun\project_id()`, `Upsun\application_name()`, `Upsun\primary_route()`, `Upsun\routes()`, `Upsun\relationship( string $name )`, `Upsun\version()` — all safe to call off-platform.

## WP-CLI

```
wp upsun info            # project / environment / branch / routes
wp upsun doctor          # health checks; exits 1 on failure (deploy-hook friendly)
wp upsun relationships   # service relationships (credentials never printed)
wp upsun cache flush     # object cache only — the router cache has no purge API
wp upsun cache-check /some/page          # why is/isn't this page router-cacheable?
wp upsun cache-check / --cookie="a=1"    # ...and what do these request cookies change?
wp upsun cache-check / --auth=user:pass  # for previews behind HTTP access control
wp upsun sanitize        # fire the preview sanitize actions (refuses on production)
wp upsun sanitize --if-needed   # post_deploy-hook mode: stamp-aware, safe everywhere
wp upsun sanitize --dry-run
```

All commands print "Not running on Upsun." and exit 0 off-platform.

## Development

```
composer install
composer test   # PHPUnit, no WordPress install required
```

PHP floor is **8.1** (enforced in CI); tests are standalone with minimal WordPress function stubs.

## Roadmap

See [ROADMAP.md](ROADMAP.md) for the versioned plan — headline items: an
"Upsun" dashboard page in wp-admin (environment/services/health panels +
operational actions, extensible via `upsun_dashboard_panels`), a SafePreviews
module (neuter live payment/webhook/mail integrations on preview clones),
`wp upsun cache-check` (explain why a page is/isn't router-cacheable), cron
execution heartbeat, a read-only-FS plugin compatibility layer, and
`wp upsun migrate`. Router cache purge remains blocked on a platform purge API.
