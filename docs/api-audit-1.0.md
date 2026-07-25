# Public API surface audit — freeze recommendations for 1.0

Audited against `main` @ `5c5a009` (0.6.0). Every count below is reproducible from
the repo; the commands are given in "Method" at the end.

## Status: executed in 0.7

The recommendations below shipped, with two deviations found while implementing
them. This section is the record; the body is left as written so the reasoning
behind each verdict stays reviewable.

| Step | Shipped in | Note |
|---|---|---|
| `@internal` on the ~40 non-allowlist statics | #10 | `Environment` fenced at class level |
| One application point per multi-site filter | #10 | 4 filters, `@internal` accessors |
| WP integration harness + PHP × WP matrix | #11 | Prerequisite for the shims below |
| 7 renames, shimmed | 0.7.0 | §1a, §1e — **6, not 7, of the listed names** |
| Generic `upsun_{module,integration,fetcher}_enabled` | 0.7.0 | §1b |
| `upsun_cloudflare_restore_remote_addr` removed | 0.7.0 | §1d, unshimmed |

**Deviation 1 — `upsun_configure_smtp` was not renamed.** §1e recommended
`upsun_smtp_enabled`, reading it as a boot gate with a verb name. It is not one:
it is applied inside `Smtp::configure()` on every `phpmailer_init`, deciding
whether to wire PHPMailer to the relay for *this* send. Renaming it to `*_enabled`
would have made a per-send decision look like the module toggle that now lives in
the registry. It keeps its name; a consumer (keds-upsun) also uses it.

**Deviation 2 — the blast radius was measured, not assumed.** §7 suggested
grepping the consumer repos before the renames. Done across `keds-ecampus`,
`keds-jcs-wordpress`, `keds-upsun` and `wordpress-upsun-starter`, excluding
vendored copies of this plugin: consumer code uses `upsun_page_cache_skip`,
`_ttl`, `_strip_cookies`, `upsun_cache_check_route_cache`,
`upsun_safe_previews_mail`, `upsun_safe_previews_actions`,
`upsun_sanitize_preserved_emails`, `upsun_configure_smtp`, and the helper
`Upsun\is_preview_environment()`. **None of the renamed names, and no direct
`Upsun\Environment::` call** — so the shims can be dropped at 1.0 as planned, and
fencing `Environment` in #10 declared nothing in use unsupported.

Also fixed in passing: `UPSUN_DISABLE_FETCHER_TRANSIENT`, documented in §2 as part
of the constant surface, was never read — `Vendor::fetchers()` appended the
fallback unconditionally. It is now honoured, alongside the new
`upsun_fetcher_enabled` filter.

## Corrected inventory

The earlier read undercounted. Actuals:

| Surface | Earlier claim | Actual | Note |
|---|---|---|---|
| Filters | 49 | **51** | `upsun_dashboard_menu_icon` and `upsun_updates_notice_text` are multi-line `apply_filters()` calls and were missed by a single-line grep |
| Actions | — (not counted) | **1** | `upsun_preview_sanitize`, fired via the `SafePreviews::SANITIZE_HOOK` constant (`src/Modules/SafePreviews.php:367`) |
| `UPSUN_*` constants | 13 | **5 named + 3 generated families** = 25 concrete names today | 13 `UPSUN_DISABLE_{MODULE}` + 5 `UPSUN_DISABLE_INTEGRATION_{ID}` + 2 `UPSUN_DISABLE_FETCHER_{ID}` + 5 standalone |
| `wp upsun` subcommands | 10 | **10** ✓ | plus 16 distinct flags and 4 positional args — also frozen |
| Helper functions | 12 | **12** ✓ | all documented |
| Extension interfaces | 3 | **4** | `FetcherStatus` (`src/FetcherStatus.php`) was omitted |
| Public static class methods | — (not counted) | **~70** | across `Environment`, `Vendor`, `Sanitizers`, `Migrations`, `CacheCheck`, `RelationshipHealth`, the two registries — **none marked `@internal`** |
| Documented `$_SERVER` keys | — | **1** | `UPSUN_ORIGINAL_REMOTE_ADDR` — a `$_SERVER` key, not a constant (it was miscounted as one) |

