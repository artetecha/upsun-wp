# upsun-wp — the Upsun mu-plugin for WordPress

Platform integration for WordPress running on [Upsun](https://upsun.com): environment awareness, router-cache friendliness, safe preview clones, deploy migrations, Cloudflare front-end support, Upsun-specific Site Health checks, and a `wp upsun` CLI command.

**Site & docs: [upsun.artetecha.com](https://upsun.artetecha.com/)**

The plugin detects Upsun at runtime (`PLATFORM_APPLICATION_NAME` + `PLATFORM_ENVIRONMENT`) and **fully no-ops anywhere else** — local development and CI need no special-casing. It reads platform variables directly and never defines WordPress configuration constants: your `wp-config.php` stays the single owner of database credentials, URLs, salts, and `WP_ENVIRONMENT_TYPE`.

This is a generic plugin for any WordPress project on Upsun; site-specific behavior belongs in the consuming project via the filters below — never in this package. It was built for, and is battle-tested by, its first customer: a production LMS/commerce site consuming it exclusively through the public filter/constant API. A companion starter repository — a deploy-ready Composer WordPress on Upsun, pre-wired for this plugin — is on its way.

## Installation (Composer-managed WordPress)

Three steps: require the package, route the install path (and copy the
loader shim), and wire the post_deploy hook.

**1. Require the package**

```jsonc
// composer.json
{
  "require": {
    "artetecha/upsun-wp": "^0.3"
  }
}
```

**2. Route the install path and copy the loader shim.** WordPress does not
scan mu-plugin subdirectories, so a shim always has to reach the mu-plugins
root; where the package itself may install depends on your layout.

*Content directory OUTSIDE the core install dir* (Bedrock-style): the
standard route works — the package lands in `mu-plugins/upsun/` (via its
`installer-name`) and only the shim needs copying:

```json
"extra": {
  "installer-paths": {
    "web/app/mu-plugins/{$name}": ["type:wordpress-muplugin"]
  }
},
"scripts": {
  "post-install-cmd": [
    "cp web/app/mu-plugins/upsun/upsun-loader.php web/app/mu-plugins/upsun-loader.php"
  ]
}
```

*Content directory INSIDE the core install dir* (johnpbloch-style
`wordpress/wp-content/...`): **do not route this package into
`wordpress/`.** Composer installs independent packages in alphabetical
order; `artetecha/*` sorts before `johnpbloch/*`, and the WordPress core
extraction replaces the entire install dir — silently deleting anything
placed there earlier. Route the package to a staging directory and copy it
in with the shim:

```json
"extra": {
  "installer-paths": {
    "composer-mu-plugins/{$name}": ["artetecha/upsun-wp"],
    "wordpress/wp-content/mu-plugins/{$name}": ["type:wordpress-muplugin"]
  }
},
"scripts": {
  "postbuild": [
    "mkdir -p wordpress/wp-content/mu-plugins",
    "rm -rf wordpress/wp-content/mu-plugins/upsun",
    "cp -R composer-mu-plugins/upsun wordpress/wp-content/mu-plugins/upsun",
    "cp composer-mu-plugins/upsun/upsun-loader.php wordpress/wp-content/mu-plugins/upsun-loader.php"
  ],
  "post-install-cmd": "@postbuild",
  "post-update-cmd": "@postbuild"
}
```

(Add `/composer-mu-plugins/` and the copied files to `.gitignore`; scripts
run after every install, so the copy is always fresh.)

**3. Wire preview sanitize into the post_deploy hook.** Data syncs redeploy an environment **without a code change, so only the `post_deploy` hook runs** — `deploy` does not, which makes `post_deploy` the only hook that can catch every clone and resync. Add one line to `.upsun/config.yaml` that is safe on every environment (production refreshes the stamp that makes its clones detectable; already-sanitized previews no-op):

```yaml
hooks:
  post_deploy: |
    wp upsun sanitize --if-needed
```

This line is also where your **sanitization policy** lives: `--enable` forces
the opt-in DB-writing sanitizers for the run, so the whole policy is declared
at project level in versioned config and applied identically to every child
environment (or vary it per environment type with a small script):

```yaml
hooks:
  post_deploy: |
    wp upsun sanitize --if-needed --enable="anonymize-user-emails,anonymize-user-passwords:password-{ID}"
```

Skipping this step does **not** weaken the runtime preview protections (mail interception, payment test mode, webhook pausing are active on every preview request from boot) — it only means the one-time `upsun_preview_sanitize` consumer actions never fire. The "Preview safety" health check (Site Health, the Upsun dashboard, `wp upsun doctor`) warns on every environment until the wiring is in place. If you cannot edit your hooks, enable the per-boot fallback via the `upsun_safe_previews_boot_check` filter.

## Modules

| Module | What it does |
|---|---|
| `cloudflare` | For sites proxied by Cloudflare in front of the Upsun router. **The Upsun router already resolves the real client IP into `REMOTE_ADDR`** (verified: `REMOTE_ADDR` == `CF-Connecting-IP` == `X-Client-IP`, and Cloudflare's edge never appears in `REMOTE_ADDR`/`X-Forwarded-For`), so this module does **not** rewrite it — that would be redundant and, on a direct origin hit, spoofable. It detects Cloudflare via the `CF-Ray`/`CF-Connecting-IP` headers and adds a health check + dashboard panel that confirm fronting and that `REMOTE_ADDR` agrees with `CF-Connecting-IP`. Adds `wp upsun cloudflare purge` — the edge invalidation the Upsun router cache never had — with optional auto-purge of a post's URL on change, and an optional shared-secret origin guard (off by default) that rejects production requests bypassing Cloudflare. A raw-origin `REMOTE_ADDR` restoration path exists for consumers without an IP-resolving router, gated off by default (`upsun_cloudflare_restore_remote_addr`). Inert where Cloudflare isn't fronting, so it's safe to leave enabled everywhere. |
| `environment-indicator` | Color-coded admin-bar badge (branch · environment type) with an Upsun Console link, a dashboard widget with environment metadata, and a matching banner on the login screen. |
| `page-cache` | Emits `Cache-Control: public, max-age=0, s-maxage={ttl}` on anonymous, session-free page views so the Upsun router can cache them; optionally strips configured Set-Cookie headers (e.g. LMS guest sessions) to keep responses cacheable. Built-in bypass patterns cover core session cookies; commerce patterns come from the Integrations layer. `wp upsun cache-check <url>` (also a form in the dashboard Caching panel) explains any page's verdict: effective TTL, Set-Cookie spoilers, bypass-pattern matches, the route cookie allowlist (declared via `upsun_cache_check_route_cache` — Upsun does not expose it at runtime), and whether the fetch was a router HIT/MISS/BYPASS. |
| `updates-policy` | Disables the in-app auto-update machinery (the filesystem is read-only; Composer is the update path), replaces the auto-update toggles with a note, and removes the core Site Health tests that would fail by design. |
| `site-health` | Upsun-specific Site Health checks: object cache round-trip, cron configuration, writable mounts, preview search visibility, deploy migrations, live relationship health (MySQL ping, Redis INFO, HTTP/cluster status), disk usage; plus an "Upsun" section in the Info tab. |
| `preview-protection` | Sends `X-Robots-Tag: noindex, nofollow` and robots meta on non-production environments, without touching the `blog_public` option (the database is a production clone). |
| `smtp` | Points PHPMailer at the on-platform relay (`PLATFORM_SMTP_HOST`, port 25) unless a mailer plugin already configured SMTP. |
| `dashboard` | A top-level "Upsun" page in wp-admin (`manage_options`) styled like the WP Dashboard: panels are real meta boxes in the core dashboard grid — collapsible, draggable between columns, layout persisted per user. Panels: environment, services (credentials never rendered), health checks, resolved caching config, module status; plus operational actions (flush object cache). Extensible via `upsun_dashboard_panels`; deliberately actions-not-settings — configuration stays in code. |
| `cron-heartbeat` | Proves cron *executes*, not just that it is configured: schedules a recurring event that stamps a timestamp option, and reports staleness (plus overdue-event counts) through Site Health, the dashboard, and `wp upsun doctor`. |
| `mount-usage` | Disk and mount visibility: live disk total/free from the mount filesystem (warn at 80% used, fail at 95% — full mounts are a rude way to discover a quota), plus a per-mount size breakdown computed daily via WP-Cron (walking uploads is expensive) and shown with its age in a "Disk & mounts" dashboard panel and the shared checks. |
| `writable-paths` | Advises on the writable-path needs of known plugins: Integrations declare where plugins write, the check compares that against the mounts declared in `PLATFORM_APPLICATION`, and `wp upsun mounts` prints ready-to-paste mount YAML for anything missing. Advisory-only by design — on Upsun the fix is a mount, not a runtime path redirection. |
| `safe-previews` | Neuters live outbound integrations on preview clones, runtime-only (never DB writes): intercepts `wp_mail` (or redirects it) built-in; the WooCommerce integrations contribute Stripe test-mode forcing and webhook pausing through the same registry. Fresh clones and data syncs are detected via an environment stamp and sanitized by `wp upsun sanitize --if-needed` in the post_deploy hook (installation step 3), which runs the opt-in DB-writing sanitizers (anonymize user emails/passwords, deactivate listed plugins, scrub listed options — all disabled by default, enabled via filters) and fires `upsun_preview_sanitize` so consumers can scrub their own integrations; registries extensible via `upsun_safe_previews_actions` and `upsun_preview_sanitizers`. Adds a "Preview safety" health check and dashboard panel that warn when the hook wiring is missing. |

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
| `wordfence` | Wordfence | Advisory: declares `wp-content/wflogs` as a writable-path requirement. |
| `updraftplus` | UpdraftPlus | Advisory: declares `wp-content/updraft` as a writable-path requirement. |
| `wp-rocket` | WP Rocket | Advisory: declares `wp-content/cache` and `wp-content/wp-rocket-config`; notes the `advanced-cache.php` root drop-in (not mountable — copy at build time). |

Toggles mirror modules: the `upsun_integrations` filter, or
`UPSUN_DISABLE_INTEGRATION_{ID}` constants (e.g.
`UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE`, `UPSUN_DISABLE_INTEGRATION_WP_ROCKET`). To support a plugin the package doesn't know, use the public
filters directly from your own mu-plugin — that is exactly what the built-in
integrations do.

## Configuration

### Constants (wp-config friendly)

- `UPSUN_MU_DISABLE` — kill switch for the whole plugin.
- `UPSUN_DISABLE_CLOUDFLARE`, `UPSUN_DISABLE_ENVIRONMENT_INDICATOR`, `UPSUN_DISABLE_PAGE_CACHE`, `UPSUN_DISABLE_UPDATES_POLICY`, `UPSUN_DISABLE_SITE_HEALTH`, `UPSUN_DISABLE_PREVIEW_PROTECTION`, `UPSUN_DISABLE_SMTP`, `UPSUN_DISABLE_DASHBOARD`, `UPSUN_DISABLE_CRON_HEARTBEAT`, `UPSUN_DISABLE_SAFE_PREVIEWS`, `UPSUN_DISABLE_WRITABLE_PATHS`, `UPSUN_DISABLE_MOUNT_USAGE` — per-module switches.
- `UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE`, `UPSUN_DISABLE_INTEGRATION_WOOCOMMERCE_STRIPE` — per-integration switches.
- `UPSUN_MIGRATIONS_DIR` — directory of deploy migrations (see below); unset = feature idle.
- `UPSUN_MU_FORCE` — boot modules and integrations off-platform (testing against faked `PLATFORM_*` variables).

### Filters

Module boot is deferred to `muplugins_loaded` priority 0, so **any mu-plugin** can register these regardless of load order.

| Filter | Type | Default | Purpose |
|---|---|---|---|
| `upsun_mu_modules` | `array<string, class-string>` | all modules | Add/remove/replace modules. |
| `upsun_integrations` | `array<string, class-string>` | all integrations | Add/remove/replace third-party plugin integrations. |
| `upsun_page_cache_ttl` | `int` | `600` | Shared-cache TTL in seconds; `<= 0` disables the header. |
| `upsun_cloudflare_enabled` | `bool` | `true` | Load the Cloudflare module (inert where Cloudflare isn't fronting, so safe to leave on everywhere). |
| `upsun_cloudflare_restore_remote_addr` | `bool` | `false` | Rewrite `REMOTE_ADDR` from `CF-Connecting-IP` (gated on the CF ranges). **Leave off on Upsun** — the router already sets the real client IP. Only for raw-origin consumers with no IP-resolving router in front. |
| `upsun_cloudflare_ip_ranges` | `string[]` | bundled CF v4+v6 CIDRs | Cloudflare ranges used by the raw-origin restoration path and the origin guard. Override to refresh the bundled list without a plugin release. |
| `upsun_cloudflare_origin_secret` | `string` | `''` (from `CLOUDFLARE_ORIGIN_SECRET`) | Shared secret a CF Transform Rule injects on proxied requests. When set, production requests missing/mismatching it get a 403 (bypass guard). Empty = guard disabled. Read from an env var; never hard-code. |
| `upsun_cloudflare_origin_secret_header` | `string` | `'HTTP_X_ORIGIN_SECRET'` | The `$_SERVER` key carrying the origin secret (i.e. `X-Origin-Secret`). |
| `upsun_cloudflare_zone_id` | `string` | `''` (from `CLOUDFLARE_ZONE_ID`) | Cloudflare zone id for purge calls. |
| `upsun_cloudflare_api_token` | `string` | `''` (from `CLOUDFLARE_API_TOKEN`) | Cloudflare API token for purge calls — scope it to Zone → Cache Purge only. |
| `upsun_cloudflare_auto_purge` | `bool` | `false` | Purge a post's URL(s) from the Cloudflare edge when its cache is cleaned. |
| `upsun_cloudflare_post_purge_urls` | `string[]` | `[ permalink ]` | The URLs purged for a changed post when auto-purge is on. |
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
| `upsun_writable_paths_enabled` | `bool` | `true` | Disable the writable-path advisor check. |
| `upsun_preview_sanitizers` | `array<string, {label, enabled, run}>` | 4 built-ins, all disabled | Add your own DB-writing sanitizers (idempotent, dry-run aware) or remove built-ins. They run inside the sanitize flow, before `upsun_preview_sanitize`. |
| `upsun_sanitize_anonymize_user_emails` | `bool` | `false` | Rewrite every user email to `user-{ID}@upsun-preview.invalid` on sanitize (one idempotent UPDATE; usernames keep working for login). |
| `upsun_sanitize_anonymize_passwords` | `bool\|string` | `false` | `true` sets every password to `password`; a template like `'password-{ID}'` gives per-user passwords (legacy-MD5 hashes, rehashed by WP on first login). **Pair with Upsun's HTTP access control** — known passwords on a reachable preview are a door. |
| `upsun_sanitize_preserved_emails` | `string[]` | `[]` | Users exempt from BOTH anonymizers: exact addresses or `'@domain'` suffixes. |
| `upsun_sanitize_deactivate_plugins` | `string[]` | `[]` | Plugin basenames deactivated on sanitize (empty = disabled). |
| `upsun_sanitize_scrub_options` | `array<string, mixed>` | `[]` | Options scrubbed on sanitize: option name (optionally with a dotted sub-key path like `gateway_settings.live_secret_key`) => replacement; `null` deletes/unsets. |
| `upsun_migrations_dir` | `?string` | `UPSUN_MIGRATIONS_DIR` constant | Directory of deploy migrations; null = feature idle. |
| `upsun_mount_usage_enabled` | `bool` | `true` | Disable the daily mount measurement and the "Disk & mounts" panel. |
| `upsun_disk_usage_thresholds` | `array{int, int}` | `[80, 95]` | Used-percent thresholds for the disk-usage check (warn, fail). |
| `upsun_writable_path_requirements` | `array<string, {label, active, paths, note?}>` | contributed by Integrations | Declare where a plugin writes (paths relative to wp-content; `active` evaluated at check time). The check and `wp upsun mounts` do the rest. |

### Actions

| Action | When it fires |
|---|---|
| `upsun_preview_sanitize` (`?string $previous, string $current`) | When `wp upsun sanitize` runs (typically `--if-needed` from the post_deploy hook after a clone or data sync, detected via the `upsun_environment_stamp` option), from the dashboard "Run sanitize actions now" button, or at boot if `upsun_safe_previews_boot_check` is enabled. Scrub or reconfigure site-specific integrations here; callbacks must be idempotent. |

### Deploy migrations

Ordered, once-per-database changes that ship with your code. Point
`UPSUN_MIGRATIONS_DIR` at a directory of PHP files named
`YYYYMMDD_NNNN_short_name.php`, each returning a callable:

```php
<?php // migrations/20260712_0001_enable_ip_sessions.php
return static function () {
	update_option( 'learn_press_store_ip_customer_session', 'yes' );
};
```

Run `wp upsun migrate` from the **deploy hook** (before traffic): pending
migrations apply in filename order, each success is recorded in a
non-autoloaded option, and the first failure (throwable or `return false`)
exits non-zero so the deploy aborts. Completion markers live in the
database on purpose — a preview cloned from production carries them along
with the already-migrated data, so nothing re-runs. A shared health check
warns everywhere when migrations are pending and fails on misnamed files.

### Helper functions

`Upsun\is_upsun()`, `Upsun\environment_name()`, `Upsun\environment_type()`, `Upsun\is_production()`, `Upsun\is_preview_environment()`, `Upsun\branch()`, `Upsun\project_id()`, `Upsun\application_name()`, `Upsun\primary_route()`, `Upsun\routes()`, `Upsun\relationship( string $name )`, `Upsun\version()` — all safe to call off-platform.

## WP-CLI

```
wp upsun info            # project / environment / branch / routes
wp upsun doctor          # health checks; exits 1 on failure (deploy-hook friendly)
wp upsun relationships   # service relationships (credentials never printed)
wp upsun relationships --health   # live probes: MySQL ping, Redis INFO, HTTP/cluster status
wp upsun cache flush     # object cache only — the router cache has no purge API
wp upsun cloudflare status               # is Cloudflare fronting this env? purge creds set?
wp upsun cloudflare purge --all          # purge the whole Cloudflare zone
wp upsun cloudflare purge --url=https://example.com/   # ...or specific URLs (repeatable)
wp upsun cache-check /some/page          # why is/isn't this page router-cacheable?
wp upsun cache-check / --cookie="a=1"    # ...and what do these request cookies change?
wp upsun cache-check / --auth=user:pass  # for previews behind HTTP access control
wp upsun mounts          # declared mounts + ready-to-paste YAML for missing ones
wp upsun migrate         # apply pending deploy migrations; non-zero exit aborts the deploy
wp upsun migrate --dry-run
wp upsun sanitize        # fire the preview sanitize actions (refuses on production)
wp upsun sanitize --if-needed   # post_deploy-hook mode: stamp-aware, safe everywhere
wp upsun sanitize --dry-run
wp upsun sanitize --enable="anonymize-user-emails,anonymize-user-passwords:password-{ID}"
                         # force sanitizers for this run only (project-level policy
                         # when placed in the post_deploy hook); filters still work
```

All commands except `wp upsun cloudflare` print "Not running on Upsun." and exit 0 off-platform. `cloudflare` is host-agnostic (it talks to the Cloudflare API using `CLOUDFLARE_*` credentials), so it also runs from CI or a local shell.

## Development

```
composer install
composer test   # PHPUnit, no WordPress install required
```

PHP floor is **8.1** (enforced in CI); tests are standalone with minimal WordPress function stubs.

## Roadmap

See [ROADMAP.md](ROADMAP.md) for the versioned plan. The v0.2 and v0.3
milestones shipped (the "Upsun" wp-admin dashboard, SafePreviews, integrations
architecture, `wp upsun cache-check`/`migrate`/`mounts`/`relationships --health`,
opt-in sanitizers, writable-path and mount-usage advisors), and the plugin was
extracted to this repository and published on Packagist. Latest: the
`cloudflare` module (0.4.x) — Cloudflare-fronting awareness (health check +
dashboard), edge cache purge, and an optional origin guard, for sites proxied by
Cloudflare in front of the Upsun router (the router already provides the real
client IP, so the module verifies rather than rewrites it). Next up in v0.4: the
premium plugin vendoring toolkit (`wp upsun vendor`).
Router cache purge remains blocked on a platform purge API — though the
`cloudflare` module now purges the *edge* cache when Cloudflare fronts the site.
