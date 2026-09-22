#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$ROOT/scripts/symfony-disposable-restore-drill.sh"

assert_rejected() {
  local expected="$1"
  shift
  local out err
  out="$(mktemp)"
  err="$(mktemp)"
  if "$@" >"$out" 2>"$err"; then
    printf 'ERROR: restore drill safety contract unexpectedly succeeded\n' >&2
    rm -f "$out" "$err"
    exit 1
  fi
  [[ ! -s "$out" ]]
  grep -Fxq "ERROR: $expected" "$err"
  rm -f "$out" "$err"
}

assert_rejected   "restore drill requires explicit approval."   env -u GF_RESTORE_DRILL_APPROVED bash "$SCRIPT"

assert_rejected   "restore drill is test-only."   env GF_RESTORE_DRILL_APPROVED=1 APP_ENV=prod CI=true     DATABASE_URL='mysql://grindflow@127.0.0.1:3306/grindflow_symfony_ci'     bash "$SCRIPT"

assert_rejected   "restore drill is CI-only."   env GF_RESTORE_DRILL_APPROVED=1 APP_ENV=test CI=false     DATABASE_URL='mysql://grindflow@127.0.0.1:3306/grindflow_symfony_ci'     bash "$SCRIPT"

assert_rejected   "restore drill requires a loopback MariaDB host."   env GF_RESTORE_DRILL_APPROVED=1 APP_ENV=test CI=true     DATABASE_URL='mysql://grindflow@db.example.test:3306/grindflow_symfony_ci'     bash "$SCRIPT"

assert_rejected   "restore drill requires the disposable CI database."   env GF_RESTORE_DRILL_APPROVED=1 APP_ENV=test CI=true     DATABASE_URL='mysql://grindflow@127.0.0.1:3306/not_disposable'     bash "$SCRIPT"

printf 'GF-ARCH-002 disposable restore drill safety contract: OK\n'