Two things worth stating up front, because they change the shape of the work:

1. **Hook documentation coverage is already 100%.** README mentions 53 `upsun_*`
   names: all 51 filters, the 1 action, and the `upsun_environment_stamp` option.
   Nothing is undocumented and nothing documented is missing from the code. The
   audit's job is therefore classification, not discovery.
2. **The real unbounded surface is PHP, not hooks.** ~70 `public static` methods
   on non-`@internal` classes are, by PHP's rules, API. Only the 12 helper
   functions are documented as such. This is the single biggest thing to fence
   before 1.0 — it is bigger than the entire filter surface, and it is free to
   fence now (adding `@internal` breaks no caller).

---

## 1. Filters (51)

Verdicts: **FREEZE** = public, ship as-is · **RENAME** = public but the name is
wrong; change now, shim, remove shim at 1.0 · **REPLACE** = collapse into a
generic family · **REMOVE** = delete before 1.0 · **DOC** = public, needs a
threat-model note.

### 1a. Extension registries — the core contract (8) → all FREEZE (1 rename)

These are what an extension author actually builds against. Highest-value to
freeze, lowest risk: all 8 already take/return a well-typed array.

| Filter | Site | Default | Verdict |
|---|---|---|---|
| `upsun_mu_modules` | `ModuleRegistry.php:62` | 13 built-ins | **RENAME** → `upsun_modules` |
| `upsun_integrations` | `IntegrationRegistry.php:53` | 5 built-ins | FREEZE |
| `upsun_vendor_fetchers` | `Vendor.php:464` | `[]` | FREEZE |
| `upsun_dashboard_panels` | `Dashboard.php:229` | built-ins | FREEZE |
| `upsun_site_health_tests` | `SiteHealth.php:74` | built-ins | FREEZE |
| `upsun_preview_sanitizers` | `Sanitizers.php:74` | 4, all off | FREEZE |
| `upsun_safe_previews_actions` | `SafePreviews.php:117` | built-ins | FREEZE |
| `upsun_writable_path_requirements` | `WritablePaths.php:55` | `[]` | **RENAME** → `upsun_writable_paths_requirements` |

`upsun_mu_modules`: "mu" is a delivery detail (the loader), not a concept in the
module API — and it is the only registry filter carrying it. `upsun_modules` sits
correctly beside `upsun_integrations` / `upsun_vendor_fetchers`. Cheap now,
permanent later.

`upsun_writable_path*`: the module is `writable-paths` and its sibling filter is
`upsun_writable_paths_enabled`; only the requirements filter is singular.

### 1b. The `*_enabled` toggles (8) → REPLACE with one generic filter

| Filter | Site | Module |
|---|---|---|
| `upsun_cloudflare_enabled` | `Cloudflare.php:88` | cloudflare |
| `upsun_security_headers_enabled` | `SecurityHeaders.php:53` | security-headers |
| `upsun_environment_indicator_enabled` | `EnvironmentIndicator.php:21` | environment-indicator |
| `upsun_dashboard_enabled` | `Dashboard.php:58` | dashboard |
| `upsun_cron_heartbeat_enabled` | `CronHeartbeat.php:30` | cron-heartbeat |
| `upsun_safe_previews_enabled` | `SafePreviews.php:53` | safe-previews |
| `upsun_writable_paths_enabled` | `WritablePaths.php:34` | writable-paths |
| `upsun_mount_usage_enabled` | `MountUsage.php:34` | mount-usage |

This is the "two ways to toggle the same thing" smell, and it is worse than it
looked: **the coverage is inconsistent.** Only 8 of 13 modules have an `_enabled`
filter. `page-cache`, `updates-policy`, `site-health`, `preview-protection` and
`smtp` have none — so a consumer who wants a conditional (per-environment) toggle
can do it for 8 modules and must fall back to a wp-config constant for 5. Two of
the five have a same-shaped boolean filter under a *different* name
(`upsun_page_cache_skip`, `upsun_configure_smtp`), which hides the gap rather
than filling it.

