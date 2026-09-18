#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCOPE="$ROOT/scripts/ci-scope.sh"

run_scope() {
  local event="$1"
  shift
  printf '%s\n' "$@" | bash "$SCOPE" "$event"
}

expect_flag() {
  local output="$1"
  local expected="$2"
  local label="$3"

  if ! grep -Fxq "$expected" <<<"$output"; then
    printf 'CI scope contract failed: %s\nExpected: %s\nActual:\n%s\n' \
      "$label" "$expected" "$output" >&2
    exit 1
  fi
}

docs="$(run_scope pull_request README.md AGENTS.md)"
expect_flag "$docs" "run_php_quality=false" "docs skip php-quality"
expect_flag "$docs" "run_tests=false" "docs skip tests"
expect_flag "$docs" "run_database=false" "docs skip database"
expect_flag "$docs" "run_browser=false" "docs skip browser"
expect_flag "$docs" "run_legacy=false" "docs skip legacy"

service="$(run_scope pull_request app/Services/Media/MediaAssetProcessor.php)"
expect_flag "$service" "run_php_quality=true" "Laravel service selects php-quality"
expect_flag "$service" "run_tests=true" "Laravel service selects tests"
expect_flag "$service" "run_database=false" "Laravel service does not force database"
expect_flag "$service" "run_browser=false" "Laravel service does not force browser"

model="$(run_scope pull_request app/Models/MediaAsset.php)"
expect_flag "$model" "run_database=true" "model selects database"

view="$(run_scope pull_request resources/views/vault/index.blade.php)"
expect_flag "$view" "run_browser=true" "view selects browser"

legacy="$(run_scope pull_request src/lib/example.ts)"
expect_flag "$legacy" "run_legacy=true" "legacy source selects legacy gate"

ci_core="$(run_scope pull_request .github/workflows/grindflow-ci.yml)"
for flag in run_php_quality run_tests run_database run_browser run_legacy; do
  expect_flag "$ci_core" "$flag=true" "CI core forces $flag"
done
expect_flag "$ci_core" "full=true" "CI core marks full validation"

manual="$(run_scope workflow_dispatch)"
for flag in run_php_quality run_tests run_database run_browser run_legacy; do
  expect_flag "$manual" "$flag=true" "manual dispatch forces $flag"
done
expect_flag "$manual" "full=true" "manual dispatch marks full validation"

mixed="$(run_scope pull_request app/Services/Media/MediaAssetProcessor.php resources/views/vault/index.blade.php src/lib/example.ts)"
expect_flag "$mixed" "run_php_quality=true" "mixed keeps php-quality"
expect_flag "$mixed" "run_tests=true" "mixed keeps tests"
expect_flag "$mixed" "run_browser=true" "mixed unions browser"
expect_flag "$mixed" "run_legacy=true" "mixed unions legacy"

printf 'GrindFlow CI scope contract passed.\n'
