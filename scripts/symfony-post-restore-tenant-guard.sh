#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SYMFONY_DIR="$ROOT/symfony"

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 2
}

[[ "${APP_ENV:-}" == "test" ]] || fail "post-restore tenant guard is test-only."
[[ "${CI:-}" == "true" ]] || fail "post-restore tenant guard is CI-only."
[[ -x "$SYMFONY_DIR/vendor/bin/simple-phpunit" ]] || fail "Symfony test dependencies are required."

cd "$SYMFONY_DIR"

php vendor/bin/simple-phpunit -c phpunit.xml.dist \
  tests/php/VaultTest.php \
  tests/php/VaultBulkUsageTest.php \
  tests/php/VaultTrashTest.php \
  tests/php/OrganizationSettingsTest.php \
  tests/php/DistributionAuthorizationTest.php

printf 'GF-ARCH-002 post-restore tenant/IDOR guard: OK\n'