Meanwhile the *constants* already have generic, uniform families for all three
extensible things (`UPSUN_DISABLE_{MODULE}`, `..._INTEGRATION_{ID}`,
`..._FETCHER_{ID}`). The filters have no family at all.

**Recommendation.** Add to the registries, next to the existing constant check:

```php
// ModuleRegistry::boot(), beside disabled_by_constant( $id )
if ( ! apply_filters( 'upsun_module_enabled', true, $id ) ) { … 'state' => 'filter' }
```

plus `upsun_integration_enabled` and `upsun_fetcher_enabled` for symmetry with
the constant families. Then deprecate all 8 specific filters via
`apply_filters_deprecated()` in 0.7 and drop the shims at 1.0. Net effect: 8
inconsistent filters → 3 uniform ones, 13/13 module coverage instead of 8/13, and
one documented precedence chain (kill switch → off-platform → constant → filter →
`should_load()`) that already exists in the code and is already documented for
constants.

Keep `upsun_page_cache_skip` (`PageCache.php:134`) — it is per-*request*, not
boot-time gating, a genuinely different hook. **FREEZE** it, but say so in the
reference, because the name invites the confusion.

### 1c. Page cache (5) → FREEZE, but fix the double application first

| Filter | Applied at | Times |
|---|---|---|
| `upsun_page_cache_bypass_cookie_patterns` | `PageCache.php:110`, `CacheCheck.php:85`, `Dashboard.php:408` | **3** |
| `upsun_page_cache_ttl` | `PageCache.php:144`, `Dashboard.php:407` | **2** |
| `upsun_page_cache_strip_cookies` | `PageCache.php:245`, `Dashboard.php:409` | **2** |
| `upsun_page_cache_skip` | `PageCache.php:134` | 1 |
| `upsun_page_cache_debug_headers` | `PageCache.php:152` | 1 |

And the same pattern outside page-cache: `upsun_preview_noindex` is applied twice
(`PreviewProtection.php:52`, `SiteHealth.php:178`).

Each call site re-applies the filter *and re-states the default*. Two consequences
worth fixing before the contract is frozen:

- A consumer callback that is expensive or non-idempotent runs 2–3× per request.
- The default is duplicated across files, so `PageCache::DEFAULT_TTL` and the
  dashboard/`cache-check` view can silently diverge. (Today they don't —
  `CacheCheck` and `Dashboard` both reference `PageCache::` constants, which is
  why this is a latent risk rather than a live bug.)

**Recommendation:** one accessor per setting on `PageCache` (`ttl()`,
`bypass_patterns()`, `stripped_cookies()`) marked `@internal`, called by
`CacheCheck` and `Dashboard`. Same for `PreviewProtection::noindex()`. The filter
names and signatures don't change — this is purely making "applied once, in one
place" true so the frozen doc can say it.

### 1d. Cloudflare (9) → 1 REMOVE, 3 DOC, 5 FREEZE

| Filter | Site | Verdict |
|---|---|---|
| `upsun_cloudflare_restore_remote_addr` | `Cloudflare.php:101` | **REMOVE** |
| `upsun_cloudflare_api_token` | `Cloudflare.php:475` | DOC (secret-bearing) |
| `upsun_cloudflare_zone_id` | `Cloudflare.php:467` | DOC (secret-bearing) |
| `upsun_cloudflare_origin_secret` | `Cloudflare.php:404` | DOC (secret-bearing) |
| `upsun_cloudflare_enabled` | `Cloudflare.php:88` | REPLACE (see 1b) |
| `upsun_cloudflare_auto_purge` | `Cloudflare.php:109` | FREEZE |
| `upsun_cloudflare_ip_ranges` | `Cloudflare.php:196` | FREEZE |
| `upsun_cloudflare_origin_secret_header` | `Cloudflare.php:413` | FREEZE |
| `upsun_cloudflare_post_purge_urls` | `Cloudflare.php:560` | FREEZE |

