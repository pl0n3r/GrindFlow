#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$ROOT/scripts/symfony-post-restore-tenant-guard.sh"

assert_rejected() {
  local expected="$1"
  shift
  local out err
  out="$(mktemp)"
  err="$(mktemp)"
  if "$@" >"$out" 2>"$err"; then
    printf 'ERROR: post-restore tenant guard safety contract unexpectedly succeeded\n' >&2
    rm -f "$out" "$err"
    exit 1
  fi
  [[ ! -s "$out" ]]
  grep -Fxq "ERROR: $expected" "$err"
  rm -f "$out" "$err"
}

assert_rejected   "post-restore tenant guard is test-only."   env APP_ENV=prod CI=true bash "$SCRIPT"

assert_rejected   "post-restore tenant guard is CI-only."   env APP_ENV=test CI=false bash "$SCRIPT"

printf 'GF-ARCH-002 post-restore tenant guard safety contract: OK\n'
