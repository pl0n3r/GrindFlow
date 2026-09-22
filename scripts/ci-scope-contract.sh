#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCOPE="$ROOT/scripts/ci-scope.sh"
RUN_REALSTACK_ENABLED="run_realstack=true"
RUN_SYMFONY_ENABLED=$RUN_SYMFONY_ENABLED

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
expect_flag "$docs" "run_realstack=false" "docs skip real-stack"
expect_flag "$docs" "run_legacy=false" "docs skip legacy"
expect_flag "$docs" "run_symfony=false" "docs skip Symfony"

release="$(run_scope pull_request README.md AGENTS.md config/version.php)"
expect_flag "$release" "run_php_quality=false" "human release metadata stays fast-only"
expect_flag "$release" "run_tests=false" "human release metadata skips tests"
expect_flag "$release" "run_database=false" "human release metadata skips MariaDB"
expect_flag "$release" "run_browser=false" "human release metadata skips browser"
expect_flag "$release" "run_realstack=false" "human release metadata skips real-stack"
expect_flag "$release" "run_legacy=false" "human release metadata skips legacy"
expect_flag "$release" "run_symfony=false" "human release metadata skips Symfony"

service="$(run_scope pull_request app/Services/Media/MediaAssetProcessor.php)"
expect_flag "$service" "run_php_quality=true" "Laravel service selects php-quality"
expect_flag "$service" "run_tests=true" "Laravel service selects tests"
expect_flag "$service" "run_database=false" "Laravel service does not force database"
expect_flag "$service" "run_browser=false" "Laravel service does not force browser"
expect_flag "$service" $RUN_REALSTACK_ENABLED "Laravel service selects real-stack"

provider="$(run_scope pull_request app/Support/Operations/ReleaseCacheGuard.php)"
expect_flag "$provider" $RUN_REALSTACK_ENABLED "Laravel support service selects real-stack"

model="$(run_scope pull_request app/Models/MediaAsset.php)"
expect_flag "$model" "run_database=true" "model selects database"
expect_flag "$model" $RUN_REALSTACK_ENABLED "model selects MariaDB real-stack"

view="$(run_scope pull_request resources/views/vault/index.blade.php)"
expect_flag "$view" "run_browser=true" "view selects browser"
expect_flag "$view" $RUN_REALSTACK_ENABLED "view selects real-stack"

symfony="$(run_scope pull_request symfony/src/Kernel.php)"
expect_flag "$symfony" $RUN_SYMFONY_ENABLED "Symfony source selects its gate"
expect_flag "$symfony" "run_legacy=false" "Symfony source does not select legacy Node"
expect_flag "$symfony" "run_php_quality=false" "Symfony source does not select Laravel quality"

symfony_docs="$(run_scope pull_request symfony/README.md symfony/docs/operations.md)"
expect_flag "$symfony_docs" "run_symfony=false" "Symfony documentation skips heavy Symfony gate"

symfony_mixed="$(run_scope pull_request symfony/README.md symfony/src/Kernel.php)"
expect_flag "$symfony_mixed" $RUN_SYMFONY_ENABLED "Symfony source cannot be masked by docs"

schema_tooling="$(run_scope pull_request scripts/mariadb-structure-snapshot.php)"
expect_flag "$schema_tooling" $RUN_SYMFONY_ENABLED "schema snapshot tooling selects Symfony parity gate"

schema_contract="$(run_scope pull_request scripts/data-schema-structure-parity.py)"
expect_flag "$schema_contract" $RUN_SYMFONY_ENABLED "schema parity tooling selects Symfony parity gate"

legacy="$(run_scope pull_request src/lib/example.ts)"
expect_flag "$legacy" "run_legacy=true" "legacy source selects legacy gate"

ci_core="$(run_scope pull_request .github/workflows/grindflow-ci.yml)"
for flag in run_php_quality run_tests run_database run_browser run_realstack run_legacy run_symfony; do
  expect_flag "$ci_core" "$flag=true" "CI core forces $flag"
done
expect_flag "$ci_core" "full=true" "CI core marks full validation"

database_runner="$(run_scope pull_request scripts/database-test-runner.sh)"
for flag in run_php_quality run_tests run_database run_browser run_realstack run_legacy run_symfony; do
  expect_flag "$database_runner" "$flag=true" "database runner changes force $flag"
done

manual="$(run_scope workflow_dispatch)"
for flag in run_php_quality run_tests run_database run_browser run_realstack run_legacy run_symfony; do
  expect_flag "$manual" "$flag=true" "manual dispatch forces $flag"
done
expect_flag "$manual" "full=true" "manual dispatch marks full validation"

mixed="$(run_scope pull_request app/Services/Media/MediaAssetProcessor.php resources/views/vault/index.blade.php src/lib/example.ts)"
expect_flag "$mixed" "run_php_quality=true" "mixed keeps php-quality"
expect_flag "$mixed" "run_tests=true" "mixed keeps tests"
expect_flag "$mixed" "run_browser=true" "mixed unions browser"
expect_flag "$mixed" $RUN_REALSTACK_ENABLED "mixed unions real-stack"
expect_flag "$mixed" "run_legacy=true" "mixed unions legacy"

new_mixed="$(run_scope pull_request symfony/src/Kernel.php src/lib/example.ts)"
expect_flag "$new_mixed" $RUN_SYMFONY_ENABLED "new stack selected alongside legacy"
expect_flag "$new_mixed" "run_legacy=true" "legacy remains selected alongside Symfony"

printf 'GrindFlow CI scope contract passed.\n'
