#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

cat > "$tmp/runner" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$GF_TEST_CALLS"
if [[ "$1" == "--list-groups" ]]; then
  case "$GF_TEST_MODE" in
    database) printf 'Available test group(s):\n - default\n - database\n'; exit 0 ;;
    absent) printf 'Available test group(s):\n - default\n'; exit 0 ;;
    unlisted) exit 2 ;;
  esac
fi
if [[ "$GF_TEST_MODE" == "database" && "$1" == "--group=database" ]]; then
  exit 7
fi
SH
chmod +x "$tmp/runner"
export GF_DATABASE_TEST_RUNNER="$tmp/runner"
export GF_TEST_CALLS="$tmp/calls"

export GF_TEST_MODE=database
: > "$GF_TEST_CALLS"
status=0
bash "$root/scripts/database-test-runner.sh" > "$tmp/output" || status=$?
[[ "$status" == 7 ]] || { echo 'Database test failure must be preserved' >&2; exit 1; }
[[ "$(wc -l < "$GF_TEST_CALLS")" -eq 2 ]]
grep -Fxq -- '--group=database' "$GF_TEST_CALLS"

export GF_TEST_MODE=absent
: > "$GF_TEST_CALLS"
bash "$root/scripts/database-test-runner.sh" > "$tmp/output"
[[ "$(wc -l < "$GF_TEST_CALLS")" -eq 2 ]]
[[ "$(tail -1 "$GF_TEST_CALLS")" == "" ]]

export GF_TEST_MODE=unlisted
: > "$GF_TEST_CALLS"
bash "$root/scripts/database-test-runner.sh" > "$tmp/output"
[[ "$(wc -l < "$GF_TEST_CALLS")" -eq 2 ]]
grep -q 'Cannot list database test groups' "$tmp/output"

echo 'Database group selection and no-retry contract passed.'
