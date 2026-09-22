#!/usr/bin/env bash
set -euo pipefail
script_dir="$(cd "$(dirname "$0")" && pwd)"
workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

readonly NO_RETRY_MARKER='Production smoke attempt 2/'
readonly LOGIN_POST_PATTERN='^POST http://mock/login$'

# Guard absence assertions explicitly: negated grep is not a reliable errexit gate.
assert_absent_fixed() {
  if grep -Fq -- "$1" "$2"; then printf 'FAIL: unexpected secret or request.\n' >&2; exit 1; fi
}
assert_absent_regex() {
  if grep -Eq -- "$1" "$2"; then printf 'FAIL: unexpected diagnostic or request.\n' >&2; exit 1; fi
}

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
status=200; body=""; csv_headers=""; redirect=""
[[ -z "${MOCK_REQUEST_LOG:-}" ]] || printf '%s %s\n' "$method" "$url" >> "$MOCK_REQUEST_LOG"
case "$url" in
  http://mock/up) body="ok";;
  http://mock/login)
    if [[ "$method" == POST ]]; then
      status=302; redirect="/dashboard"
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_login ]]; then redirect="/login?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_external ]]; then redirect="https://external.invalid/dashboard?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_network ]]; then redirect="//external.invalid/dashboard?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_absolute_ok ]]; then redirect="http://mock/dashboard?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_absolute_scheme ]]; then redirect="https://mock/dashboard?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_absolute_port ]]; then redirect="http://mock:8080/dashboard?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_absolute_host ]]; then redirect="http://mock.invalid/dashboard?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_absolute_userinfo ]]; then redirect="http://user:private-query-do-not-print@mock/dashboard"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == post_303 ]]; then status=303; fi
       if [[ "${MOCK_AUTH_MODE:-ok}" =~ ^post_(301|307|308)$ ]]; then status="${BASH_REMATCH[1]}"; redirect="/login?private-query-do-not-print"; fi
      if [[ "${MOCK_AUTH_MODE:-ok}" =~ ^post_(401|403|419|422|429)$ ]]; then status="${BASH_REMATCH[1]}"; redirect=""; fi
    else
      body='<form><input name="_token" value="fake-csrf"></form>'
      if [[ "${MOCK_AUTH_MODE:-ok}" == login_csrf_rotates ]] && [[ -n "${MOCK_REQUEST_LOG:-}" ]] && [[ "$(grep -c '^GET http://mock/login$' "$MOCK_REQUEST_LOG")" -ge 2 ]]; then
        body='<form><input name="_token" value="never-print-csrf-rotated"></form>'
      fi
      if [[ "${MOCK_AUTH_MODE:-ok}" == login_body_secret || "${MOCK_AUTH_MODE:-ok}" == login_body_secret_invalid_id ]]; then status=500; body="never-print-body-secret"; fi
    fi;;
  http://mock/admin/diagnostics.json)
    body='{"entries":[{"timestamp":"never-print-diag-date","incident_id":"00000000-0000-4000-8000-000000000001","status":500,"request":{"method":"GET","path":"/private?never-print-diag-path"},"exception":"never-print-diag-exception","message":"never-print-diag-message","location":"never-print-diag-location","trace":[{"file":"never-print-diag-trace","line":9,"call":"never-print-diag-call"}]}]}';;
  http://mock/dashboard)
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_login ]]; then status=302; redirect="/login?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_external ]]; then status=302; redirect="https://external.invalid/login?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_network ]]; then status=302; redirect="//external.invalid/login?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_absolute_login ]]; then status=302; redirect="http://mock/login?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_absolute_external ]]; then status=302; redirect="http://mock.invalid/login?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_other ]]; then status=302; redirect="/organizations?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_secret ]]; then status=302; redirect="/l/private-query-do-not-print?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" == dashboard_303 ]]; then status=303; redirect="/login?private-query-do-not-print"; fi
     if [[ "${MOCK_AUTH_MODE:-ok}" =~ ^dashboard_(301|307|308)$ ]]; then status="${BASH_REMATCH[1]}"; redirect="/login?private-query-do-not-print"; fi
    if [[ "${MOCK_AUTH_MODE:-ok}" =~ ^dashboard_(401|403|419|422|429)$ ]]; then status="${BASH_REMATCH[1]}"; fi
    if [[ "${MOCK_VAULT_MODE:-ok}" == missing_link ]]; then body='<h1>Overview</h1>Tenant isolation active'; else body='<h1>Overview</h1>Tenant isolation active <a href="/organizations/example/vault">Vault</a>'; fi;;
  http://mock/admin/system)
    release="$(sed -nE "s/^[[:space:]]*'number'[[:space:]]*=>[[:space:]]*'([0-9]+\\.[0-9]+\\.[0-9]+)'.*/\\1/p" "$MOCK_REPOSITORY_ROOT/config/version.php")"
    [[ "${MOCK_RELEASE_MODE:-current}" == stale ]] && release=0.0.0
    release_markup="<div data-grindflow-release=\"$release\">GrindFlow v$release</div>"
    [[ "${MOCK_RELEASE_MODE:-current}" == missing ]] && release_markup=""
    body="<h2>Runtime configuration</h2>$release_markup<span data-pending-migrations=\"$MOCK_PENDING\">status</span><span data-media-storage-configured=\"0\">storage</span>"
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
if [[ "${MOCK_DIAGNOSTIC_MODE:-valid}" == invalid && "$url" == http://mock/admin/diagnostics.json ]]; then
  body="${body//00000000-0000-4000-8000-000000000001/never-print-diag-invalid-id}"
fi
if [[ "${MOCK_MODULE_MODE:-ok}" == failed && "$url" == http://mock/organizations/example/traffic ]]; then
  status=500; body="synthetic module failure"
fi
if [[ "${MOCK_CSV_MODE:-ok}" == failed && "$url" == http://mock/organizations/example/traffic/export ]]; then
  body="invalid report"
fi
[[ "$output" == /dev/null ]] || printf '%s' "$body" > "$output"
incident_header='00000000-0000-4000-8000-000000000002'
[[ "${MOCK_AUTH_MODE:-ok}" == login_body_secret_invalid_id ]] && incident_header='never-print-header-invalid-id'
[[ -z "$headers" ]] || printf 'HTTP/1.1 %s\r\n%sX-Request-Id: never-print-header-private\r\nX-Incident-ID: %s\r\n' "$status" "$csv_headers" "$incident_header" > "$headers"
[[ -z "$headers" || -z "$redirect" ]] || printf 'Location: %s\r\n' "$redirect" >> "$headers"
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
  local auth_mode="${9:-ok}"
  local diagnostic_mode="${10:-valid}"
  local log="$workdir/$label.log"
  local requests="$workdir/$label.requests"
  local result
  if MOCK_PENDING="$pending" MOCK_INVENTORY_MODE="$inventory_mode" MOCK_VAULT_MODE="$vault_mode" MOCK_MODULE_MODE="$module_mode" MOCK_CSV_MODE="$csv_mode" MOCK_RELEASE_MODE="$release_mode" MOCK_AUTH_MODE="$auth_mode" MOCK_DIAGNOSTIC_MODE="$diagnostic_mode" MOCK_REQUEST_LOG="$requests" MOCK_REPOSITORY_ROOT="$script_dir/.." BASE_URL=http://mock E2E_USER_PASSWORD=synthetic-only CURL_BIN="$workdir/mock-curl" ATTEMPTS=3 WAIT_SECONDS=0 bash "$script_dir/production-smoke.sh" > "$log" 2>&1; then result=0; else result=$?; fi
  if [[ "$result" -ne "$expected_status" ]]; then printf 'FAIL %s: exit=%s expected=%s\n' "$label" "$result" "$expected_status" >&2; cat "$log" >&2; exit 1; fi
  assert_absent_fixed 'never-print-header-private' "$log"
  [[ "$(grep -c '^GET http://mock/login$' "$requests")" -eq 2 ]] || { printf 'FAIL %s: expected exactly two read-only login GETs.\n' "$label" >&2; exit 1; }
  case "$label" in
    pending*)
      grep -Fxq 'MIGRATIONS_PENDING=3' "$log"
      if [[ "$vault_mode" == failed || "$vault_mode" == missing_link ]]; then grep -Fxq 'VAULT_READ_ONLY=failed' "$log"; grep -Fq 'ERROR: read-only Vault check failed while migrations remain pending' "$log"; else grep -Fxq 'VAULT_READ_ONLY=ok' "$log"; grep -Fxq 'MEDIA_STORAGE_READY=0' "$log"; fi
      if [[ "$inventory_mode" == valid ]]; then grep -Fxq 'MIGRATION_INVENTORY_STATUS=verified' "$log"; grep -Fxq 'MIGRATION_NAME=2026_09_19_000001_first' "$log"; grep -Fxq 'MIGRATION_NAME=2026_09_19_000002_second' "$log"; grep -Fxq 'MIGRATION_NAME=2026_09_19_000003_third' "$log"; grep -Eq '^MIGRATION_BATCH_SHA256=[a-f0-9]{64}$' "$log"; [[ "$(grep -c '^MIGRATION_NAME=' "$log")" -eq 3 ]]; else grep -Fxq 'MIGRATION_INVENTORY_STATUS=unavailable' "$log"; assert_absent_regex '^MIGRATION_(NAME|BATCH_SHA256)=' "$log"; fi
      assert_absent_fixed 'do-not-leak-csrf' "$log"; assert_absent_fixed 'never-print' "$log"; assert_absent_fixed "$NO_RETRY_MARKER" "$log"; assert_absent_regex '/(scheduler|distribution|traffic|finance)' "$requests";;
    auth_login_csrf_rotates)
      grep -Fxq 'LOGIN_SESSION_PREFLIGHT=inconsistent' "$log"
      grep -Fq 'anonymous session/CSRF changed across identical GET requests; no login POST was sent.' "$log"
      grep -Fq 'ERROR: authentication failure is deterministic; do not retry credentials.' "$log"
      assert_absent_fixed 'fake-csrf' "$log"
      assert_absent_fixed 'never-print-csrf-rotated' "$log"
      assert_absent_fixed "$NO_RETRY_MARKER" "$log"
      assert_absent_regex "$LOGIN_POST_PATTERN" "$requests"
      assert_absent_fixed 'GET http://mock/dashboard' "$requests"
      ;;
    current|auth_post_303|auth_post_absolute_ok)
      grep -Fxq 'VAULT_READ_ONLY=ok' "$log"
      grep -Eq '^RELEASE_UI_OBSERVED=v[0-9]+\.[0-9]+\.[0-9]+$' "$log"
      grep -Eq '^RELEASE_UI_EXPECTED=v[0-9]+[.][0-9]+[.][0-9]+$' "$log"
      for module in scheduler distribution traffic finance traffic-csv; do grep -Fxq "MODULE_READ_ONLY=$module:ok" "$log"; done
      [[ "$(grep -c '^MODULE_READ_ONLY=.*:ok$' "$log")" -eq 5 ]]
      grep -Fq 'PASS production smoke:' "$log"
      assert_absent_regex '^MIGRATIONS_PENDING=' "$log"
      [[ "$(grep -c "$LOGIN_POST_PATTERN" "$requests")" -eq 1 ]]
      [[ "$(grep -c '^GET http://mock/organizations/example/traffic/export$' "$requests")" -eq 1 ]]
      ;;
    current_module_failure|current_csv_failure|current_module_failure_invalid_id)
      grep -Fxq 'MODULE_READ_ONLY=failed' "$log"
      grep -Fq 'ERROR: read-only workspace module check failed; no repeated login requests.' "$log"
      grep -Fxq 'Recorded incidents (up to 5): 1' "$log"
      if [[ "$diagnostic_mode" == invalid ]]; then
        grep -Fxq 'Incident #1: HTTP=500 method=GET incident_id=unknown' "$log"
      else
        grep -Fxq 'Incident #1: HTTP=500 method=GET incident_id=00000000-0000-4000-8000-000000000001' "$log"
      fi
      assert_absent_fixed 'never-print-diag-' "$log"
      assert_absent_fixed "$NO_RETRY_MARKER" "$log"
      [[ "$(grep -c "$LOGIN_POST_PATTERN" "$requests")" -eq 1 ]]
      ;;
    current_stale_release|current_missing_release)
      grep -Eq '^RELEASE_UI_EXPECTED=v[0-9]+[.][0-9]+[.][0-9]+$' "$log"
      if [[ "$release_mode" == stale ]]; then
        grep -Fxq 'RELEASE_UI_OBSERVED=v0.0.0' "$log"
        grep -Fq 'ERROR: production release v0.0.0 differs from candidate v' "$log"
      else
        grep -Fxq 'RELEASE_UI_OBSERVED=unknown' "$log"
        grep -Fq 'ERROR: production release cannot be identified' "$log"
      fi
      assert_absent_regex '^MODULE_READ_ONLY=' "$log"
      assert_absent_fixed "$NO_RETRY_MARKER" "$log"
      [[ "$(grep -c "$LOGIN_POST_PATTERN" "$requests")" -eq 1 ]]
      ;;
    current_vault_failure|current_vault_link_missing)
      grep -Fxq 'VAULT_READ_ONLY=failed' "$log"; grep -Fq 'ERROR: read-only Vault check failed on the current schema; no repeated login requests.' "$log"; assert_absent_fixed "$NO_RETRY_MARKER" "$log";;
    auth_post_login|auth_post_external|auth_post_network|auth_post_absolute_scheme|auth_post_absolute_port|auth_post_absolute_host|auth_post_absolute_userinfo|auth_dashboard_login|auth_dashboard_external|auth_dashboard_network|auth_dashboard_absolute_login|auth_dashboard_absolute_external|auth_dashboard_other|auth_dashboard_secret|auth_dashboard_303|auth_post_301|auth_post_307|auth_post_308|auth_dashboard_301|auth_dashboard_307|auth_dashboard_308|auth_post_401|auth_post_403|auth_post_419|auth_post_422|auth_post_429|auth_dashboard_401|auth_dashboard_403|auth_dashboard_419|auth_dashboard_422|auth_dashboard_429)
      grep -Fq 'ERROR: authentication failure is deterministic; do not retry credentials.' "$log"
      assert_absent_fixed 'private-query-do-not-print' "$log"
      assert_absent_fixed 'external.invalid' "$log"
      assert_absent_fixed "$NO_RETRY_MARKER" "$log"
      [[ "$(grep -c "$LOGIN_POST_PATTERN" "$requests")" -eq 1 ]]
      if [[ "$auth_mode" =~ ^post_(301|307|308)$ ]]; then
        grep -Fxq 'LOGIN_REDIRECT_PATH=/login' "$log"
        grep -Fq "ERROR: login returned HTTP ${BASH_REMATCH[1]}; stop authentication retries on unexpected redirect." "$log"
        assert_absent_fixed 'GET http://mock/dashboard' "$requests"
      elif [[ "$auth_mode" =~ ^post_(401|403|419|422|429)$ ]]; then
        grep -Fq "ERROR: login returned HTTP ${BASH_REMATCH[1]}; stop authentication retries." "$log"
        assert_absent_fixed 'GET http://mock/dashboard' "$requests"
      elif [[ "$auth_mode" == post_login ]]; then
        grep -Fxq 'LOGIN_REDIRECT_PATH=/login' "$log"
        grep -Fq 'ERROR: login redirected back to /login;' "$log"
        assert_absent_fixed 'GET http://mock/dashboard' "$requests"
      elif [[ "$auth_mode" == post_external || "$auth_mode" == post_network || "$auth_mode" =~ ^post_absolute_(scheme|port|host|userinfo)$ ]]; then
        grep -Fxq 'LOGIN_REDIRECT_PATH=(redacted)' "$log"
        grep -Fq 'login redirect was not a recognized local dashboard path;' "$log"
        assert_absent_fixed 'GET http://mock/dashboard' "$requests"
      elif [[ "$auth_mode" == dashboard_external || "$auth_mode" == dashboard_network || "$auth_mode" == dashboard_absolute_external ]]; then
        grep -Fxq 'LOGIN_REDIRECT_PATH=/dashboard' "$log"
        grep -Fq 'redirect path (redacted)' "$log"
      elif [[ "$auth_mode" == dashboard_secret ]]; then
        grep -Fxq 'LOGIN_REDIRECT_PATH=/dashboard' "$log"
        grep -Fq 'redirect path (redacted)' "$log"
      elif [[ "$auth_mode" =~ ^dashboard_(401|403|419|422|429)$ ]]; then
        grep -Fq "ERROR: authenticated dashboard returned HTTP ${BASH_REMATCH[1]}; stop authentication retries." "$log"
      elif [[ "$auth_mode" == dashboard_absolute_login ]]; then
        grep -Fq "authenticated dashboard returned HTTP 302, redirect path /login" "$log"
      elif [[ "$auth_mode" =~ ^dashboard_(301|303|307|308)$ ]]; then
        grep -Fq "authenticated dashboard returned HTTP ${BASH_REMATCH[1]}, redirect path /login" "$log"
      else
        grep -Fxq 'LOGIN_REDIRECT_PATH=/dashboard' "$log"
        grep -Fq "redirect path /$( [[ "$auth_mode" == dashboard_login ]] && printf login || printf organizations)" "$log"
      fi;;
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
run_case current_module_failure_invalid_id 0 5 valid ok failed ok current ok invalid
run_case current_csv_failure 0 5 valid ok ok failed
run_case current_stale_release 0 6 valid ok ok ok stale
run_case current_missing_release 0 6 valid ok ok ok missing
run_case current_vault_failure 0 4 valid failed
run_case current_vault_link_missing 0 4 valid missing_link
run_case auth_login_csrf_rotates 0 7 valid ok ok ok current login_csrf_rotates
run_case auth_post_login 0 7 valid ok ok ok current post_login
run_case auth_post_external 0 7 valid ok ok ok current post_external
run_case auth_post_network 0 7 valid ok ok ok current post_network
run_case auth_post_absolute_ok 0 0 valid ok ok ok current post_absolute_ok
run_case auth_post_absolute_scheme 0 7 valid ok ok ok current post_absolute_scheme
run_case auth_post_absolute_port 0 7 valid ok ok ok current post_absolute_port
run_case auth_post_absolute_host 0 7 valid ok ok ok current post_absolute_host
run_case auth_post_absolute_userinfo 0 7 valid ok ok ok current post_absolute_userinfo
run_case auth_dashboard_external 0 7 valid ok ok ok current dashboard_external
run_case auth_dashboard_network 0 7 valid ok ok ok current dashboard_network
run_case auth_dashboard_absolute_login 0 7 valid ok ok ok current dashboard_absolute_login
run_case auth_dashboard_absolute_external 0 7 valid ok ok ok current dashboard_absolute_external
run_case auth_post_419 0 7 valid ok ok ok current post_419
run_case auth_dashboard_login 0 7 valid ok ok ok current dashboard_login
run_case auth_dashboard_other 0 7 valid ok ok ok current dashboard_other
run_case auth_dashboard_secret 0 7 valid ok ok ok current dashboard_secret
run_case auth_post_303 0 0 valid ok ok ok current post_303
run_case auth_post_301 0 7 valid ok ok ok current post_301
run_case auth_post_307 0 7 valid ok ok ok current post_307
run_case auth_post_308 0 7 valid ok ok ok current post_308
run_case auth_dashboard_301 0 7 valid ok ok ok current dashboard_301
run_case auth_dashboard_307 0 7 valid ok ok ok current dashboard_307
run_case auth_dashboard_308 0 7 valid ok ok ok current dashboard_308
run_case auth_dashboard_303 0 7 valid ok ok ok current dashboard_303
run_case auth_post_401 0 7 valid ok ok ok current post_401
run_case auth_post_403 0 7 valid ok ok ok current post_403
run_case auth_post_422 0 7 valid ok ok ok current post_422
run_case auth_post_429 0 7 valid ok ok ok current post_429
run_case auth_dashboard_401 0 7 valid ok ok ok current dashboard_401
run_case auth_dashboard_403 0 7 valid ok ok ok current dashboard_403
run_case auth_dashboard_419 0 7 valid ok ok ok current dashboard_419
run_case auth_dashboard_422 0 7 valid ok ok ok current dashboard_422
run_case auth_dashboard_429 0 7 valid ok ok ok current dashboard_429

