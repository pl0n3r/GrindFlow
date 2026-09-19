#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://www.grindflow.com.co}"
E2E_USER_EMAIL="${E2E_USER_EMAIL:-e2e-admin@grindflow.test}"
: "${E2E_USER_PASSWORD:?E2E_USER_PASSWORD is required}"

ATTEMPTS="${ATTEMPTS:-12}"
WAIT_SECONDS="${WAIT_SECONDS:-20}"
CURL_BIN="${CURL_BIN:-curl}"
SMOKE_USER_AGENT="${SMOKE_USER_AGENT:-Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0 Safari/537.36 GrindFlowProductionSmoke/1.0}"
SMOKE_ACCEPT="${SMOKE_ACCEPT:-text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXPECTED_RELEASE="$(sed -nE "s/^[[:space:]]*'number'[[:space:]]*=>[[:space:]]*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/p" "$script_dir/../config/version.php")"
[[ "$EXPECTED_RELEASE" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { printf 'ERROR: expected release version is unavailable.\n' >&2; exit 1; }

workdir="$(mktemp -d)"
cookie_jar="$workdir/cookies.txt"
login_html="$workdir/login.html"
dashboard_html="$workdir/dashboard.html"
system_html="$workdir/system.html"
vault_html="$workdir/vault.html"
diagnostics_json="$workdir/diagnostics.json"
up_body="$workdir/up.body"
up_headers="$workdir/up.headers"
login_headers="$workdir/login.headers"
module_html="$workdir/module.html"
csv_body="$workdir/traffic.csv"
csv_headers="$workdir/traffic.headers"

cleanup() { rm -rf "$workdir"; }
trap cleanup EXIT

curl_common() {
  "$CURL_BIN" --silent --show-error --max-time 20 --user-agent "$SMOKE_USER_AGENT" --header "Accept: $SMOKE_ACCEPT" --header "Accept-Language: en-US,en;q=0.8" "$@"
}

print_http_failure() {
  local label="$1" status="$2" headers_file="$3" body_file="$4"
  printf 'ERROR: %s returned HTTP %s\n' "$label" "$status" >&2
  if [[ -s "$headers_file" ]]; then
    printf '%s\n' '---- safe response headers ----' >&2
    grep -iE '^(server|content-type|content-length|location|retry-after|via|x-cache|x-request-id|x-correlation-id|x-hostinger|cf-ray):' "$headers_file" >&2 || true
  fi
  if [[ -s "$body_file" ]]; then
    python3 - "$body_file" >&2 <<'PY'
import sys
with open(sys.argv[1], encoding="utf-8", errors="replace") as handle:
    snippet = " ".join(handle.read(4096).split())[:500]
if snippet:
    print(f"Body snippet: {snippet}")
PY
  fi
  printf '%s\n' '-------------------------------' >&2
}

extract_csrf() {
  python3 - "$login_html" <<'PY'
from html.parser import HTMLParser
import sys
class TokenParser(HTMLParser):
    def __init__(self):
        super().__init__(); self.token = None
    def handle_starttag(self, tag, attrs):
        if tag != "input": return
        attrs = dict(attrs)
        if attrs.get("name") == "_token" and attrs.get("value"): self.token = attrs["value"]
parser = TokenParser()
with open(sys.argv[1], encoding="utf-8") as handle: parser.feed(handle.read())
if not parser.token: raise SystemExit(2)
print(parser.token)
PY
}

extract_vault_path() {
  python3 - "$dashboard_html" <<'PY'
from html.parser import HTMLParser
from urllib.parse import urlparse
import re, sys
pattern = re.compile(r"^/organizations/[^/]+/vault$")
class VaultParser(HTMLParser):
    def __init__(self): super().__init__(); self.path = None
    def handle_starttag(self, tag, attrs):
        if self.path is not None or tag != "a": return
        href = dict(attrs).get("href")
        if not href: return
        path = urlparse(href).path
        if pattern.match(path): self.path = path
parser = VaultParser()
with open(sys.argv[1], encoding="utf-8") as handle: parser.feed(handle.read())
if not parser.path: raise SystemExit(2)
print(parser.path)
PY
}

extract_pending_migrations() {
  python3 - "$system_html" <<'PY'
from html.parser import HTMLParser
import sys
class PendingParser(HTMLParser):
    def __init__(self): super().__init__(); self.value = None
    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if "data-pending-migrations" in values: self.value = values["data-pending-migrations"]
parser = PendingParser()
with open(sys.argv[1], encoding="utf-8") as handle: parser.feed(handle.read())
if parser.value is None or not parser.value.isascii() or not parser.value.isdecimal(): raise SystemExit(2)
print(parser.value)
PY
}

assert_contains() {
  local file="$1" expected="$2"
  if ! grep -Fq "$expected" "$file"; then
    printf 'ERROR: expected text not found: %s\n' "$expected" >&2
    return 1
  fi
}

print_diagnostics() {
  local diagnostic_status
  diagnostic_status="$(curl_common --cookie "$cookie_jar" --output "$diagnostics_json" --write-out '%{http_code}' "$BASE_URL/admin/diagnostics.json" || true)"
  printf '\n---- GrindFlow application diagnostics ----\n' >&2
  if [[ "$diagnostic_status" != "200" ]]; then
    printf 'Diagnostics endpoint unavailable: HTTP %s\n' "$diagnostic_status" >&2
    printf '%s\n' '-------------------------------------------' >&2
    return 0
  fi
  python3 - "$diagnostics_json" >&2 <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle: payload = json.load(handle)
entries = payload.get("entries", [])[:5]
if not entries: print("No recorded 5xx incidents.")
else:
    for entry in entries:
        request = entry.get("request", {})
        print(f"[{entry.get('timestamp', '?')}] incident={entry.get('incident_id', '?')} HTTP={entry.get('status', '?')} {request.get('method', '?')} {request.get('path', '?')}")
        print(f"  {entry.get('exception', 'Exception')}: {entry.get('message', '')}")
        print(f"  at {entry.get('location', '?')}")
        for frame in entry.get("trace", [])[:6]: print(f"    {frame.get('file', '?')}:{frame.get('line', '?')} {frame.get('call', '')}")
        print()
PY
  printf '%s\n' '-------------------------------------------' >&2
}

vault_failure_status() {
  local migrations_blocked="$1"
  if [[ "$migrations_blocked" -eq 1 ]]; then return 3; fi
  return 4
}

module_failure() {
  local label="$1"
  printf 'MODULE_READ_ONLY=failed\n' >&2
  printf 'ERROR: authenticated read-only %s check failed; no repeated login requests.\n' "$label" >&2
  print_diagnostics
  return 5
}

# The same authenticated session already used for Vault exercises the actual
# Scheduler/Distribution/Traffic/Finance GET controllers, then the new CSV GET.
# No forms are submitted, no redirects are followed, and no public tracked
# links are opened (those would record clicks).
check_workspace_modules() {
  local workspace_path="${1%/vault}"
  local module marker status

  for module in scheduler distribution traffic finance; do
    case "$module" in
      scheduler) marker="Scheduling schema ready" ;;
      distribution) marker="Distribution ready" ;;
      traffic) marker="Traffic schema ready" ;;
      finance) marker="Finance schema ready" ;;
    esac

    status="$(curl_common --cookie "$cookie_jar" --output "$module_html" --write-out '%{http_code}' "$BASE_URL$workspace_path/$module" || true)"
    if [[ "$status" != 200 ]] || ! assert_contains "$module_html" "$marker"; then
      module_failure "$module"; return $?
    fi
    printf 'MODULE_READ_ONLY=%s:ok\n' "$module"
  done

  # A no-filter CSV GET uses the server's bounded default UTC window. Only
  # inspect headers and the fixed report header; never expose data rows.
  status="$(curl_common --cookie "$cookie_jar" --output "$csv_body" --dump-header "$csv_headers" --write-out '%{http_code}' "$BASE_URL$workspace_path/traffic/export" || true)"
  if [[ "$status" != 200 ]] ||
     ! grep -iEq '^content-type:[[:space:]]*text/csv([;[:space:]]|$)' "$csv_headers" ||
     ! grep -iEq '^content-disposition:[[:space:]]*attachment;' "$csv_headers" ||
     ! grep -Fq 'date_utc,label,short_link,channel,campaign,status,clicks' "$csv_body"; then
    module_failure "traffic CSV"; return $?
  fi
  printf 'MODULE_READ_ONLY=traffic-csv:ok\n'
}

