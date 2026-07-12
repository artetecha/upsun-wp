# Roadmap

## Status (2026-07-12)

| Milestone | Item | Status |
|---|---|---|
| v0.2 | Upsun dashboard page (`dashboard` module) | ✅ shipped in 0.2.0 (PR #44), verified on a preview env |
| v0.2 | Cron heartbeat (`cron-heartbeat` module) | ✅ shipped in 0.2.1 (PR #45), `wp upsun doctor` verified live |
| v0.2 | Login-screen environment banner | ✅ shipped in 0.2.1 (PR #45), verified on a preview env |
| v0.2 | SafePreviews module + `wp upsun sanitize` | ✅ shipped in 0.2.2 (PR #46); dashboard restyle followed in 0.2.3 (PR #47) |
| v0.2 | `wp upsun cache-check <url>` | ✅ shipped in 0.2.4 (PR #48), verified live — **v0.2 milestone complete** |
| v0.3 | Integrations architecture (`src/Integrations/`) | ✅ shipped in 0.3.0 (PR #49), verified on a preview env |
| v0.3 | Writable-path advisor (`writable-paths` + `wp upsun mounts`) | ✅ shipped in 0.3.1 (PR #50), verified on a preview env |
| v0.3 | Opt-in sanitizers (email/password anonymizers, deactivate-plugins, scrub-options) | ✅ shipped in 0.3.2 (PR #51), verified on a preview env |
| v0.3 | Deploy migrations (`wp upsun migrate`) | 🔄 implemented in 0.3.3; preview-env verification pending |
| v0.3 | Relationship health, mount usage | ⬜ not started |
| — | Extraction to an independent repo | ⬜ triggered by second consumer or v0.3 |

The v0.2 milestone spans 0.2.x releases; version = package `composer.json` /
`UPSUN_MU_PLUGIN_VERSION`.

---

This package is a **generic platform plugin for WordPress on Upsun** — not a KEDS
component. KEDS is the first customer: it consumes the plugin through the public
filter/constant API only, and anything KEDS-specific lives in the consuming repo
(`keds/mu-plugins/keds-upsun-config.php`), never in here. The package is designed
to be extracted to its own repository and published on Packagist once it stabilizes.

## Principles

1. **Generic first.** A feature lands here only if it helps any WordPress site on
   Upsun. Site-specific behavior must be expressible through filters.
2. **No-op everywhere else.** Off-platform (local, CI) the plugin loads and does
   nothing. Malformed platform data degrades silently, never fatals.
3. **wp-config wins.** The plugin never defines configuration constants; it reads
   env and constants and fills runtime-behavior gaps.
4. **Honest about the platform.** No feature that pretends an API exists (e.g.
   router cache purge). Document limitations instead of papering over them.
5. **Every module unit-tested** against the WP stubs in `tests/bootstrap.php`;
   behavior assertions belong to the consumer's e2e suite against a real
   preview environment.
6. **Compatibility floor:** PHP 8.1+, WordPress 6.0+. Semver; 0.x minors may add
   modules but must not change default behavior of existing ones.

---

## v0.2 — Preview safety, cache DX, and the Upsun dashboard

The theme: make Upsun's clone-of-production preview environments safe by default,
make the router cache debuggable, and give the plugin a home inside wp-admin.

### Upsun dashboard page (`dashboard` module) — shipped in 0.2.0

A top-level **"Upsun"** entry in the wp-admin sidebar (`add_menu_page`,
`manage_options`, slug `upsun`; since 0.2.2 positioned directly below
Dashboard with the official Upsun mark as a repaintable base64-SVG icon),
following the WP Engine/Kinsta pattern of a platform home inside wp-admin. This becomes the surface that all other features plug into,
instead of each growing its own UI.

- **Panel registry**: modules contribute panels via an
  `upsun_dashboard_panels` filter (same extension philosophy as
  `upsun_mu_modules`), so consumers and future modules can add their own.
  Since 0.2.3 panels are real meta boxes in the core dashboard grid
  (`do_meta_boxes` + postbox.js): collapsible, draggable between the four
  columns, per-user layout persistence — the WP Dashboard experience, all
  stock wp-admin machinery.
  Launch panels:
  - *Environment* — name, type, branch, project, application, primary route,
    PHP/WP/plugin versions, link to the Upsun Console (what the v0.1 dashboard
    widget shows, expanded).
  - *Services* — relationships with scheme/host/port (credentials never
    rendered), object-cache status and hit/miss if the drop-in exposes stats.
  - *Health* — the SiteHealth check registry rendered inline (same source as
    `wp upsun doctor`), with re-run button.
  - *Caching* — effective page-cache TTL, active bypass-cookie patterns and
    strip list (resolved through the filters, so consumers see their overrides),
    honest "router purge: not available" note.
  - *Modules* — which modules are loaded/disabled and by what (constant vs
    filter), so "why isn't X happening?" is answerable at a glance.
- **Actions, not settings.** Operational buttons where applicable — flush
  object cache, re-run health checks, later `sanitize now` (SafePreviews) and
  `cache-check` form (enter a URL, see the verdict) — all nonce- and
  capability-gated POSTs. Deliberately **no settings UI**: configuration stays
  in code (constants/filters) where it is versioned and survives environment
  clones; a DB-backed settings page would fight the platform's config model.
- The v0.1 admin-bar badge and activity widget link here; the widget shrinks
  to a summary + "open Upsun dashboard" link.
- Toggle: `UPSUN_DISABLE_DASHBOARD` / registry entry `dashboard`.

Shipped in 0.2.0: the shell, all five panels (the Caching panel in its static
form), and the flush-object-cache action. The Caching panel's interactive
cache-check form and the SafePreviews sanitize action land with their
features below.

### SafePreviews module (`safe-previews`) — implemented in 0.2.2

The flagship. Upsun previews are byte-for-byte clones of production — including
live payment keys, webhook URLs, and CRM credentials. This module neuters
outbound integrations on non-production environments:

- **Mail policy**: on previews, default to intercepting `wp_mail` (short-circuit
  via `pre_wp_mail`, log the message instead). Modes via
  `upsun_safe_previews_mail` filter: `intercept` (default) | `allow` |
  `redirect:<address>`.
- **Payment gateways**: runtime-filter known gateways into test/sandbox mode
  where the gateway supports it (start with WooCommerce Stripe; registry is
  extensible via `upsun_safe_previews_actions`). Prefer runtime filters over DB
  writes so behavior applies always and leaves the cloned data untouched.
- **Outbound webhooks**: pause WooCommerce webhooks deliveries on previews
  (runtime short-circuit, not status mutation, if achievable).
- **Site hook**: fire `upsun_preview_sanitize` action when a fresh clone or
  data sync is sanitized, so consumer code can scrub/adjust its own
  integrations.
- **Fresh-clone detection**: store the environment name in an option
  (`upsun_environment_stamp`); a mismatch between stored and current env means
  "this database was just cloned/synced from elsewhere". (The clone carries
  the parent's stamp, which is exactly what makes the mismatch detectable —
  no runtime `PLATFORM_*` variable exposes parent env or data-sync time.)
- **Trigger**: `wp upsun sanitize --if-needed` in the **post_deploy hook** —
  the only hook that runs on every redeploy including data syncs, and safe on
  all environments (production refreshes the stamp, sanitized previews no-op).
  A per-request boot check exists as an opt-in fallback
  (`upsun_safe_previews_boot_check`, default off); the preview_safety check
  warns when neither has run for the current data.
- **Opt-outs**: `UPSUN_DISABLE_SAFE_PREVIEWS`, per-concern filters. A real
  staging domain that must send mail can allow-list itself.
- **CLI**: `wp upsun sanitize [--if-needed] [--dry-run]` to run the sanitize
  actions on demand.

Design constraint: KEDS's e2e suite currently forbids POSTs against previews
because Stripe/FluentCRM are live there. Success criterion: with SafePreviews
active, that warning can be deleted (FluentCRM specifics stay KEDS-side via the
registry filter).

### `wp upsun cache-check <url>` — implemented in 0.2.4

Self-service diagnosis of the #1 WordPress-on-Upsun support issue ("why isn't
my page cached?"). Fetches the URL (optionally with `--cookie`) and
explains the verdict:

- which request cookie matched a bypass pattern (and the pattern),
- whether the response carried `Set-Cookie` (and which cookie — the thing that
  makes the router refuse to cache),
- the emitted `Cache-Control` and effective s-maxage,
- whether `DONOTCACHEPAGE`/prior no-cache headers spoiled it,
- whether this fetch was a router HIT/MISS/BYPASS (`X-Platform-Cache`).

Output: a table plus a one-line verdict (`cacheable for 600s (s-maxage)` /
`uncacheable: Set-Cookie lp_session_guest`). As originally suspected, Upsun
does not expose the routes' cache blocks in `PLATFORM_ROUTES` (verified
live) — documented defaults are assumed and flagged "(assumed)", and
consumers can mirror their real route cache config via the
`upsun_cache_check_route_cache` filter to make the cookie notes exact.
The tool reads the block if the platform ever starts exposing it. HTTP 401s
are diagnosed as access control (with `--auth=<user:pass>` support for
protected previews) rather than misattributed to the page. The same engine
powers the interactive form in the dashboard Caching panel (restricted to
the environment's own routes).

### Cron heartbeat — shipped in 0.2.1

SiteHealth v0.1 checks configuration (`DISABLE_WP_CRON`), not execution. The
`cron-heartbeat` module schedules a recurring event (default hourly, filter
`upsun_cron_heartbeat_schedule`) that stamps a non-autoloaded timestamp
option; the shared check registry then warns at 2× the schedule interval and
fails at 4×, with an overdue-events count via `wp_get_ready_cron_jobs()`.
Reported by Site Health, the dashboard Health panel, and `wp upsun doctor`.

### Login-screen environment banner — shipped in 0.2.1

EnvironmentIndicator renders a colored banner above the login form (via
`login_message`) naming the environment type and branch, using the badge
color coding. The admin-bar badge only protects people who are already logged
in; this protects them at the door. Opt out via `upsun_login_banner`.

---

## v0.3 — Fleet features

For adoption beyond the first customer.

### Integrations architecture — implemented in 0.3.0

The opening move of v0.3, a pure refactor: everything the plugin knows about
one specific third-party plugin lives in a dedicated class under
`src/Integrations/` (launch set: `woocommerce`, `woocommerce-stripe`),
booted by an `IntegrationRegistry` mirroring the module registry
(`upsun_integrations` filter, `UPSUN_DISABLE_INTEGRATION_*` constants,
status in the dashboard Modules panel with target-plugin detection).

The load-bearing rule: **integrations contribute exclusively through the
public filter API consumers use** — bypass-cookie patterns, page-cache
skips, SafePreviews protections. If a built-in integration can't express
something through the public API, a consumer couldn't either; permanent
dogfooding. Integrations boot before modules at `muplugins_loaded` 0 and
contribute at filter priority 5, so consumer filters (default 10) still
override. Behavior is byte-identical to 0.2.4 (pattern-coverage parity is
pinned by a test); every feature below that touches a specific plugin
(compat fixes, `deactivate-plugins` targets, gateway sanitizers) lands as
an integration, not as inline knowledge in a concern module.

### Built-in sanitizers (opt-in, disabled by default) — implemented in 0.3.2

SafePreviews' runtime protections never write to the database; the
`Sanitizers` registry adds *DB-writing* sanitizers that run inside the
sanitize flow (before `upsun_preview_sanitize`, so consumer callbacks get
the final say) — each shipped **disabled** and enabled per-slug via filter
(code-based config, no toggle UI; 0.x semver forbids changing default
behavior). Writes are safe by platform design: data only flows parent→child
on Upsun, so scrubbed preview state can never leak back, and a resync
re-triggers sanitize. Every sanitizer is idempotent and dry-run aware,
reporting what changed through `wp upsun sanitize` and the dashboard panel.

Enablement is deliberately never DB-backed (a resync would erase the toggle
at exactly the moment it is needed). Two equivalent surfaces: filters in a
consumer mu-plugin, or `wp upsun sanitize --enable=<ids>` — per-run forcing
that turns the post_deploy hook line into the project-level sanitization
policy, versioned in `.upsun/config.yaml` and identical for every child
environment.

Shipped built-ins:

- **`anonymize-user-emails`** — one idempotent UPDATE rewriting emails to
  `user-{ID}@upsun-preview.invalid` (RFC 2606). Defense-in-depth beyond mail
  interception: no plugin can reach a real customer through any send path.
  Preserve list shared with the password anonymizer
  (`upsun_sanitize_preserved_emails`).
- **`anonymize-user-passwords`** — `true` = `password` for everyone, or a
  `'password-{ID}'` template for per-user values; single UPDATE using legacy
  MD5 hashes (WP rehashes on first login), exactly idempotent. Pair with
  Upsun's HTTP access control — known passwords on a reachable preview are
  a door.
- **`deactivate-plugins`** — deactivate a consumer-supplied list of plugin
  basenames on previews (backup runners, analytics, gateways with no runtime
  test-mode switch). Could replace KEDS's LearnPress-Stripe gateway-removal
  shim with declarative config — deliberately not switched: the runtime
  filter needs no DB write and re-applies instantly on resync.
- **`scrub-options`** — null/overwrite a consumer-supplied list of option
  names or dotted array sub-keys. The generic escape hatch for plugins that
  read credentials in ways runtime filters cannot reach (the LearnPress
  settings-cache problem, generalized).

### Writable-path advisor (`writable-paths`) — implemented in 0.3.1

Reshaped from the originally planned "read-only-FS compat layer" (a
pantheon-mu-plugin port) after realizing the premise doesn't transfer:
Pantheon has a *fixed* set of writable paths, so redirecting plugin
cache/log/backup paths into uploads is the only fix available there. On
Upsun, writable directories are user-declared mounts — the platform-native
fix is three lines of YAML, and runtime path redirection would paper over
what config should express ("honest about the platform"). What actually
remains is a discovery problem plus two residual gaps:

- **The advisor**: Integrations declare where known plugins write
  (`upsun_writable_path_requirements` registry); the `writable_paths` check
  compares that against the mounts declared in `PLATFORM_APPLICATION` and
  warns naming the missing directories; `wp upsun mounts` lists the declared
  mounts and prints ready-to-paste mount YAML for anything not covered.
  Launch registry: Wordfence (`wflogs`), UpdraftPlus (`updraft`), WP Rocket
  (`cache`, `wp-rocket-config`). Grows from real adoption reports.
- **Residual gap 1 — wp-content-root drop-ins** (`advanced-cache.php` and
  friends) cannot be mounts: mounting wp-content root would shadow deployed
  code. Surfaced as notes in the check; handled at build time (Composer
  post-install copy), like an object-cache drop-in.
- **Residual gap 2 — `is_writable()` nags**: some plugins complain about the
  read-only tree even when their real write target is mounted. Per-plugin
  suppression lands in that plugin's integration class as adoption reports
  arrive (KEDS's thim-core notice hider is the consumer-side prototype).

### Deploy migrations (`wp upsun migrate`) — implemented in 0.3.3

Generalizes the shell-script framework proven in the KEDS repo: ordered,
once-per-database PHP migration files (`YYYYMMDD_NNNN_short_name.php`, each
returning a callable) from a consumer directory (`UPSUN_MIGRATIONS_DIR` /
`upsun_migrations_dir`), tracked per-migration in non-autoloaded options
(clones carry the markers with the migrated data), `--dry-run` support,
non-zero exit on the first failure so deploy hooks abort before traffic,
plus a shared health check that warns on pending and fails on misnamed
files. Every serious WP-on-Upsun project reinvents this. KEDS's own shell
framework (`keds/deploy-migrations/`) keeps working as-is; swapping it to
the plugin's PHP format is an optional consumer follow-up.

### Relationship health & search wiring

- `wp upsun relationships --health`: live checks per relationship (Redis
  `INFO` memory/hit-rate, DB ping, search-service cluster status), surfaced in
  Site Health too.
- Auto-wiring helper for an Elasticsearch/OpenSearch relationship into
  ElasticPress (host filter + Site Health check), behind a module toggle.

### Mount usage visibility

Dashboard widget + doctor check reporting disk usage of the writable mounts
against the app's disk size (from `PLATFORM_APPLICATION`). Full mounts are a
rude way to discover a quota. Note: mounts share one disk — report per-mount
`du` cautiously (expensive; cache the result, compute on cron).

---

## v0.4+ / blocked on platform or demand

- **Router cache purge** — blocked: the Upsun router exposes no purge API.
  Pre-ship the API surface as documented no-ops (`Upsun\purge_paths()`,
  `Upsun\purge_keys()`) with a pluggable backend so Fastly/Cloudflare-in-front
  setups can adapter in, and the feature lights up if the platform ships purging.
- **Multisite** — keep delegating to `upsun/wp-ms-dbu` until there's demand to
  absorb it.
- **Maintenance mode** — parity feature with pantheon-mu-plugin; low urgency.
- **Environment-aware activity log** — real but well-served by existing plugins;
  revisit if preview-safety auditing needs it.

---

## Extraction to an independent repo

Trigger: a second real consumer (or Upsun/Platform.sh team adoption), or v0.3
shipping — whichever comes first.

Steps:

1. Split `keds/packages/upsun-mu-plugin/` into its own repository with history
   (`git filter-repo --subdirectory-filter`).
2. Port the unit-test workflow (PHP 8.1 + 8.4 matrix) as the new repo's CI;
   add a WP-integration smoke job (wp-env or the hermetic pattern from the
   KEDS repo).
3. Publish `upsun/wordpress-mu-plugin` on Packagist; tag = the composer.json
   version. `archive.exclude` starts applying to dist installs (tests stop
   shipping to consumers).
4. KEDS swaps the path repository for a Packagist version constraint (`^0.x`)
   — a two-line `composer.json` change; the loader-shim postbuild line is
   unchanged.
5. Distribution stays Composer-first. No wordpress.org listing: mu-plugins
   aren't activatable and the loader-shim install step doesn't fit the plugin
   directory model.

Until then, this directory is the source of truth and the KEDS repo's CI is
the plugin's CI.
