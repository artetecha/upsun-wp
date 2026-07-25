# Threat model

What this plugin is trusted with, what it defends against, and what it knowingly
does not. Written for 0.8 as part of the road to 1.0; reporting instructions are
in [`SECURITY.md`](../SECURITY.md).

## Trust boundaries

Five actors, in decreasing order of privilege:

| Actor | Position | Trusted with |
|---|---|---|
| **Operator** | Upsun SSH, deploy hooks, `wp upsun` | Everything. Can already run arbitrary code in the container. |
| **Administrator** | wp-admin, `manage_options` | The dashboard's actions (object-cache flush, cache-check, sanitize trigger). |
| **Site code** | Any loaded plugin, theme, or mu-plugin | Everything the plugin can reach. See below. |
| **Vendor endpoint** | The update APIs the fetchers call | Nothing. Treated as hostile input. |
| **Visitor** | Anonymous HTTP | Nothing. |

**Site code is inside the boundary, and that is not fixable.** WordPress filters
are a shared bus: any loaded plugin can `add_filter( 'upsun_cloudflare_api_token', … )`
and read the value, because the filter exists so that a consumer *can* supply it
from a secrets manager. The same is true of `upsun_cloudflare_zone_id` and
`upsun_cloudflare_origin_secret`. A malicious plugin in a WordPress install has
far worse options available to it than reading a CDN token, so this is stated
rather than defended: **treat every plugin you install as trusted with your
Cloudflare credentials**, and prefer the environment-variable path (the filters
default to `getenv()`) so the value is not also sitting in a filter callback.

Two consequences worth being explicit about:

- The plugin never tries to defend against code already running in the process.
  A report that requires a hostile plugin is out of scope.
- The plugin does not add capability checks where WordPress already enforces
  them. The dashboard page is registered with `manage_options`, so WordPress
  gates the render; the one state-changing endpoint re-checks anyway (below).

## Privileged surface 1 — the vendoring engine

`wp upsun vendor` downloads a premium plugin or theme from a vendor endpoint,
extracts it, and writes it into a Composer package directory that the operator
then commits. **The output is executable code that will run on every request.**
This is the most sensitive path in the plugin, and the only one where hostile
input becomes code.

What guards it:

| Guard | Where |
|---|---|
| Remote fetches must be **https**, re-checked at **every redirect hop** | `Vendor::download_https()` |
| Redirect chain bounded (5 hops), refused chains delete the partial file | same |
| Download capped at **256 MB** (`limit_response_size`) | same |
| Archive entries refused if absolute, drive-lettered, or containing `..` | `Vendor::extract_zip()` |
| Declared uncompressed size capped at **1 GB**, checked before extracting | same |
| Work directory is `0700` with a `random_bytes()` name; a failed `mkdir()` is fatal | `Vendor::temp_dir()` |
| Credentials come from site state (update transients, vendor registration rows), never env or committed config | the `Fetcher` contract |
| The authenticated download URL is **never** printed, including in `--format=json` | `wp upsun vendor` |

The https-per-hop check exists because WordPress follows up to five redirects
itself and only validates them when `reject_unsafe_urls` is set — so before 0.8 an
https URL could redirect to http and downgrade the one fetch whose bytes get
committed. That was a real hole, fixed and regression-tested
(`tests/VendorFetchSecurityTest.php`).

Accepted risks:

- **No signature or checksum verification.** The vendors these fetchers talk to
  publish neither. https plus a bounded redirect chain is the whole of the
  transport guarantee; beyond that, the mitigation is procedural and real:
  re-vendoring produces a **reviewable diff in version control**, and an
  operator commits it. Read the diff.
- **A compromised vendor endpoint owns the package.** Nothing here can detect
  that. It is the same exposure as any plugin auto-update, except that the
  commit step makes it visible.
- **SSRF is not blocked.** The download URL comes from site state, so it is
  semi-trusted rather than user input, and `reject_unsafe_urls` is deliberately
  not set: it would also reject private hosts and non-standard ports, breaking
  self-hosted vendor mirrors. An attacker who can already write the update
  transient or the vendor's registration row in the database can point a fetch
  at an internal address — but that attacker has database write access, which is
  a larger problem than one outbound request.
- **A zip bomb below the caps still costs disk.** Writable space on Upsun is a
  finite declared mount; the caps bound the damage rather than eliminate it.

## Privileged surface 2 — the Cloudflare origin guard

Optional, **off by default**, production-only: when a shared secret is
configured, requests to the origin that do not carry it are refused with a 403,
so traffic cannot bypass Cloudflare by hitting `*.platformsh.site` directly.

| Guard | Detail |
|---|---|
| Constant-time comparison | `hash_equals()`, never `===` |
| Disabled unless a secret is set | empty secret ⇒ `allow` |
| Production only | non-production environments are never gated |
| CLI exempt | cron and deploy hooks carry no HTTP headers |
| Secret from the environment | `CLOUDFLARE_ORIGIN_SECRET`, filterable |

