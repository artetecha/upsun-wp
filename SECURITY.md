# Security policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report privately through GitHub's [private vulnerability
reporting](https://github.com/artetecha/upsun-wp/security/advisories/new) on this
repository. If that is unavailable to you, email the maintainer address on the
[Packagist page](https://packagist.org/packages/artetecha/upsun-wp) with
`upsun-wp security` in the subject.

Useful in a report, roughly in order of usefulness:

- what an attacker gains, and what position they need to start from (anonymous
  visitor / logged-in subscriber / administrator / operator with CLI access);
- the affected version, and whether the site runs on Upsun or off-platform;
- a reproduction — a request, a `wp upsun` invocation, or a filter callback;
- whether a module or integration has to be enabled for it to apply.

What to expect: an acknowledgement within **5 working days**, an assessment
within **10 working days**, and a fix released before the report is made public.
This is a small project maintained alongside other work, not a funded security
programme, and there is no bug bounty. Credit in the advisory and the changelog
unless you would rather not be named.

## Supported versions

| Version | Supported |
|---|---|
| latest `0.x` minor | ✅ fixes land here |
| older `0.x` minors | ❌ upgrade to the latest minor |

Pre-1.0, only the newest minor gets fixes: there is no long-term support branch
to backport to, and upgrading within `0.x` is deliberately cheap — breaking
changes are documented in [README's Deprecations
section](README.md#deprecations) and every renamed filter keeps working for a
release. After 1.0 this table gains the supported majors.

The tested matrix is **PHP 8.1–8.5 × WordPress 6.0+**, enforced in CI (see
[Development](README.md#development)). Reports against older versions of either
are welcome but may be closed as unsupported.

## Scope

In scope: anything in this repository — the modules, integrations, fetchers, the
`wp upsun` commands, the vendoring engine, and the mu-plugin loader.

Out of scope, because they are not this plugin's to fix:

- vulnerabilities in WordPress core, in third-party plugins or themes, or in the
  vendors whose update endpoints the fetchers talk to;
- the Upsun platform itself (report those to
  [Upsun](https://upsun.com/) / Platform.sh);
- a site's own configuration — a missing HTTP access control on a reachable
  preview, a leaked `CLOUDFLARE_API_TOKEN`, a world-readable mount;
- anything requiring the attacker to already run PHP inside the site. Any code
  loaded into the WordPress process is inside this plugin's trust boundary; see
  [the threat model](docs/threat-model.md) for what that implies.

## Threat model

[`docs/threat-model.md`](docs/threat-model.md) documents the privileged surfaces
(the vendoring engine, the Cloudflare origin guard, the DB-writing sanitizers,
SMTP), the guards on each, and the risks knowingly accepted. Read it before
reporting a design decision as a vulnerability — it may already be there with
the reasoning, and if you disagree with the reasoning that is worth an issue.
