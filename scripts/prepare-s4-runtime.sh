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
  php_bin="$(command -v "$php_bin" 2>/dev/null || true)"
  [[ -n "$php_bin" ]] || fail "php-unavailable"
fi
if [[ "$composer_bin" == */* ]]; then
  [[ -f "$composer_bin" && -r "$composer_bin" ]] || fail "composer-unavailable"
  composer_path="$composer_bin"
else
  composer_path="$(command -v "$composer_bin" 2>/dev/null || true)"
  [[ -n "$composer_path" ]] || fail "composer-unavailable"
fi

phase_rc=0
"$php_bin" -r '$v=@parse_ini_file($argv[1], false, INI_SCANNER_RAW); if (!is_array($v) || !array_key_exists("APP_PHASE", $v) || trim((string) $v["APP_PHASE"]) === "") { exit(10); } exit(((string) $v["APP_PHASE"]) === "construccion" ? 0 : 11);' "$root/.env" >/dev/null 2>&1 || phase_rc=$?
case "$phase_rc" in
  0) ;;
  10)
    printf 'S4_RUNTIME_PREPARE_ACTION:add APP_PHASE="construccion" to .env\n' >&2
    fail "phase-missing"
    ;;
  *) fail "phase-not-construction" ;;
esac

composer_log_dir="${S4_RUNTIME_LOG_DIR:-${HOME:-$root}/.grindflow/logs}"
mkdir -p "$composer_log_dir" || fail "composer-log-unavailable"
chmod 0700 "$composer_log_dir" || fail "composer-log-unavailable"
composer_log="$composer_log_dir/s4-runtime-composer.log"
: > "$composer_log" || fail "composer-log-unavailable"
chmod 0600 "$composer_log" || fail "composer-log-unavailable"

composer_command=("$composer_path")
composer_first_line="$(head -n 1 "$composer_path" 2>/dev/null || true)"
if [[ "$composer_path" == *.phar || "$composer_first_line" == '#!'*php* || "$composer_first_line" == '<?php'* ]]; then
  composer_command=("$php_bin" "$composer_path")
fi

classify_composer_failure() {
  if grep -Eqi 'requires[[:space:]]+php|your php version|php version.*(does not|not satisfy)|does not satisfy.*php' "$composer_log"; then
    printf '%s' 'composer-php-version-unsatisfied'
  elif grep -Eqi 'lock file.*not up to date|locked package|installable set of packages|composer.lock.*incompatible' "$composer_log"; then
    printf '%s' 'composer-lock-incompatible'
  elif grep -Eqi 'could not resolve host|network is unreachable|connection timed out|failed to connect|curl error (6|7|28)' "$composer_log"; then
    printf '%s' 'composer-network-unavailable'
  elif grep -Eqi 'allowed memory size|out of memory|memory exhausted' "$composer_log"; then
    printf '%s' 'composer-memory-exhausted'
  else
    printf '%s' 'composer-install-failed'
  fi
}

stage="$symfony_root/.vendor-stage-$$"
backup="$symfony_root/.vendor-backup-$$"
had_previous=false
promotion_started=false
prepared=false

cleanup() {
  rm -rf "$stage"
  if [[ "$prepared" == true ]]; then
    rm -rf "$backup"
    return
  fi

  if [[ "$promotion_started" == true ]]; then
    rm -rf "$symfony_root/vendor"
  fi

  if [[ "$had_previous" == true && -d "$backup" ]]; then
    mv "$backup" "$symfony_root/vendor" >/dev/null 2>&1 || true
  fi
}

abort_on_signal() {
  case "$1" in
    HUP) exit 129 ;;
    INT) exit 130 ;;
    TERM) exit 143 ;;
    *) exit 1 ;;
  esac
}

trap cleanup EXIT
trap 'abort_on_signal HUP' HUP
trap 'abort_on_signal INT' INT
trap 'abort_on_signal TERM' TERM

[[ ! -e "$stage" && ! -e "$backup" ]] || fail "temporary-path-collision"
if [[ -L "$symfony_root/vendor" || ( -e "$symfony_root/vendor" && ! -d "$symfony_root/vendor" ) ]]; then
  fail "vendor-target-invalid"
fi

if ! COMPOSER_VENDOR_DIR="$stage" "${composer_command[@]}" --working-dir="$symfony_root" install \
  --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts --no-plugins --no-progress \
  >"$composer_log" 2>&1; then
  printf 'S4_RUNTIME_PREPARE_DETAIL:composer-log-private\n' >&2
  fail "$(classify_composer_failure)"
fi

[[ -f "$stage/autoload.php" ]] || fail "staged-autoload-missing"
COMPOSER_VENDOR_DIR="$stage" "$php_bin" -r 'require $argv[1];' "$stage/autoload.php" >/dev/null 2>&1 \
  || fail "staged-autoload-invalid"

current_sha_after_prepare="$(git -C "$root" rev-parse HEAD 2>/dev/null || true)"
[[ "$current_sha_after_prepare" == "$expected_sha" ]] || fail "checkout-sha-changed-during-prepare"

if [[ -d "$symfony_root/vendor" ]]; then
  had_previous=true
  mv "$symfony_root/vendor" "$backup" || fail "vendor-backup-failed"
fi

promotion_started=true
if ! mv "$stage" "$symfony_root/vendor"; then
  fail "vendor-promotion-failed"
fi

if ! "$php_bin" -r 'require $argv[1];' "$symfony_root/vendor/autoload.php" >/dev/null 2>&1; then
  fail "promoted-autoload-invalid"
fi

prepared=true
rm -rf "$backup"
trap - EXIT HUP INT TERM
printf 'S4_RUNTIME_PREPARE_OK\n'