**`upsun_cloudflare_restore_remote_addr` — recommend deleting it, not freezing it.**
The code comment above it (`Cloudflare.php:92-101`) already makes the argument:
on Upsun the router resolves the real client IP before PHP runs, so
`REMOTE_ADDR` is already correct and rewriting it from `CF-Connecting-IP` is
redundant — *and* on a direct hit to the `*.platformsh.site` origin it lets a
forged header override the router's correct value. So: the filter's only effect
on the one platform this plugin supports is to open a header-spoofing path. It is
off by default, undocumented as to when you'd want it, and its stated audience
("raw-origin consumers with no IP-resolving router in front") is by construction
not an Upsun site.

Freezing it means committing for a major cycle to a switch whose only reachable
behaviour is a downgrade. Delete the filter and `restore_client_ip()` with it (and
`$_SERVER['UPSUN_ORIGINAL_REMOTE_ADDR']`, which only that path writes — see §2).
If you'd rather keep the capability: keep it but rename to something that names
the risk (`upsun_cloudflare_trust_forwarded_ip`) and document the raw-origin
precondition. Either is defensible; silently freezing the current name is not.

**The three secret-bearing filters** (`api_token`, `zone_id`, `origin_secret`)
each default to a `getenv()` read and are applied at point of use, so any loaded
plugin can hook them and read the value. That is inherent to WordPress filters and
is a legitimate design (it's how a consumer supplies credentials from a secrets
manager). It belongs in the threat model as a stated property — "a Cloudflare API
token reachable through these filters is readable by any code running in the
site" — not as a code change. Freeze the names; write it down in workstream 4.

### 1e. Naming inconsistencies → RENAME (6 more)

| Current | Recommended | Why |
|---|---|---|
| `upsun_configure_smtp` | `upsun_smtp_enabled` | It is a boolean enable gate (`Smtp.php:34`) with a verb name; the only module gate that reads like an action |
| `upsun_disk_usage_thresholds` | `upsun_mount_usage_thresholds` | Owned by the `mount-usage` module; the only filter whose prefix names neither its module nor a shared concept |
| `upsun_login_banner` | `upsun_environment_indicator_login_banner` | Owned by `environment-indicator` (`EnvironmentIndicator.php:60`); bare `upsun_login_banner` reads global |
| `upsun_sanitize_anonymize_passwords` | `upsun_sanitize_anonymize_user_passwords` | Sibling is `..._anonymize_user_emails`, and the registry id is already `anonymize-user-passwords` (`Sanitizers.php:44`) — the filter is the only spelling missing `user` |
| `upsun_safe_previews_pause_webhooks` | `upsun_woocommerce_pause_webhooks` | Declared by the WooCommerce *integration* (`Integrations/WooCommerce.php:124`), not the module; the prefix misattributes ownership |
| `upsun_safe_previews_stripe_test_mode` | `upsun_woocommerce_stripe_test_mode` | Same, `Integrations/WooCommerceStripe.php:90` |

The last two are the ones I'd argue hardest for: the `Integration` docblock says
integrations contribute "exclusively through the same public filter API consumers
use," so their filters are part of the *integration's* contract. Naming them
`upsun_safe_previews_*` means a consumer who removes `safe-previews` from
`upsun_modules` cannot tell from the name that these two filters still apply.

Considered and rejected as churn: renaming `upsun_preview_noindex` →
`upsun_preview_protection_noindex` (well-established, reads fine, applied by two
modules by design), and `upsun_page_cache_skip` (see 1b).

### 1f. Everything else → FREEZE (24)

`upsun_cache_check_route_cache` · `upsun_cron_heartbeat_schedule` ·
`upsun_dashboard_menu_icon` · `upsun_dashboard_menu_position` ·
`upsun_migrations_dir` · `upsun_preview_noindex` ·
`upsun_safe_previews_boot_check` · `upsun_safe_previews_mail` ·
`upsun_sanitize_deactivate_plugins` · `upsun_sanitize_preserved_emails` ·
`upsun_sanitize_scrub_options` · `upsun_sanitize_anonymize_user_emails` ·
`upsun_security_headers` · `upsun_security_hsts` · `upsun_security_hsts_value` ·
`upsun_updates_notice_text` · plus the page-cache and Cloudflare rows already
marked FREEZE above.

