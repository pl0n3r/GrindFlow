#!/usr/bin/env bash
set -euo pipefail

script="scripts/mariadb-structure-snapshot.php"

php -l "$script" >/dev/null

stdout_file="$(mktemp)"
stderr_file="$(mktemp)"
trap 'rm -f "$stdout_file" "$stderr_file"' EXIT

if env -u GF_METADATA_SNAPSHOT_APPROVED -u DATABASE_URL php "$script" >"$stdout_file" 2>"$stderr_file"; then
  echo "ERROR: snapshot script must refuse missing approval" >&2
  exit 1
fi
test ! -s "$stdout_file"
grep -Fxq "ERROR: metadata snapshot requires explicit approval." "$stderr_file"

: >"$stdout_file"
: >"$stderr_file"
if env -u DATABASE_URL GF_METADATA_SNAPSHOT_APPROVED=1 php "$script" >"$stdout_file" 2>"$stderr_file"; then
  echo "ERROR: snapshot script must refuse missing DATABASE_URL" >&2
  exit 1
fi
test ! -s "$stdout_file"
grep -Fxq "ERROR: DATABASE_URL is required." "$stderr_file"

echo "GF-ARCH-002 MariaDB metadata snapshot contract: OK"