if MOCK_PENDING=unknown MOCK_REPOSITORY_ROOT="$script_dir/.." BASE_URL=http://mock E2E_USER_PASSWORD=synthetic-only CURL_BIN="$workdir/mock-curl" ATTEMPTS=1 WAIT_SECONDS=0 bash "$script_dir/production-smoke.sh" > "$workdir/unknown.log" 2>&1; then printf 'FAIL: unknown schema passed smoke.\n' >&2; exit 1; else result=$?; fi
[[ "$result" -eq 1 ]]
grep -Fq 'ERROR: production migration inventory is unavailable.' "$workdir/unknown.log"
assert_absent_regex '^MIGRATIONS_PENDING=' "$workdir/unknown.log"
for mode in login_body_secret login_body_secret_invalid_id; do
  if MOCK_PENDING=0 MOCK_AUTH_MODE="$mode" MOCK_REPOSITORY_ROOT="$script_dir/.." BASE_URL=http://mock E2E_USER_PASSWORD=synthetic-only CURL_BIN="$workdir/mock-curl" ATTEMPTS=1 WAIT_SECONDS=0 bash "$script_dir/production-smoke.sh" > "$workdir/$mode.log" 2>&1; then
    printf 'FAIL: synthetic login error unexpectedly passed.\n' >&2; exit 1
  else
    result=$?
  fi
  [[ "$result" -eq 1 ]]
  if [[ "$mode" == login_body_secret ]]; then
    grep -Fxq 'ERROR: login page GET /login returned HTTP 500 incident_id=00000000-0000-4000-8000-000000000002' "$workdir/$mode.log"
  else
    grep -Fxq 'ERROR: login page GET /login returned HTTP 500 incident_id=unknown' "$workdir/$mode.log"
  fi
  assert_absent_fixed 'never-print-body-secret' "$workdir/$mode.log"
  assert_absent_fixed 'never-print-header-invalid-id' "$workdir/$mode.log"
  printf 'PASS production smoke contract: %s redaction\n' "$mode"
