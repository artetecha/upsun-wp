# Changelog

Notable changes per release. Versions are the package version and
`UPSUN_MU_PLUGIN_VERSION`; each shipped release has a matching git tag and a
[Packagist](https://packagist.org/packages/artetecha/upsun-wp) version.

Pre-1.0, breaking changes may land in a minor — they are called out under
**Breaking** and, where a rename is involved, the old name keeps working for at
least one release. From 1.0 the [deprecation
policy](docs/api-reference.md#deprecation-policy) applies.

## 0.8.0 — 2026-07-27

Security review of the privileged surfaces, and the documentation a 1.0
commitment needs.

### Security

- **Fixed a transport downgrade in the vendoring engine.** `Vendor::fetch_zip()`
  required https of the URL it was given, but WordPress follows up to five
  redirects itself and validates them only when `reject_unsafe_urls` is set — so
  an https download could redirect to `http` and the fetch whose bytes get
  committed and executed would run over cleartext. Redirects are now followed one
  hop at a time with the scheme re-checked before each request, the chain is
  bounded at five hops, and a refused chain deletes the partial file.
- **Credentials no longer follow a redirect off-origin.** The `Fetcher` contract
  permits auth headers on the resolved download, and the vendor endpoint is
  untrusted — so a compromised one could 302 to a host it controls and collect a
  licence token. Every caller header is dropped when the scheme, host or port
  changes, as browsers and curl do with `Authorization`.
- **Bounded what an external archive can cost.** Downloads are capped at 256 MB
  (enforced while streaming), and the declared uncompressed size is capped at 1 GB
  and checked before anything is extracted.
- **Hardened the vendoring work directory** — `random_bytes()` name, mode `0700`,
  and a failed `mkdir()` now throws instead of continuing into a directory the
  process may not own.
- Protocol-relative redirects (`//host/path`) are resolved correctly instead of
  being treated as a path on the original host.

### Added

- [`SECURITY.md`](SECURITY.md) — private reporting process, response
  expectations, supported versions, and an explicit out-of-scope list.
- [`docs/threat-model.md`](docs/threat-model.md) — the four privileged surfaces
  (vendoring engine, Cloudflare origin guard, DB-writing sanitizers, SMTP), the
  guards on each, and the risks knowingly accepted. Most importantly: any plugin
  loaded into WordPress can read a secret passed through a filter.
- [`docs/api-reference.md`](docs/api-reference.md) — the authoritative, versioned
  reference for the whole public surface, **generated** from the source by
  `bin/api-reference.php`. A test runs the generator in `--check` mode, so the
  reference cannot drift from the code.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — how to add a module, an integration, or
  a fetcher, with the test and deprecation conventions.
- This changelog.
- Docblocks for `upsun_cloudflare_auto_purge` and
  `upsun_page_cache_debug_headers`, which had none — surfaced by generating the
  reference from docblocks.

### Changed

- The unit suite's HTTP stubs became a shared queue with a request log, so
  transport behaviour (redirect chains, refused hops) is assertable.
- The integration harness sets `DISABLE_WP_CRON` and runs the built-in server
  with `PHP_CLI_SERVER_WORKERS=4`: WordPress's wp-cron loopback request against a
  single-process server was dropping connections and making the HTTP phase
  intermittently fail.

## 0.7.0 — 2026-07-26

The road to 1.0. No new features, on purpose: this release earns the confidence
to freeze the public API.

### Breaking

Every old name below still works and reports itself through `_deprecated_hook()`
when `WP_DEBUG` is on. They are removed at 1.0. Mapping and a migration snippet:
[README](README.md#deprecations).

- Renamed: `upsun_mu_modules` → `upsun_modules`,
  `upsun_writable_path_requirements` → `upsun_writable_paths_requirements`,
  `upsun_disk_usage_thresholds` → `upsun_mount_usage_thresholds`,
  `upsun_login_banner` → `upsun_environment_indicator_login_banner`,
  `upsun_sanitize_anonymize_passwords` →
  `upsun_sanitize_anonymize_user_passwords`,
  `upsun_safe_previews_pause_webhooks` → `upsun_woocommerce_pause_webhooks`,
  `upsun_safe_previews_stripe_test_mode` → `upsun_woocommerce_stripe_test_mode`.
- Replaced the eight per-module `*_enabled` toggles with **one**
  `upsun_module_enabled( bool $enabled, string $id )`. The old set covered 8 of
  13 modules; five had no conditional switch at all.
- **Removed** `upsun_cloudflare_restore_remote_addr` (unshimmed), with
  `Cloudflare::restore_client_ip()` and the
  `$_SERVER['UPSUN_ORIGINAL_REMOTE_ADDR']` key it wrote. The router resolves the
  real client IP before PHP runs, so the filter's only reachable effect on Upsun
  was letting a forged `CF-Connecting-IP` override a correct value on a direct
  origin hit.

### Added

- `upsun_integration_enabled` and `upsun_fetcher_enabled`, so all three
  registries have both a constant family and a filter.
- [`docs/api-audit-1.0.md`](docs/api-audit-1.0.md) — every public symbol
  classified with a freeze verdict.
- A **WordPress integration harness** (`tests/integration/`): real WordPress, a
  real database, module boot, the kill switch, the `wp upsun` commands, and the
  response headers asserted over HTTP.
- CI matrix of **PHP 8.1–8.5 × WordPress 6.0 to 7.x**, so the documented floor is
  enforced rather than claimed.

### Changed

- ~40 public statics outside the documented facade are marked `@internal`
  (`Upsun\Environment` at class level), shrinking what 1.0 freezes from ~130
  symbols to ~90.
- `upsun_page_cache_ttl`, `_bypass_cookie_patterns`, `_strip_cookies` and
  `upsun_preview_noindex` are each applied at exactly one place, so a consumer
  callback runs once per request instead of up to three times.
- `UPSUN_DISABLE_FETCHER_TRANSIENT` is now honoured — it was documented but never
  read.

## 0.6.0 — 2026-07-24

- Built-in `ThimPressFetcher` (Eduma, thim-core, LearnPress add-ons),
  conditionally active like the integrations and disabled with
  `UPSUN_DISABLE_FETCHER_THIMPRESS`.
- `wp upsun vendor --dry-run --format=json` emits the re-vendor plans for CI. The
  resolved download URL is deliberately omitted: it carries the licence token.
- `vendor_fetchers` reporting in `wp upsun doctor`, Site Health, and the
  dashboard, via the optional `FetcherStatus` interface.

## 0.5.0 — 2026-07-23

- Premium plugin vendoring toolkit: `wp upsun vendor <slug>` exports an installed
  plugin or theme as a Composer package; `--check-updates` and the
  `vendored_updates` check flag premium updates Composer cannot see.
- `--update` re-vendors a new version through a pluggable `Fetcher` registry
  (built-in `TransientFetcher`), merging over the upstream `composer.json`.
  Credentials come from site state, never from the environment.

## 0.4.2 — 2026-07-16

- `security-headers` module: baseline headers on the HTML document, which
  `config.yaml` cannot reach (its `headers` only decorate static files). HSTS is
  emitted directly or deferred to Cloudflare when it fronts the request, so there
  is exactly one source.

## 0.4.1 — 2026-07-15

- `cloudflare`: detect fronting through the `CF-Ray`/`CF-Connecting-IP` headers
  and **stop rewriting** `REMOTE_ADDR` — the Upsun router already resolves the
  real client IP, verified live. The module verifies rather than rewrites.

## 0.4.0 — 2026-07-14

- `cloudflare` module: edge cache purge (`wp upsun cloudflare purge`) — the
  invalidation the Upsun router cache never had — with optional auto-purge on
  post change and an optional shared-secret origin guard.

## 0.3.5 — 2026-07-13

- Fixed: no cron-schedule writes before WordPress is installed.
- Extraction complete — this repository, published on Packagist, with the starter
  template live.

## 0.3.4 — 2026-07-13

- `wp upsun relationships --health`: live probes for MySQL, Redis, and HTTP
  services.
- `mount-usage` module: daily mount measurement and a disk-usage check.
- Documented and tested the install-order hazard for inside-core-dir layouts.

## 0.3.3 and earlier

Deploy migrations (`wp upsun migrate`), the opt-in DB sanitizers, the
writable-path advisor with `wp upsun mounts`, the integrations architecture,
`wp upsun cache-check`, SafePreviews with `wp upsun sanitize`, the cron
heartbeat, the login-screen banner, the "Upsun" wp-admin dashboard, and the core
modules. See [ROADMAP.md](ROADMAP.md) for the per-milestone detail.
