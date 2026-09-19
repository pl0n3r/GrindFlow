#!/usr/bin/env bash
set -euo pipefail
script_dir="$(cd "$(dirname "$0")" && pwd)"
workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

cat > "$workdir/mock-curl" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
method=GET; output=/dev/null; headers=""; write_out=""; url=""
while (( $# > 0 )); do
  case "$1" in
    --output|--dump-header|--write-out|--request)
      case "$1" in --output) output="$2";; --dump-header) headers="$2";; --write-out) write_out="$2";; --request) method="$2";; esac; shift 2;;
    --cookie|--cookie-jar|--max-time|--user-agent|--header|--data-urlencode) shift 2;;
    --silent|--show-error) shift;;
    http://mock/*) url="$1"; shift;;
    *) printf 'unexpected fake-curl argument: %s\n' "$1" >&2; exit 2;;
  esac
done
status=200; body=""; csv_headers=""
[[ -z "${MOCK_REQUEST_LOG:-}" ]] || printf '%s %s\n' "$method" "$url" >> "$MOCK_REQUEST_LOG"
case "$url" in
  http://mock/up) body="ok";;
  http://mock/login) if [[ "$method" == POST ]]; then status=302; else body='<form><input name="_token" value="fake-csrf"></form>'; fi;;
  http://mock/dashboard)
    if [[ "${MOCK_VAULT_MODE:-ok}" == missing_link ]]; then body='<h1>Overview</h1>Tenant isolation active'; else body='<h1>Overview</h1>Tenant isolation active <a href="/organizations/example/vault">Vault</a>'; fi;;
  http://mock/admin/system)
    release="$(sed -nE "s/^[[:space:]]*'number'[[:space:]]*=>[[:space:]]*'([0-9]+\\.[0-9]+\\.[0-9]+)'.*/\\1/p" "$MOCK_REPOSITORY_ROOT/config/version.php")"
    [[ "${MOCK_RELEASE_MODE:-current}" == stale ]] && release=0.0.0
    body="<h2>Runtime configuration</h2>GrindFlow v$release<span data-pending-migrations=\"$MOCK_PENDING\">status</span><span data-media-storage-configured=\"0\">storage</span>"
    if [[ "$MOCK_PENDING" == 3 ]]; then
      fingerprint="$(printf 'a%.0s' {1..64})"
      inventory='<ol data-pending-migration-inventory><li><code>2026_09_19_000001_first</code></li><li><code>2026_09_19_000002_second</code></li><li><code>2026_09_19_000003_third</code></li></ol>'
      case "${MOCK_INVENTORY_MODE:-valid}" in missing) inventory="";; mismatch) inventory='<ol data-pending-migration-inventory><li><code>2026_09_19_000001_first</code></li></ol>';; unsafe) inventory='<ol data-pending-migration-inventory><li><code>2026_09_19_bad-secret=never-print</code></li><li><code>2026_09_19_000002_second</code></li><li><code>2026_09_19_000003_third</code></li></ol>';; no_fingerprint) fingerprint=invalid;; esac
      body+="<input name=\"migration_batch\" value=\"$fingerprint\"><input name=\"_token\" value=\"do-not-leak-csrf\">$inventory"
    fi;;
  http://mock/organizations/example/vault) if [[ "${MOCK_VAULT_MODE:-ok}" == failed ]]; then status=500; body="synthetic vault failure"; else body="Organization scoped Direct upload"; fi;;
  http://mock/organizations/example/scheduler) body="Scheduling schema ready";;
  http://mock/organizations/example/distribution) body="Distribution ready";;
  http://mock/organizations/example/traffic) body="Traffic schema ready";;
  http://mock/organizations/example/finance) body="Finance schema ready";;
  http://mock/organizations/example/traffic/export)
    body=$'\xEF\xBB\xBFdate_utc,label,short_link,channel,campaign,status,clicks\n'
    csv_headers=$'Content-Type: text/csv; charset=UTF-8\r\nContent-Disposition: attachment; filename=grindflow-traffic.csv\r\n'
    ;;
  *) status=404;;
