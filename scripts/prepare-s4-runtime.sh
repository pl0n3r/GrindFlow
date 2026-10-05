#!/usr/bin/env bash
set -euo pipefail

umask 077

fail() {
  printf 'S4_RUNTIME_PREPARE_ERROR:%s\n' "$1" >&2
  exit 1
}

expected_sha="${EXPECTED_SHA:-}"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail "invalid-expected-sha"

root="$(pwd -P)"
symfony_root="$root/symfony"
composer_json="$root/symfony/composer.json"
composer_lock="$root/symfony/composer.lock"
[[ -f "$root/.env" ]] || fail "environment-missing"
[[ -f "$composer_json" && -f "$composer_lock" ]] || fail "symfony-lock-inputs-missing"

current_sha="$(git -C "$root" rev-parse HEAD 2>/dev/null || true)"
[[ "$current_sha" == "$expected_sha" ]] || fail "checkout-sha-mismatch"

php_bin="${PHP_BIN:-/opt/alt/php85/usr/bin/php}"
composer_bin="${COMPOSER_BIN:-composer2}"
if [[ "$php_bin" == */* ]]; then
  [[ -x "$php_bin" ]] || fail "php-unavailable"
else
  command -v "$php_bin" >/dev/null 2>&1 || fail "php-unavailable"
fi
if [[ "$composer_bin" == */* ]]; then
  [[ -x "$composer_bin" ]] || fail "composer-unavailable"
else
  command -v "$composer_bin" >/dev/null 2>&1 || fail "composer-unavailable"
fi

"$php_bin" -r '$v=@parse_ini_file($argv[1], false, INI_SCANNER_RAW); exit(is_array($v) && (($v["APP_PHASE"] ?? null) === "construccion") ? 0 : 9);' "$root/.env" >/dev/null 2>&1   || fail "phase-not-construction"

stage="$symfony_root/.vendor-stage-$$"
backup="$symfony_root/.vendor-backup-$$"
had_previous=false

cleanup() {
  rm -rf "$stage"
  if [[ -d "$backup" && ! -e "$symfony_root/vendor" ]]; then
    mv "$backup" "$symfony_root/vendor" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT HUP INT TERM

[[ ! -e "$stage" && ! -e "$backup" ]] || fail "temporary-path-collision"
if [[ -L "$symfony_root/vendor" || ( -e "$symfony_root/vendor" && ! -d "$symfony_root/vendor" ) ]]; then
  fail "vendor-target-invalid"
fi

if ! COMPOSER_VENDOR_DIR="$stage" "$composer_bin" --working-dir="$symfony_root" install   --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts --no-plugins --no-progress --quiet   >/dev/null 2>&1; then
  fail "composer-install-failed"
fi

[[ -f "$stage/autoload.php" ]] || fail "staged-autoload-missing"
"$php_bin" -r 'require $argv[1];' "$stage/autoload.php" >/dev/null 2>&1   || fail "staged-autoload-invalid"

current_sha_after_prepare="$(git -C "$root" rev-parse HEAD 2>/dev/null || true)"
[[ "$current_sha_after_prepare" == "$expected_sha" ]] || fail "checkout-sha-changed-during-prepare"

if [[ -d "$symfony_root/vendor" ]]; then
  mv "$symfony_root/vendor" "$backup" || fail "vendor-backup-failed"
  had_previous=true
fi

if ! mv "$stage" "$symfony_root/vendor"; then
  if [[ "$had_previous" == true && -d "$backup" ]]; then
    mv "$backup" "$symfony_root/vendor" >/dev/null 2>&1 || true
  fi
  fail "vendor-promotion-failed"
fi

if ! "$php_bin" -r 'require $argv[1];' "$symfony_root/vendor/autoload.php" >/dev/null 2>&1; then
  rm -rf "$symfony_root/vendor"
  if [[ "$had_previous" == true && -d "$backup" ]]; then
    mv "$backup" "$symfony_root/vendor" || fail "vendor-rollback-failed"
  fi
  fail "promoted-autoload-invalid"
fi

rm -rf "$backup"
trap - EXIT HUP INT TERM
printf 'S4_RUNTIME_PREPARE_OK\n'