done
# Test the pure redirect classifier without invoking a real HTTP request or credentials.
sed -n '/^safe_redirect_path() {/,/^}/p' "$script_dir/production-smoke.sh" > "$workdir/redirect-classifier.sh"
# shellcheck disable=SC1090
source "$workdir/redirect-classifier.sh"
# Build synthetic HTTP addresses from parts: these values are NEVER sent on a network.
mock_http_scheme=http
mock_http_origin="$(printf '%s://%s' "$mock_http_scheme" mock)"
printf 'HTTP/1.1 302 Found\r\nLocation: %s:80/dashboard?private-query-do-not-print\r\n' "$mock_http_origin" > "$workdir/redirect-origin.headers"
for origin in "$mock_http_origin" "$mock_http_origin:80"; do
  observed="$(BASE_URL="$origin" safe_redirect_path "$workdir/redirect-origin.headers")"
  if [[ "$observed" != /dashboard ]]; then
    printf 'FAIL production smoke contract: equivalent default HTTP origin\n' >&2
    exit 1
  fi
done
for authority in '@mock' ':@mock'; do
  observed="$(BASE_URL="${mock_http_scheme}://$authority" safe_redirect_path "$workdir/redirect-origin.headers")"
  if [[ "$observed" != '(redacted)' ]]; then
    printf 'FAIL production smoke contract: empty syntactic userinfo accepted\n' >&2
    exit 1
  fi
done
printf 'HTTP/1.1 302 Found\r\nLocation: https://mock:443/dashboard?private-query-do-not-print\r\n' > "$workdir/redirect-origin.headers"
observed="$(BASE_URL=https://mock safe_redirect_path "$workdir/redirect-origin.headers")"
if [[ "$observed" != /dashboard ]]; then
  printf 'FAIL production smoke contract: equivalent default HTTPS origin\n' >&2
  exit 1
fi
printf 'PASS production smoke contract: default ports and empty-userinfo rejection\n'
printf 'PASS production smoke contract: unknown\n'