esac
if [[ "${MOCK_MODULE_MODE:-ok}" == failed && "$url" == http://mock/organizations/example/traffic ]]; then
  status=500; body="synthetic module failure"
fi
if [[ "${MOCK_CSV_MODE:-ok}" == failed && "$url" == http://mock/organizations/example/traffic/export ]]; then
  body="invalid report"
fi
[[ "$output" == /dev/null ]] || printf '%s' "$body" > "$output"
[[ -z "$headers" ]] || printf 'HTTP/1.1 %s\r\n%s' "$status" "$csv_headers" > "$headers"
[[ -z "$write_out" ]] || printf '%s' "$status"
MOCK
chmod +x "$workdir/mock-curl"

run_case() {
  local label="$1"
  local pending="$2"
  local expected_status="$3"
  local inventory_mode="${4:-valid}"
  local vault_mode="${5:-ok}"
  local module_mode="${6:-ok}"
  local csv_mode="${7:-ok}"
  local release_mode="${8:-current}"
  local log="$workdir/$label.log"
  local requests="$workdir/$label.requests"
  local result
  if MOCK_PENDING="$pending" MOCK_INVENTORY_MODE="$inventory_mode" MOCK_VAULT_MODE="$vault_mode" MOCK_MODULE_MODE="$module_mode" MOCK_CSV_MODE="$csv_mode" MOCK_RELEASE_MODE="$release_mode" MOCK_REQUEST_LOG="$requests" MOCK_REPOSITORY_ROOT="$script_dir/.." BASE_URL=http://mock E2E_USER_PASSWORD=synthetic-only CURL_BIN="$workdir/mock-curl" ATTEMPTS=1 WAIT_SECONDS=0 bash "$script_dir/production-smoke.sh" > "$log" 2>&1; then result=0; else result=$?; fi
  if [[ "$result" -ne "$expected_status" ]]; then printf 'FAIL %s: exit=%s expected=%s\n' "$label" "$result" "$expected_status" >&2; cat "$log" >&2; exit 1; fi
  case "$label" in
    pending*)
      grep -Fxq 'MIGRATIONS_PENDING=3' "$log"
      if [[ "$vault_mode" == failed || "$vault_mode" == missing_link ]]; then grep -Fxq 'VAULT_READ_ONLY=failed' "$log"; grep -Fq 'ERROR: read-only Vault check failed while migrations remain pending' "$log"; else grep -Fxq 'VAULT_READ_ONLY=ok' "$log"; grep -Fxq 'MEDIA_STORAGE_READY=0' "$log"; fi
      if [[ "$inventory_mode" == valid ]]; then grep -Fxq 'MIGRATION_INVENTORY_STATUS=verified' "$log"; grep -Fxq 'MIGRATION_NAME=2026_09_19_000001_first' "$log"; grep -Fxq 'MIGRATION_NAME=2026_09_19_000002_second' "$log"; grep -Fxq 'MIGRATION_NAME=2026_09_19_000003_third' "$log"; grep -Eq '^MIGRATION_BATCH_SHA256=[a-f0-9]{64}$' "$log"; [[ "$(grep -c '^MIGRATION_NAME=' "$log")" -eq 3 ]]; else grep -Fxq 'MIGRATION_INVENTORY_STATUS=unavailable' "$log"; ! grep -Eq '^MIGRATION_(NAME|BATCH_SHA256)=' "$log"; fi
      ! grep -Fq 'do-not-leak-csrf' "$log"; ! grep -Fq 'never-print' "$log"; ! grep -Fq 'Production smoke attempt 2/' "$log"; ! grep -Eq '/(scheduler|distribution|traffic|finance)' "$requests";;
    current)
      grep -Fxq 'VAULT_READ_ONLY=ok' "$log"
      grep -Eq '^RELEASE_UI_OBSERVED=v[0-9]+\.[0-9]+\.[0-9]+$' "$log"
      for module in scheduler distribution traffic finance traffic-csv; do grep -Fxq "MODULE_READ_ONLY=$module:ok" "$log"; done
      [[ "$(grep -c '^MODULE_READ_ONLY=.*:ok$' "$log")" -eq 5 ]]
      grep -Fq 'PASS production smoke:' "$log"
      ! grep -q '^MIGRATIONS_PENDING=' "$log"
      [[ "$(grep -c '^POST http://mock/login$' "$requests")" -eq 1 ]]
      [[ "$(grep -c '^GET http://mock/organizations/example/traffic/export$' "$requests")" -eq 1 ]]
      ;;
    current_module_failure|current_csv_failure)
      grep -Fxq 'MODULE_READ_ONLY=failed' "$log"
      grep -Fq 'ERROR: read-only workspace module check failed; no repeated login requests.' "$log"
      ! grep -Fq 'Production smoke attempt 2/' "$log"
      [[ "$(grep -c '^POST http://mock/login$' "$requests")" -eq 1 ]]
      ;;
    current_stale_release)
      grep -Fq 'ERROR: production release does not match candidate v' "$log"
      ! grep -q '^MODULE_READ_ONLY=' "$log"
      ;;
    current_vault_failure|current_vault_link_missing)
      grep -Fxq 'VAULT_READ_ONLY=failed' "$log"; grep -Fq 'ERROR: read-only Vault check failed on the current schema; no repeated login requests.' "$log"; ! grep -Fq 'Production smoke attempt 2/' "$log";;
  esac
  printf 'PASS production smoke contract: %s\n' "$label"
}

run_case pending 3 2
run_case pending_missing 3 2 missing
run_case pending_mismatch 3 2 mismatch
run_case pending_unsafe 3 2 unsafe
run_case pending_no_fingerprint 3 2 no_fingerprint
run_case pending_vault_failure 3 3 valid failed
run_case pending_vault_link_missing 3 3 valid missing_link
run_case current 0 0
run_case current_module_failure 0 5 valid ok failed
run_case current_csv_failure 0 5 valid ok ok failed
run_case current_stale_release 0 1 valid ok ok ok stale
run_case current_vault_failure 0 4 valid failed
run_case current_vault_link_missing 0 4 valid missing_link

if MOCK_PENDING=unknown MOCK_REPOSITORY_ROOT="$script_dir/.." BASE_URL=http://mock E2E_USER_PASSWORD=synthetic-only CURL_BIN="$workdir/mock-curl" ATTEMPTS=1 WAIT_SECONDS=0 bash "$script_dir/production-smoke.sh" > "$workdir/unknown.log" 2>&1; then printf 'FAIL: unknown schema passed smoke.\n' >&2; exit 1; else result=$?; fi
[[ "$result" -eq 1 ]]
grep -Fq 'ERROR: production migration inventory is unavailable.' "$workdir/unknown.log"
! grep -q '^MIGRATIONS_PENDING=' "$workdir/unknown.log"
printf 'PASS production smoke contract: unknown\n'