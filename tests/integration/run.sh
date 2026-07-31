#!/usr/bin/env bash
#
# WordPress integration harness.
#
# Builds a throwaway consumer project the way the README documents, installs
# real WordPress against a real database, and runs the integration scripts
# against it — off-platform, on-platform (faked PLATFORM_* variables), and
# over HTTP through the PHP built-in server.
#
# Runs in CI (see .github/workflows/tests.yml) and locally. It needs a MySQL
# or MariaDB server; the quickest local one is a container:
#
#   docker run --rm -d -p 3306:3306 -e MARIADB_ROOT_PASSWORD=root \
#     -e MARIADB_DATABASE=wp --name upsun-wp-db mariadb:11
#   bash tests/integration/run.sh
#   docker rm -f upsun-wp-db
#
# Environment:
#   WP_CORE   johnpbloch/wordpress-core constraint (default ^7.0)
#   DB_HOST   default 127.0.0.1
#   DB_NAME   default wp
#   DB_USER   default root
#   DB_PASS   default root
#   PORT      built-in server port (default 8089)
#   WORK_DIR  scratch directory (default <tmp>/upsun-wp-integration)
#   KEEP      set to 1 to leave WORK_DIR in place for inspection

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Deliberately outside the repository: the consumer project copies the plugin
# directory through a Composer path repository, and a work directory inside it
# would be copied into itself.
WORK_DIR="${WORK_DIR:-${TMPDIR:-/tmp}/upsun-wp-integration}"
WORK_DIR="${WORK_DIR%/}"

WP_CORE="${WP_CORE:-^7.0}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-wp}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
PORT="${PORT:-8089}"
KEEP="${KEEP:-0}"

SITE_URL="http://127.0.0.1:${PORT}"
WP_DIR="${WORK_DIR}/consumer/wordpress"
SERVER_PID=""

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
die() { printf '\n\033[31mFAILED: %s\033[0m\n' "$1" >&2; exit 1; }