Accepted risks: the guard is only as strong as the Cloudflare Transform Rule
injecting the header, and it protects *availability of the bypass path*, not
authentication. It is not a WAF. If the secret leaks, the guard is void — rotate
it in the environment, not in code.

0.7 **removed** the opt-in `REMOTE_ADDR` restoration path
(`upsun_cloudflare_restore_remote_addr`). The Upsun router resolves the real
client IP before PHP runs, so on this platform the filter's only reachable effect
was letting a forged `CF-Connecting-IP` override a correct value on a direct
origin hit. The module verifies the client IP through a health check instead of
rewriting it.

## Privileged surface 3 — the DB-writing sanitizers

`wp upsun sanitize` writes to the database of a preview clone: anonymising user
emails and passwords, deactivating listed plugins, scrubbing listed options.

| Guard | Detail |
|---|---|
| **Hard refusal on production** | `Sanitizers::run()` returns nothing if `Environment::is_production()`, on top of the CLI's own guard |
| Every sanitizer is **opt-in** | all four built-ins are disabled by default; a filter or `--enable` turns one on |
| SQL is built from prepared fragments | `$wpdb->prepare()` per clause; the `WHERE` is never empty, so no unbounded `UPDATE` |
| Idempotent by construction | conditions exclude already-sanitized rows, so a second run reports zero changes |
| Dry-run first | `--dry-run` reports counts without writing |

Accepted risks:

- **The password anonymiser sets known passwords.** That is its purpose: a QA
  team needs to log in. It writes legacy MD5 hashes that WordPress rehashes on
  first login, and it invalidates existing auth cookies. A preview environment
  with known passwords and no access control is an open door — **pair it with
  Upsun's HTTP access control**. The health check warns when preview safety is
  incompletely wired.
- **Consumer sanitizers run with full DB access.** `upsun_preview_sanitizers`
  accepts arbitrary callbacks; they are site code (inside the boundary), and the
  contract asks them to be idempotent and dry-run aware. Nothing enforces that.
- **A clone is production data.** The plugin reduces exposure on previews; it
  does not make a clone safe to expose.

## Privileged surface 4 — SMTP

When `PLATFORM_SMTP_HOST` is present, the `smtp` module points PHPMailer at it:
port 25, `SMTPAuth` off, no TLS (`SMTPSecure` empty, `SMTPAutoTLS` off).

That looks alarming written down, so: the host is the **platform's own relay**,
reached over the internal network from inside the container, and it neither
offers nor requires authentication. There is no credential to leak here and no
external hop to protect. The module only ever uses the platform-provided host —
it does not accept an arbitrary hostname — and it stands down entirely if another
mailer plugin has already configured SMTP (`'smtp' === $phpmailer->Mailer`), so a
consumer wanting an authenticated external relay simply installs one.

What this does not protect against: mail *content* leaving a preview
environment. That is the `safe-previews` module's job — it intercepts `wp_mail`
on non-production by default.

## Cross-cutting

**wp-admin actions.** One state-changing endpoint (object-cache flush):
`current_user_can( 'manage_options' )`, then `check_admin_referer()`, then
`wp_safe_redirect()`. The dashboard is deliberately actions-not-settings and
never writes options, so there is no settings surface to abuse.

**The cache-check panel is SSRF-gated.** It makes an outbound request to a
submitted URL, so it requires a valid nonce and the resolved URL must match one
of *this environment's own routes* (`CacheCheck::is_environment_url()`). The CLI
equivalent has no such allowlist by design — the operator chooses the target, and
they can already make requests from the container.

**`wp upsun cache-check --auth=user:pass` puts credentials in the process
arguments,** where anything reading `/proc` or `ps` on the container can see
them, and they will be sent to whatever host the operator named. Prefer checking
an environment without HTTP access control, or accept the exposure knowingly.

**The migrations directory is executed code.** `UPSUN_MIGRATIONS_DIR` files are
`include`d and their returned callable is invoked during deploy. Keep the
directory in version control and treat it like any other code path — the
filename pattern is validated, the contents are not.

**Preview protection is headers, not options.** `noindex` is emitted per request
rather than written to `blog_public`, so nothing can sync back to production in a
merge. It stops well-behaved crawlers; it is not access control.

## Verified during the 0.8 review

Recorded so a future reader knows these were looked at rather than assumed: the
zip-slip guard, `hash_equals` in the origin guard and its production/CLI gating,
the admin-post capability + nonce + safe-redirect chain, the cache-check route
allowlist, the omission of the authenticated URL from `--format=json`, the
sanitizers' production refusal and prepared-fragment SQL, and `remove_tree()`
unlinking symlinks instead of following them.

Fixed during the same review: the redirect downgrade, the missing download and
expansion caps, and the predictable world-readable work directory.
