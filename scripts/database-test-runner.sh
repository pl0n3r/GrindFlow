#!/usr/bin/env bash
# Select the database group when it exists; never rerun an actual test failure
# as a full-suite fallback (that doubles cost and hides the first cause).
set -euo pipefail

if [[ -v GF_DATABASE_TEST_RUNNER && -n "$GF_DATABASE_TEST_RUNNER" ]]; then
  runner="$GF_DATABASE_TEST_RUNNER"
elif [[ -x vendor/bin/pest ]]; then
  runner=vendor/bin/pest
else
  runner=vendor/bin/phpunit
fi

if groups="$("$runner" --list-groups 2>&1)"; then
  if grep -Eq '^[[:space:]]*[-*]?[[:space:]]*database[[:space:]]*$' <<< "$groups"; then
    echo "Database CI: running explicitly tagged database tests."
    "$runner" --group=database
  else
    echo "Database CI: no database group; running the full suite exactly once."
    "$runner"
  fi
else
  echo "::warning::Cannot list database test groups; running the full suite exactly once."
  "$runner"
fi