One signature note for the reference, not a change: `upsun_safe_previews_mail`
(`SafePreviews.php:141`) is a *string-mode* filter — `'intercept'` | `'allow'` |
`'redirect:<email>'`, malformed input failing safe to `'intercept'`. String-mode
filters are a weaker contract than a boolean or an array (a typo silently becomes
the default). The fail-safe direction is right, so I'd freeze it, but the
reference must enumerate the three legal values, and it deserves a
`_doing_it_wrong()`-style debug notice on unrecognized input rather than silence.

### Filter tally

| Verdict | Count |
|---|---|
| FREEZE as-is | 34 |
| RENAME (shim in 0.7, shim removed at 1.0) | 8 |
| REPLACE by `upsun_{module,integration,fetcher}_enabled` | 8 |
| REMOVE | 1 |
| **Total** | **51** |

Plus the action `upsun_preview_sanitize` (`?string $previous, string $current`) —
**FREEZE**.

---

## 2. Constants (5 named + 3 families = 25 names today)

| Constant | Read at | Verdict |
|---|---|---|
| `UPSUN_MU_DISABLE` | `ModuleRegistry.php:49`, `IntegrationRegistry.php:40` | FREEZE |
| `UPSUN_MU_FORCE` | `ModuleRegistry.php:53`, `IntegrationRegistry.php:44` | FREEZE |
| `UPSUN_MIGRATIONS_DIR` | `Migrations.php:31` | FREEZE |
| `UPSUN_MU_PLUGIN_DIR` | defined `upsun.php:17` | FREEZE + **document** (consumers read it; currently absent from README) |
| `UPSUN_MU_PLUGIN_VERSION` | defined `upsun.php:18`, read `helpers.php:67` | FREEZE + **document** (same) |
| `UPSUN_DISABLE_{MODULE}` (13) | `ModuleRegistry.php:123` | FREEZE |
| `UPSUN_DISABLE_INTEGRATION_{ID}` (5) | `IntegrationRegistry.php:122` | FREEZE |
| `UPSUN_DISABLE_FETCHER_{ID}` (2) | `Vendor.php:485` | FREEZE |

Doc gaps to close (no code change): README lists 13/13 module constants but only
3 of 5 integration constants (`WORDFENCE`, `UPDRAFTPLUS` missing) and 1 of 2
fetcher constants (`UPSUN_DISABLE_FETCHER_TRANSIENT` missing — worth an explicit
note that disabling the universal fallback fetcher disables generic vendoring).

**`$_SERVER['UPSUN_ORIGINAL_REMOTE_ADDR']`** (`Cloudflare.php:285`) is a
`$_SERVER` key, not a constant. It is only ever written by
`restore_client_ip()`, i.e. only when `upsun_cloudflare_restore_remote_addr` is
enabled — so if you take the REMOVE recommendation in §1d it disappears with it.
If you keep that path, this key must be documented, because it is the only way a
consumer can recover the pre-rewrite value.

---

## 3. WP-CLI (10 commands, 16 flags, 4 positionals)

All 10 are documented in README and all should **FREEZE**: `info`, `doctor`,
`relationships`, `cache-check`, `sanitize`, `migrate`, `mounts`, `cache`,
`vendor`, `cloudflare`.

Frozen with them, and currently *not* specified anywhere:

- **Positional args**: `cache-check <url>`, `cache <action>`,
  `cloudflare <action>`, `vendor [<slug>]`.
- **Flags** (16): `--format` (8 commands), `--dry-run` (3), `--update-all` (2),
  `--all`, `--auth`, `--check-updates`, `--cookie`, `--enable`, `--health`,
  `--if-needed`, `--license`, `--to`, `--type`, `--update`, `--url`, `--vendor`.
- **The `--format=json` payload shapes.** Eight subcommands accept `--format`, and
  0.6.0 explicitly added JSON resolve output as a feature — which means scripts
  and deploy hooks parse these structures, so the *keys* are API just as much as
  the flag names. Nothing documents them today.

