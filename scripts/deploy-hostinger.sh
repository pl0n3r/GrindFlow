#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-/opt/alt/php85/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer2}"
SMOKE_URL="${SMOKE_URL:-}"

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

[[ -x "$PHP_BIN" ]] || fail "PHP binary not found or not executable: $PHP_BIN"
command -v "$COMPOSER_BIN" >/dev/null 2>&1 || fail "Composer command not found: $COMPOSER_BIN"
[[ -f artisan ]] || fail "Run this script from the GrindFlow repository root."
[[ -f composer.json ]] || fail "composer.json not found."
[[ -f .env ]] || fail ".env is missing. Create it outside source control before deploying."

PHP_VERSION_ID="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')"
if (( PHP_VERSION_ID < 80401 )); then
  fail "GrindFlow requires PHP >= 8.4.1. Selected binary reports $("$PHP_BIN" -r 'echo PHP_VERSION;')."
fi

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
  fail "APP_KEY is missing or invalid. Generate it once with: $PHP_BIN artisan key:generate"
fi

printf 'Using PHP: %s\n' "$("$PHP_BIN" -r 'echo PHP_VERSION;')"
printf 'Installing production Composer dependencies...\n'
"$COMPOSER_BIN" install   --no-dev   --prefer-dist   --no-interaction   --optimize-autoloader

printf 'Refreshing Laravel caches...\n'
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan route:clear

"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

printf 'Laravel bootstrap check...\n'
"$PHP_BIN" artisan about --only=environment,cache,drivers >/dev/null

if [[ -n "$SMOKE_URL" ]]; then
  command -v curl >/dev/null 2>&1 || fail "curl is required for SMOKE_URL checks."
  HEALTH_URL="${SMOKE_URL%/}/up"
  printf 'Smoke testing %s...\n' "$HEALTH_URL"
  curl --fail --silent --show-error --location --max-time 15 "$HEALTH_URL" >/dev/null
fi

cat <<'EOF'
Deploy preparation completed.

This script intentionally does NOT run database migrations.
Production migrations require an explicit operator action with the migration
database role after reviewing the pending migration set.
EOF