run_smoke() {
  rm -f "$cookie_jar" "$login_html" "$dashboard_html" "$system_html" "$vault_html" "$diagnostics_json" "$up_body" "$up_headers" "$login_headers" "$module_html" "$csv_body" "$csv_headers"
  local up_status
  up_status="$(curl_common --output "$up_body" --dump-header "$up_headers" --write-out '%{http_code}' "$BASE_URL/up" || true)"
  if [[ "$up_status" != "200" ]]; then print_http_failure "health endpoint /up" "$up_status" "$up_headers" "$up_body"; return 1; fi

  local login_page_status
  login_page_status="$(curl_common --cookie-jar "$cookie_jar" --output "$login_html" --dump-header "$login_headers" --write-out '%{http_code}' "$BASE_URL/login" || true)"
  if [[ "$login_page_status" != "200" ]]; then print_http_failure "login page GET /login" "$login_page_status" "$login_headers" "$login_html"; return 1; fi

  local token
  if ! token="$(extract_csrf)"; then printf 'ERROR: login page did not expose a CSRF token.\n' >&2; return 1; fi
  local login_status
  login_status="$(curl_common --cookie "$cookie_jar" --cookie-jar "$cookie_jar" --output /dev/null --write-out '%{http_code}' --request POST --data-urlencode "_token=$token" --data-urlencode "email=$E2E_USER_EMAIL" --data-urlencode "password=$E2E_USER_PASSWORD" "$BASE_URL/login")"
  case "$login_status" in 302|303) ;; *) printf 'ERROR: login returned HTTP %s\n' "$login_status" >&2; return 1 ;; esac

  local dashboard_status
  dashboard_status="$(curl_common --cookie "$cookie_jar" --output "$dashboard_html" --write-out '%{http_code}' "$BASE_URL/dashboard")"
  if [[ "$dashboard_status" != "200" ]]; then printf 'ERROR: authenticated dashboard returned HTTP %s\n' "$dashboard_status" >&2; print_diagnostics; return 1; fi
  if ! assert_contains "$dashboard_html" "Overview"; then print_diagnostics; return 1; fi
  if ! assert_contains "$dashboard_html" "Tenant isolation active"; then print_diagnostics; return 1; fi

  local system_status
  system_status="$(curl_common --cookie "$cookie_jar" --output "$system_html" --write-out '%{http_code}' "$BASE_URL/admin/system")"
  if [[ "$system_status" != "200" ]]; then printf 'ERROR: admin system page returned HTTP %s\n' "$system_status" >&2; print_diagnostics; return 1; fi
  if ! assert_contains "$system_html" "Runtime configuration"; then print_diagnostics; return 1; fi

  local pending_migrations
  if ! pending_migrations="$(extract_pending_migrations)"; then printf 'ERROR: production migration inventory is unavailable.\n' >&2; return 1; fi
  local migrations_blocked=0
  if [[ "$pending_migrations" != "0" ]]; then
    migrations_blocked=1
    printf 'MIGRATIONS_PENDING=%s\n' "$pending_migrations"
    if python3 scripts/production-migration-inventory.py "$pending_migrations" < "$system_html"; then printf 'MIGRATION_INVENTORY_STATUS=verified\n'; else printf 'MIGRATION_INVENTORY_STATUS=unavailable\n'; fi
    printf 'BLOCKED: production has %s pending database migration(s). Review the exact batch and verified external backup in Admin > System; no migration was executed.\n' "$pending_migrations" >&2
  fi

  if grep -Fq 'data-media-storage-configured="1"' "$system_html"; then printf 'MEDIA_STORAGE_READY=1\n'; else printf 'MEDIA_STORAGE_READY=0\n'; printf 'WARN: media object storage is not configured; quick upload remains available.\n'; fi

  local vault_path
  if ! vault_path="$(extract_vault_path)"; then
    printf 'VAULT_READ_ONLY=failed\n'; printf 'ERROR: dashboard does not expose an organization Vault link.\n' >&2
    vault_failure_status "$migrations_blocked"; return $?
  fi
  local vault_status
  vault_status="$(curl_common --cookie "$cookie_jar" --output "$vault_html" --write-out '%{http_code}' "$BASE_URL$vault_path")"
  if [[ "$vault_status" != "200" ]]; then
    printf 'VAULT_READ_ONLY=failed\n'; printf 'ERROR: organization Vault returned HTTP %s\n' "$vault_status" >&2; print_diagnostics
    vault_failure_status "$migrations_blocked"; return $?
  fi
  if ! assert_contains "$vault_html" "Organization scoped"; then printf 'VAULT_READ_ONLY=failed\n'; print_diagnostics; vault_failure_status "$migrations_blocked"; return $?; fi
  if ! assert_contains "$vault_html" "Direct upload"; then printf 'VAULT_READ_ONLY=failed\n'; print_diagnostics; vault_failure_status "$migrations_blocked"; return $?; fi

  printf 'VAULT_READ_ONLY=ok\n'
  if [[ "$migrations_blocked" -eq 1 ]]; then return 2; fi

  # A product release label proves the observed runtime serves that release,
  # not the exact deployed Git commit (Hostinger checkout SHA remains unknown).
  if ! assert_contains "$system_html" "GrindFlow v$EXPECTED_RELEASE"; then
    printf 'ERROR: production release does not match candidate v%s.\n' "$EXPECTED_RELEASE" >&2
    return 1
  fi
  printf 'RELEASE_UI_OBSERVED=v%s\n' "$EXPECTED_RELEASE"

  check_workspace_modules "$vault_path" || return $?
  printf 'PASS production smoke: /up, /login, /dashboard, /admin/system, %s + workspace GETs + Traffic CSV\n' "$vault_path"
}

for attempt in $(seq 1 "$ATTEMPTS"); do
  printf 'Production smoke attempt %s/%s against %s\n' "$attempt" "$ATTEMPTS" "$BASE_URL"
  if run_smoke; then exit 0; else smoke_status=$?; fi
  case "$smoke_status" in
    2) printf 'BLOCKED: production smoke stopped on pending migrations after read-only Vault verification; no automatic migration or repeated login requests.\n' >&2; exit 2 ;;
    3) printf 'ERROR: read-only Vault check failed while migrations remain pending; no automatic migration or repeated login requests.\n' >&2; exit 3 ;;
    4) printf 'ERROR: read-only Vault check failed on the current schema; no repeated login requests.\n' >&2; exit 4 ;;
    5) printf 'ERROR: read-only workspace module check failed; no repeated login requests.\n' >&2; exit 5 ;;
  esac
  if [[ "$attempt" -lt "$ATTEMPTS" ]]; then sleep "$WAIT_SECONDS"; fi
done
printf 'ERROR: production smoke failed after %s attempts.\n' "$ATTEMPTS" >&2
exit 1