**Recommendation:** the authoritative reference must include a JSON schema block
per `--format=json` command, and the 1.0 freeze statement should say explicitly
whether *added* keys are a minor-version-compatible change (they should be:
additive-only, consumers must tolerate unknown keys). Without that sentence you
cannot add a field to `wp upsun info --format=json` after 1.0 without arguing
about it.

`<action>` sub-verbs (`cache flush`, `cloudflare status|purge`) are part of the
contract too and need enumerating.

---

## 4. PHP surface — the biggest unfenced area

| Group | Count | Verdict |
|---|---|---|
| `Upsun\*()` helper functions (`helpers.php`) | 12 | **FREEZE** — the documented facade, all safe off-platform |
| Extension interfaces | 4 | **FREEZE + document** |
| `public static` methods on `Environment`, `Vendor`, `Sanitizers`, `Migrations`, `CacheCheck`, `RelationshipHealth`, `ModuleRegistry`, `IntegrationRegistry` | ~70 | **mark `@internal`** except a named allowlist |

**Interfaces (4).** `Module` (`should_load`, `register`), `Integration`
(`label`, `is_active`, `register`), `Fetcher` (`id`, `supports`,
`available_update`), `FetcherStatus` (`label`, `is_available`). All four have
excellent docblocks — and **none of them is mentioned in README** (no
`Upsun\Module`, `Upsun\Fetcher`, or `implements` anywhere in the file). This is
exactly the `CONTRIBUTING.md` gap: the extension contract is the most
1.0-relevant part of the API and it is currently only readable by opening `src/`.

**The ~70 statics.** Nothing marks these internal, so semver-wise 1.0 would
freeze all of them. `Vendor` alone exposes 20, most of them plumbing the engine
owns: `fetch_zip()`, `extract_zip()`, `remove_tree()`, `copy_tree()`,
`build_composer_json()`, `merge_composer_json()`, `classify_updates()`,
`detect_type()`, `pick_fetcher()`. Same shape in `Sanitizers` (the four
individual `anonymize_*`/`scrub_*` runners), `CacheCheck::analyze()`,
`RelationshipHealth::human_bytes()`.

Suggested public allowlist — everything else gets `@internal`:

- `Environment::*` — a stable, thin env reader; the 12 helpers are already a
  facade over it, so either freeze both or mark `Environment` internal and let
  the helpers be the only contract. **I'd mark it `@internal` and freeze only the
  helpers** — one facade, not two.
- `ModuleRegistry::status()`, `IntegrationRegistry::status()`,
  `IntegrationRegistry::instance()`, `Vendor::fetcher_status()` — the reporting
  API the dashboard and `doctor` use; plausibly wanted by consumers.
- `*::disable_constant_name()` / `Vendor::fetcher_disable_constant_name()` —
  useful for tooling that reports how to switch something off.
- `Vendor::check()`, `Vendor::update()`, `Vendor::export()`,
  `Vendor::resolve_update()`, `Vendor::available_updates()`,
  `Vendor::resolvable_updates()` — the high-level vendoring operations.
- `Migrations::status()`, `Migrations::pending()`, `Migrations::run()`,
  `Sanitizers::registry()`, `Sanitizers::run()`, `CacheCheck::run()`,
  `RelationshipHealth::check()`, `Migrations::OPTION_PREFIX`,
  `Sanitizers::ANON_EMAIL_DOMAIN`.

`@internal` costs nothing to add, breaks no caller (tests included), and is the
difference between freezing ~30 methods and freezing ~70.

---

## 5. Structural findings (not per-symbol)

1. **Filters applied at multiple sites** (§1c) — 4 filters applied 2–3× each.
   Centralize behind `@internal` accessors before the doc promises "applied once."
2. **Filter toggles have no generic family; constants do** (§1b) — 8/13 module
   coverage vs 13/13.
3. **Extension interfaces are undocumented** (§4) — the `CONTRIBUTING.md` /
   API-reference gap, and the highest-leverage doc work for 1.0.
4. **`--format=json` shapes are an undocumented frozen contract** (§3).
5. **The supported-version floor is part of the contract too.** README claims WP
   6.0+; CI runs PHP 8.1/8.4 only and no WP matrix. The 1.0 statement should name
   the exact tested matrix, which is workstream 2's output — this is the concrete
   dependency between the audit and the integration harness, and the reason the
   harness should land in the same cycle as (or before) the freeze.