cleanup() {
	if [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null; then
		kill "${SERVER_PID}" 2>/dev/null || true
		wait "${SERVER_PID}" 2>/dev/null || true
	fi

	# PHP_CLI_SERVER_WORKERS makes php -S fork children that share the
	# listening socket, and they do not die with the parent — so killing only
	# the tracked PID leaks workers that keep holding the port and break the
	# next run. Match on our exact port so this can never touch an unrelated
	# server.
	if command -v pgrep >/dev/null; then
		local stragglers
		stragglers="$(pgrep -f "php -S 127.0.0.1:${PORT}" 2>/dev/null || true)"

		if [[ -n "${stragglers}" ]]; then
			# shellcheck disable=SC2086 -- deliberate word splitting on PIDs.
			kill ${stragglers} 2>/dev/null || true
		fi
	fi

	if [[ "${KEEP}" != "1" ]]; then
		rm -rf "${WORK_DIR}"
	else
		printf '\nWork directory kept at %s\n' "${WORK_DIR}"
	fi
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Tooling
# ---------------------------------------------------------------------------

command -v php >/dev/null || die 'php not found'
command -v composer >/dev/null || die 'composer not found'
command -v curl >/dev/null || die 'curl not found'

rm -rf "${WORK_DIR}"
mkdir -p "${WORK_DIR}"

# WP-CLI's bundled php-cli-tools trips deprecation notices on newer PHP, which
# would otherwise land in the command output the assertions grep. 24575 is
# E_ALL & ~E_DEPRECATED; plugin-side deprecations still surface through
# WP_DEBUG in the eval-file scripts.
export WP_CLI_PHP_ARGS='-d error_reporting=24575'

# Only reached when no wp is on PATH — CI installs one. This branch fetches and
# then executes remote PHP (with --allow-root in a container), so it takes an
# immutable versioned release asset and verifies it against a pinned digest
# rather than the rolling "latest stable" build.
WP_CLI_VERSION='2.12.0'
WP_CLI_SHA512='be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832'

if command -v wp >/dev/null; then
	WP_CLI=(wp)
else
	say "Downloading WP-CLI ${WP_CLI_VERSION}"

	PHAR="${WORK_DIR}/wp-cli.phar"

	# -f so an HTTP error is a failure rather than an error page written to
	# the phar and executed as PHP.
	curl -fsSL -o "${PHAR}" \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
		|| die "could not download WP-CLI ${WP_CLI_VERSION}"

	if command -v sha512sum >/dev/null; then
		PHAR_SHA512="$(sha512sum "${PHAR}" | awk '{print $1}')"
	elif command -v shasum >/dev/null; then
		PHAR_SHA512="$(shasum -a 512 "${PHAR}" | awk '{print $1}')"
	else
		die 'neither sha512sum nor shasum is available to verify the WP-CLI download'
	fi

	if [[ "${PHAR_SHA512}" != "${WP_CLI_SHA512}" ]]; then
		die "WP-CLI checksum mismatch — expected ${WP_CLI_SHA512}, got ${PHAR_SHA512}"
	fi

	WP_CLI=(php -d error_reporting=24575 "${PHAR}")
fi

# Never run WP-CLI as root without --allow-root (CI containers do).
if [[ "$(id -u)" == "0" ]]; then
	WP_CLI+=(--allow-root)
fi

wpcli() { "${WP_CLI[@]}" --path="${WP_DIR}" "$@"; }

# ---------------------------------------------------------------------------
# Consumer project
#
# The documented inside-core-dir layout: the package installs to a staging
# directory (core extraction replaces the whole wordpress/ tree, and
# alphabetical install order puts artetecha/* before johnpbloch/*), then a
# postbuild script copies it in with the loader shim.
# ---------------------------------------------------------------------------

say "Building consumer project (WordPress ${WP_CORE}, PHP $(php -r 'echo PHP_VERSION;'))"

mkdir -p "${WORK_DIR}/consumer"
cat > "${WORK_DIR}/consumer/composer.json" <<JSON
{
  "repositories": [
    { "type": "path", "url": "${PLUGIN_DIR}", "options": { "symlink": false } }
  ],
  "require": {
    "artetecha/upsun-wp": "@dev",
    "johnpbloch/wordpress-core": "${WP_CORE}",
    "johnpbloch/wordpress-core-installer": "^2.0",
    "composer/installers": "^2.0"
  },
  "extra": {
    "wordpress-install-dir": "wordpress",
    "installer-paths": {
      "composer-mu-plugins/{\$name}": ["artetecha/upsun-wp"],
      "wordpress/wp-content/mu-plugins/{\$name}": ["type:wordpress-muplugin"]
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
  },
  "config": {
    "allow-plugins": {
      "composer/installers": true,
      "johnpbloch/wordpress-core-installer": true
    }
  }
}
JSON

(cd "${WORK_DIR}/consumer" && composer install --no-interaction --no-progress --no-dev)

[[ -f "${WP_DIR}/wp-content/mu-plugins/upsun/upsun.php" ]] || die 'plugin not installed into mu-plugins/upsun/'
[[ -f "${WP_DIR}/wp-content/mu-plugins/upsun-loader.php" ]] || die 'loader shim not copied'

# ---------------------------------------------------------------------------
# WordPress install
# ---------------------------------------------------------------------------

say 'Installing WordPress'

php "${PLUGIN_DIR}/tests/integration/reset-db.php" "${DB_HOST}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}"

wpcli config create \
	--dbname="${DB_NAME}" --dbuser="${DB_USER}" --dbpass="${DB_PASS}" --dbhost="${DB_HOST}" \
	--force --skip-check
wpcli core install \
	--url="${SITE_URL}" --title='Upsun integration' \
	--admin_user=admin --admin_password='integration-only' \
	--admin_email=admin@example.invalid --skip-email

wpcli core version

# ---------------------------------------------------------------------------
# Off-platform phase — no PLATFORM_* variables
# ---------------------------------------------------------------------------

say 'Off-platform assertions'

wpcli eval-file "${PLUGIN_DIR}/tests/integration/off-platform.php"

# The CLI's own off-platform reporting, asserted on the real command output.
wpcli upsun info | grep -q 'Not running on Upsun' || die 'wp upsun info did not report off-platform'
wpcli upsun doctor | grep -q 'Not running on Upsun' || die 'wp upsun doctor did not report off-platform'

# ---------------------------------------------------------------------------
# On-platform phase — faked PLATFORM_* variables
# ---------------------------------------------------------------------------

b64() { printf '%s' "$1" | base64 | tr -d '\n'; }

ROUTES_JSON=$(cat <<JSON
{"${SITE_URL}/":{"primary":true,"type":"upstream","upstream":"app:http","cache":{"enabled":true,"default_ttl":0,"cookies":["*"]}}}
JSON
)

RELATIONSHIPS_JSON=$(cat <<JSON
{"database":[{"host":"${DB_HOST}","port":3306,"scheme":"mysql","username":"${DB_USER}","password":"${DB_PASS}","path":"${DB_NAME}","service":"mysql"}]}
JSON
)

APPLICATION_JSON='{"name":"app","type":"php:8.3","mounts":{"wp-content/uploads":{"source":"storage","source_path":"uploads"}}}'

export PLATFORM_APPLICATION_NAME='app'
export PLATFORM_ENVIRONMENT='pr-42'
export PLATFORM_ENVIRONMENT_TYPE='staging'
export PLATFORM_BRANCH='feature/harness'
export PLATFORM_PROJECT='abcdef123456'
export PLATFORM_APP_DIR="${WP_DIR}"
export PLATFORM_ROUTES="$(b64 "${ROUTES_JSON}")"
export PLATFORM_RELATIONSHIPS="$(b64 "${RELATIONSHIPS_JSON}")"
export PLATFORM_APPLICATION="$(b64 "${APPLICATION_JSON}")"
export UPSUN_IT_SITE_URL="${SITE_URL}"
export UPSUN_IT_DB_HOST="${DB_HOST}"

say 'On-platform assertions'

wpcli eval-file "${PLUGIN_DIR}/tests/integration/on-platform.php"

say 'Kill switch on-platform'

# UPSUN_MU_DISABLE is a documented public constant, so it needs coverage on the
# platform it exists for: with the PLATFORM_* variables present and the switch
# set, the plugin must behave exactly as it does off-platform. --exec runs
# before WordPress loads, so the constant is defined before mu-plugins boot.
wpcli --exec="define( 'UPSUN_MU_DISABLE', true );" eval-file "${PLUGIN_DIR}/tests/integration/kill-switch.php" \
	|| die 'UPSUN_MU_DISABLE did not fully no-op on-platform'

say 'CLI reporting on-platform'

wpcli upsun info | grep -q 'pr-42' || die 'wp upsun info did not report the environment'
wpcli upsun info --format=json | php -r 'exit( is_array( json_decode( stream_get_contents( STDIN ), true ) ) ? 0 : 1 );' \
	|| die 'wp upsun info --format=json did not emit valid JSON'
wpcli upsun relationships | grep -q 'database' || die 'wp upsun relationships did not list the relationship'

# doctor exits non-zero only on a failing check; on this synthetic environment
# warnings are expected, failures are not.
wpcli upsun doctor || die 'wp upsun doctor exited non-zero'

# ---------------------------------------------------------------------------
# HTTP phase — the headers only a real request can prove
# ---------------------------------------------------------------------------

say "Serving ${SITE_URL}"

# WordPress spawns wp-cron through a loopback request to itself on front-end
# hits. The PHP built-in server is single-process by default, so that request
# arrives while the server is busy answering the one that triggered it and gets
# dropped — which showed up as an intermittent "curl: (52) Empty reply from
# server" here. Both halves are fixed: DISABLE_WP_CRON is what this plugin's own
# doctor check tells you to set on Upsun anyway (cron belongs in the platform
# scheduler), and the workers make the server able to answer a second connection
# regardless.
wpcli config set DISABLE_WP_CRON true --raw
export PHP_CLI_SERVER_WORKERS=4

# A listener already on the port would answer the assertions below from
# somewhere else entirely — a false green. Best-effort pre-flight; the
# authoritative check is that our own server is alive after the wait.
if command -v lsof >/dev/null && lsof -iTCP:"${PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
	die "port ${PORT} is already in use — stop that process, or re-run with PORT=<other>"
fi

# Started directly rather than in a subshell: a subshell's PID is not the
# server's, so cleanup() would kill the wrapper and orphan php -S still bound
# to the port, breaking the next local run.
php -S "127.0.0.1:${PORT}" -t "${WP_DIR}" > "${WORK_DIR}/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
	if curl -fs -o /dev/null "${SITE_URL}/" 2>/dev/null; then
		break
	fi
	sleep 0.25
done

# Our server, not merely something on the port: if php -S failed to bind it
# would have exited, and everything below would be asserted against a stranger.
if ! kill -0 "${SERVER_PID}" 2>/dev/null; then
	cat "${WORK_DIR}/server.log"
	die 'the built-in server exited (see the log above; a stale listener on the port is the usual cause)'
fi

if ! curl -fsS -o /dev/null "${SITE_URL}/"; then
	cat "${WORK_DIR}/server.log"
	die 'built-in server never answered'
fi

# Header fetches get a bounded retry: an empty reply from a dev server is worth
# one more attempt, but a persistently broken response must still fail the run
# (with the server log, which is where the reason will be).
fetch_headers() {
	local attempt output
	for attempt in 1 2 3; do
		if output=$(curl -fsS -D - -o /dev/null "$@" 2>/dev/null); then
			printf '%s' "${output}"
			return 0
		fi
		sleep 1
	done

	cat "${WORK_DIR}/server.log" >&2
	die "no usable response from ${SITE_URL} after 3 attempts (curl args: $*)"
}

ANON_HEADERS=$(fetch_headers "${SITE_URL}/")
COOKIE_HEADERS=$(fetch_headers -H 'Cookie: wordpress_logged_in_harness=1' "${SITE_URL}/")

# Present in the anonymous response.
expect() {
	if ! grep -qi -- "$1" <<< "${ANON_HEADERS}"; then
		printf '\n%s\n' "${ANON_HEADERS}" >&2
		die "expected response header: $1"
	fi

	printf '  ok    %s\n' "$2"
}

# Absent from the anonymous response.
expect_absent() {
	if grep -qi -- "$1" <<< "${ANON_HEADERS}"; then
		printf '\n%s\n' "${ANON_HEADERS}" >&2
		die "unexpected response header: $1"
	fi

	printf '  ok    %s\n' "$2"
}

# Absent from the response to a request carrying a session cookie.
refute() {
	if grep -qi -- "$1" <<< "${COOKIE_HEADERS}"; then
		printf '\n%s\n' "${COOKIE_HEADERS}" >&2
		die "unexpected response header for a personalised request: $1"
	fi

	printf '  ok    %s\n' "$2"
}

printf '\n  Anonymous request\n'
expect 's-maxage=600' 'page cache sets the shared-cache TTL'
expect 'max-age=0' 'browsers keep revalidating'
expect 'X-Robots-Tag: noindex' 'preview protection sends noindex'
expect 'X-Content-Type-Options: nosniff' 'security headers: nosniff'
expect 'Referrer-Policy: strict-origin-when-cross-origin' 'security headers: referrer policy'
expect 'X-Frame-Options: SAMEORIGIN' 'security headers: frame options'

# Non-production and plain HTTP: HSTS must not be pinned on a preview.
expect_absent 'Strict-Transport-Security' 'no HSTS on a non-production plaintext request'

printf '\n  Request carrying a session cookie\n'
refute 's-maxage' 'page cache stands down for a personalised request'

printf '\n  wp upsun cache-check against the live server\n'
CACHE_CHECK=$(wpcli upsun cache-check /)
printf '%s\n' "${CACHE_CHECK}"
grep -qi 'cacheable' <<< "${CACHE_CHECK}" || die 'cache-check did not report the page cacheable'
grep -q '600' <<< "${CACHE_CHECK}" || die 'cache-check did not report the TTL'

say "All integration assertions passed (WordPress ${WP_CORE}, PHP $(php -r 'echo PHP_VERSION;'))"