6. **README is the only reference** (373 lines, hooks + constants + commands +
   helpers). Its hook coverage is complete, which is a real strength — but it has
   no anchors per symbol, no signatures for the JSON payloads, no interface docs,
   and no version-added column. A versioned reference should be generated from
   the docblocks (they are uniformly good enough to generate from) rather than
   hand-maintained a second time.

---

## 6. Proposed deprecation policy

Pre-1.0 (0.7): every RENAME lands as a new canonical name plus
`apply_filters_deprecated( 'old_name', …, '0.7.0', 'new_name' )`, so existing
consumers (KEDS) keep working with a notice. Shims are removed at 1.0 — the last
free window, and the reason to do the renames now rather than argue them later.

Post-1.0:

- A public symbol is deprecated in a **minor**, never removed in one.
- Removal happens in the **next major**, with a minimum of two minors of overlap.
- Filters deprecate via `apply_filters_deprecated()`; actions via
  `do_action_deprecated()`; PHP functions/methods via `_deprecated_function()`;
  constants by continuing to honour them plus a `doctor`/Site Health notice
  (there is no WP core helper for constants — the `doctor` check is the
  mechanism, and it fits the existing reporting model).
- Additive changes that are **not** breaking, stated explicitly: new filters, new
  keys in `--format=json` output, new keys in a filtered array, new optional CLI
  flags, new modules/integrations/fetchers in the default registries.
- Breaking, therefore major-only: removing/renaming any frozen symbol, changing a
  filter's parameter order or type, changing a default in a way that changes
  behaviour, removing a JSON key, tightening the PHP/WP floor.

---

## 7. Suggested sequencing for the freeze

Fits inside the 0.7 slot already sketched, and deliberately front-loads the
free-to-do-now items:

1. **Free, no behaviour change** — add `@internal` to the ~40 non-allowlist
   statics; document `UPSUN_MU_PLUGIN_DIR`/`_VERSION`, the two missing
   integration constants, `UPSUN_DISABLE_FETCHER_TRANSIENT`.
2. **Free, internal only** — centralize the 4 multi-site filters behind
   accessors (§1c).
3. **Breaking, shimmed** — the 8 renames (§1a, §1e).
4. **Breaking, shimmed** — introduce `upsun_module_enabled` /
   `upsun_integration_enabled` / `upsun_fetcher_enabled`; deprecate the 8
   `*_enabled` filters (§1b).
5. **Breaking, unshimmed** — remove `upsun_cloudflare_restore_remote_addr`,
   `restore_client_ip()`, and `$_SERVER['UPSUN_ORIGINAL_REMOTE_ADDR']` (§1d), or
   take the rename-and-document alternative.
6. **Docs** — generate the versioned reference from docblocks, including the
   4 interfaces and the `--format=json` schemas; publish the deprecation policy.

Steps 1–2 are worth doing regardless of how the rest is decided: they shrink the
frozen surface from ~130 symbols to ~90 without changing a single behaviour.

---

## Method

```bash
# 51 filters (the -A2 pass catches multi-line apply_filters calls)
grep -rn "apply_filters" src upsun.php upsun-loader.php | wc -l
grep -rhoE "apply_filters\s*\(\s*'[a-z0-9_]+'" src upsun.php | sed -E "s/.*'(.*)'/\1/" | sort -u
grep -rn -A2 "apply_filters($" src | grep -E "'[a-z_]+'"

# 1 action (named via a class constant, so grep the constant too)
grep -rn "do_action" src | grep -v tests

# constants, commands, helpers, interfaces
grep -rhoE "\bUPSUN_[A-Z0-9_]+\b" src upsun.php upsun-loader.php | sort -u
grep -nE "public function" src/Cli/UpsunCommand.php
grep -nE "^\s*function " src/helpers.php
grep -rln "^interface " src

# documented-vs-implemented
grep -ohE "upsun_[a-z0-9_]+" README.md | sort -u
```
