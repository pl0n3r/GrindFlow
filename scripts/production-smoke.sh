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
EXPECTED_SHA="${EXPECTED_SHA:-$(git -C "$script_dir/.." rev-parse HEAD 2>/dev/null || true)}"
[[ "$EXPECTED_RELEASE" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { printf 'ERROR: expected release version is unavailable.\n' >&2; exit 1; }
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || { printf 'ERROR: expected Git SHA is unavailable or invalid.\n' >&2; exit 1; }
export EXPECTED_SHA

workdir="$(mktemp -d)"
cleanup() { rm -rf "$workdir"; }
trap cleanup EXIT
# Curl reads the credential from a private file, never from its process argv.
umask 077
password_file="$workdir/login-password"
printf '%s' "$E2E_USER_PASSWORD" > "$password_file"
chmod 600 "$password_file"
unset E2E_USER_PASSWORD
cookie_jar="$workdir/cookies.txt"
login_html="$workdir/login.html"
login_recheck_html="$workdir/login-recheck.html"
login_recheck_headers="$workdir/login-recheck.headers"
login_failure_html="$workdir/login-failure-recheck.html"
login_failure_headers="$workdir/login-failure-recheck.headers"
dashboard_html="$workdir/dashboard.html"
system_html="$workdir/system.html"
vault_html="$workdir/vault.html"
diagnostics_json="$workdir/diagnostics.json"
health_body="$workdir/health.json"
health_headers="$workdir/health.headers"
home_body="$workdir/home.html"
home_headers="$workdir/home.headers"
login_headers="$workdir/login.headers"
login_post_headers="$workdir/login-post.headers"
csrf_file="$workdir/login-csrf"
dashboard_headers="$workdir/dashboard.headers"
module_html="$workdir/module.html"
csv_body="$workdir/traffic.csv"
csv_headers="$workdir/traffic.headers"

curl_common() {
  "$CURL_BIN" --silent --show-error --max-time 20 --user-agent "$SMOKE_USER_AGENT" --header "Accept: $SMOKE_ACCEPT" --header "Accept-Language: en-US,en;q=0.8" "$@"
}

# Keep only canonical v4 incident UUIDs, never arbitrary remote header values.
safe_incident_from_headers() {
  python3 - "$1" <<'PY'
import re
import sys

identifier = "unknown"
try:
    with open(sys.argv[1], encoding="utf-8", errors="replace") as handle:
        for line in handle:
            name, separator, value = line.partition(":")
            if separator and name.lower() == "x-incident-id":
                candidate = value.strip()
                if re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", candidate):
                    identifier = candidate
                break
except OSError:
    pass
print(identifier)
PY
}

# Only emit status, local label and an allowlisted incident UUID (or unknown).
print_http_failure() {
  local label="$1" status="$2" headers="${3:-}" incident="unknown"
  if [[ -n "$headers" ]]; then incident="$(safe_incident_from_headers "$headers")"; fi
  printf 'ERROR: %s returned HTTP %s incident_id=%s\n' "$label" "$status" "$incident" >&2
}

# Report only known local paths, including strict same-origin absolute redirects.
# Never retain host, query string, fragment or any unrecognized redirect value.
safe_redirect_path() {
  python3 - "$1" "$BASE_URL" <<'PY'
import sys
from urllib.parse import urlsplit

ALLOWLIST = {"/login", "/dashboard", "/organizations", "/admin", "/admin/system"}

def effective_port(url):
    return url.port if url.port is not None else {"http": 80, "https": 443}.get(url.scheme)

try:
    origin = urlsplit(sys.argv[2])
    if origin.scheme not in {"http", "https"} or not origin.hostname or origin.username is not None or origin.password is not None:
        raise ValueError("invalid smoke origin")
    with open(sys.argv[1], encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if not line.lower().startswith("location:"):
                continue
            destination = urlsplit(line.partition(":")[2].strip())
            if destination.scheme or destination.netloc:
                allowed_origin = (
                    destination.scheme == origin.scheme
                    and destination.hostname == origin.hostname
                    and effective_port(destination) == effective_port(origin)
                    and destination.username is None
                    and destination.password is None
                )
                path = destination.path if allowed_origin else None
            else:
                path = destination.path
            print(path if path in ALLOWLIST else "(redacted)")
            break
        else:
            print("(missing)")
except (OSError, ValueError):
    print("(redacted)")
PY
}

extract_csrf() {
  python3 - "${1:-$login_html}" <<'PY'
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

extract_observed_release() {
  python3 - "$system_html" <<'PY'
from html.parser import HTMLParser
import re
import sys

SEMVER = re.compile(r"^[0-9]+[.][0-9]+[.][0-9]+$")
LEGACY = re.compile(r"\bGrindFlow v([0-9]+[.][0-9]+[.][0-9]+)\b")

class ReleaseParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.markers = []
        self.legacy = []

    def handle_starttag(self, tag, attrs):
        value = dict(attrs).get("data-grindflow-release")
        if value is not None:
            self.markers.append(value)

    def handle_data(self, data):
        self.legacy.extend(LEGACY.findall(data))

parser = ReleaseParser()
with open(sys.argv[1], encoding="utf-8", errors="replace") as handle:
    parser.feed(handle.read())
values = parser.markers if parser.markers else parser.legacy
if not values or any(not SEMVER.fullmatch(value) for value in values):
    raise SystemExit(2)
if len(set(values)) != 1:
    raise SystemExit(2)
print(values[0])
PY
}

extract_health_identity() {
  python3 - "$health_body" <<'PY'
import json
import re
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except (OSError, UnicodeError, json.JSONDecodeError):
    raise SystemExit(2)

if not isinstance(payload, dict):
    raise SystemExit(2)
if payload.get("status") != "ok" or payload.get("exact") is not True:
    raise SystemExit(2)

version = payload.get("version")
commit = payload.get("commit")
if not isinstance(version, str) or re.fullmatch(r"[0-9]+[.][0-9]+[.][0-9]+", version) is None:
    raise SystemExit(2)
if not isinstance(commit, str) or re.fullmatch(r"[0-9a-f]{40}", commit) is None:
    raise SystemExit(2)

print(version)
print(commit)
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
  # The remote JSON is untrusted. Never put message, paths, traces,
  # identifiers or timestamps into the retained GitHub Actions artifact.
  python3 - "$diagnostics_json" >&2 <<'PY'
import json
import re
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
    entries = payload.get("entries", [])
    if not isinstance(entries, list):
        raise ValueError("invalid incident collection")
except (OSError, UnicodeError, ValueError, TypeError, AttributeError):
    print("Incident summary unavailable (invalid diagnostics response).")
else:
    if not entries:
        print("No recorded 5xx incidents.")
    else:
        print(f"Recorded incidents (up to 5): {min(len(entries), 5)}")
        for index, item in enumerate(entries[:5], start=1):
            entry = item if isinstance(item, dict) else {}
            raw_status = entry.get("status")
            status = raw_status if type(raw_status) is int and 100 <= raw_status <= 599 else "unknown"
            request = entry.get("request")
            request = request if isinstance(request, dict) else {}
            method = request.get("method")
            method = method if method in ("GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS") else "unknown"
            raw_identifier = entry.get("incident_id")
            incident_id = raw_identifier if isinstance(raw_identifier, str) and re.fullmatch(
                r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", raw_identifier
            ) else "unknown"
            print(f"Incident #{index}: HTTP={status} method={method} incident_id={incident_id}")
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

# One anonymous GET after a rejected POST checks if the session/CSRF persisted.
# Only allowlisted markers leave the private workspace; NEVER re-POST credentials.
# A changed token is a diagnostic signal, not proof of an invalid password.
check_failed_login_session() {
  local check_status after_token before_token
  check_status="$(curl_common --cookie "$cookie_jar" --cookie-jar "$cookie_jar" --output "$login_failure_html" --dump-header "$login_failure_headers" --write-out '%{http_code}' "$BASE_URL/login" || true)"
  if [[ "$check_status" != "200" ]]; then
    printf 'LOGIN_FAILURE_SESSION_CHECK=unavailable\n'
    return
  fi
  if ! after_token="$(extract_csrf "$login_failure_html")"; then
    printf 'LOGIN_FAILURE_SESSION_CHECK=unavailable\n'
    return
  fi
  before_token="$(<"$csrf_file")"
  if [[ "$after_token" == "$before_token" ]]; then
    printf 'LOGIN_FAILURE_SESSION_CHECK=stable\n'
  else
    printf 'LOGIN_FAILURE_SESSION_CHECK=changed\n'
  fi
  unset after_token before_token
}

run_smoke() {
  rm -f "$cookie_jar" "$login_html" "$login_recheck_html" "$login_recheck_headers" "$login_failure_html" "$login_failure_headers" "$dashboard_html" "$system_html" "$vault_html" "$diagnostics_json" "$health_body" "$health_headers" "$home_body" "$home_headers" "$login_headers" "$login_post_headers" "$dashboard_headers" "$module_html" "$csv_body" "$csv_headers" "$csrf_file"

  local health_status health_version health_sha
  local -a health_identity=()
  health_status="$(curl_common --output "$health_body" --dump-header "$health_headers" --write-out '%{http_code}' "$BASE_URL/health" || true)"
  if [[ "$health_status" != "200" ]]; then
    print_http_failure "exact health endpoint /health" "$health_status" "$health_headers"
    return 1
  fi
  mapfile -t health_identity < <(extract_health_identity)
  if (( ${#health_identity[@]} != 2 )); then
    printf 'ERROR: exact health identity is invalid or incomplete.\n' >&2
    return 1
  fi
  health_version="${health_identity[0]}"
  health_sha="${health_identity[1]}"
  printf 'HEALTH_VERSION=v%s\n' "$health_version"
  printf 'HEALTH_SHA=%s\n' "$health_sha"
  if [[ "$health_version" != "$EXPECTED_RELEASE" || "$health_sha" != "$EXPECTED_SHA" ]]; then
    printf 'ERROR: production health identity does not match the exact main candidate yet.\n' >&2
    return 1
  fi

  local home_status
  home_status="$(curl_common --output "$home_body" --dump-header "$home_headers" --write-out '%{http_code}' "$BASE_URL/" || true)"
  if [[ "$home_status" != "200" ]]; then
    print_http_failure "home GET /" "$home_status" "$home_headers"
    return 1
  fi

  local login_page_status
  login_page_status="$(curl_common --cookie-jar "$cookie_jar" --output "$login_html" --dump-header "$login_headers" --write-out '%{http_code}' "$BASE_URL/login" || true)"
  if [[ "$login_page_status" != "200" ]]; then print_http_failure "login page GET /login" "$login_page_status" "$login_headers"; return 1; fi

  local initial_token current_token recheck_status
  if ! initial_token="$(extract_csrf)"; then
    printf 'ERROR: login page did not expose a CSRF token.\n' >&2
    return 1
  fi

  # A second read-only GET must preserve the anonymous session/CSRF across
  # requests using the same cookie jar. If it does not, never spend a login
  # attempt or classify the account/password as invalid.
  recheck_status="$(curl_common --cookie "$cookie_jar" --cookie-jar "$cookie_jar" --output "$login_recheck_html" --dump-header "$login_recheck_headers" --write-out '%{http_code}' "$BASE_URL/login" || true)"
  if [[ "$recheck_status" != "200" ]]; then
    unset initial_token
    print_http_failure "login session recheck GET /login" "$recheck_status" "$login_recheck_headers"
    return 1
  fi
  if ! current_token="$(extract_csrf "$login_recheck_html")"; then
    unset initial_token
    printf 'ERROR: second login page did not expose a CSRF token.\n' >&2
    return 7
  fi
  if [[ "$current_token" != "$initial_token" ]]; then
    unset current_token initial_token
    printf 'LOGIN_SESSION_PREFLIGHT=inconsistent\n'
    printf 'ERROR: anonymous session/CSRF changed across identical GET requests; no login POST was sent.\n' >&2
    return 7
  fi
  printf 'LOGIN_SESSION_PREFLIGHT=consistent\n'
  # Keep the CSRF token out of curl argv; always use the rechecked token.
  printf '%s' "$current_token" > "$csrf_file"
  unset current_token initial_token
  local login_status
  login_status="$(curl_common --cookie "$cookie_jar" --cookie-jar "$cookie_jar" --dump-header "$login_post_headers" --output /dev/null --write-out '%{http_code}' --request POST --data-urlencode "_token@$csrf_file" --data-urlencode "email=$E2E_USER_EMAIL" --data-urlencode "password@$password_file" "$BASE_URL/login")"
  case "$login_status" in
    302|303) ;;
    3[0-9][0-9])
      printf 'LOGIN_REDIRECT_PATH=%s\n' "$(safe_redirect_path "$login_post_headers")"
      printf 'ERROR: login returned HTTP %s; stop authentication retries on unexpected redirect.\n' "$login_status" >&2
      return 7 ;;
    401|403|419|422|429) printf 'ERROR: login returned HTTP %s; stop authentication retries.\n' "$login_status" >&2; return 7 ;;
    *) printf 'ERROR: login returned HTTP %s\n' "$login_status" >&2; return 1 ;;
  esac
  local login_redirect
  login_redirect="$(safe_redirect_path "$login_post_headers")"
  printf 'LOGIN_REDIRECT_PATH=%s\n' "$login_redirect"
  if [[ "$login_redirect" == "/login" ]]; then
    check_failed_login_session
    printf 'ERROR: login redirected back to /login; credentials or account/session require investigation. No repeated login attempts.\n' >&2
    return 7
  fi
  if [[ "$login_redirect" != "/dashboard" ]]; then
    printf 'ERROR: login redirect was not a recognized local dashboard path; no repeated login attempts.\n' >&2
    return 7
  fi

  local dashboard_status
  dashboard_status="$(curl_common --cookie "$cookie_jar" --dump-header "$dashboard_headers" --output "$dashboard_html" --write-out '%{http_code}' "$BASE_URL/dashboard")"
  if [[ "$dashboard_status" =~ ^3[0-9][0-9]$ ]]; then
    printf 'ERROR: authenticated dashboard returned HTTP %s, redirect path %s; check authentication/session. No repeated login attempts.\n' "$dashboard_status" "$(safe_redirect_path "$dashboard_headers")" >&2
    return 7
  fi
  case "$dashboard_status" in
    401|403|419|422|429) printf 'ERROR: authenticated dashboard returned HTTP %s; stop authentication retries.\n' "$dashboard_status" >&2; return 7 ;;
  esac
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
  local observed_release
  if ! observed_release="$(extract_observed_release)"; then
    printf 'RELEASE_UI_OBSERVED=unknown\n'
    printf 'RELEASE_UI_EXPECTED=v%s\n' "$EXPECTED_RELEASE"
    printf 'ERROR: production release cannot be identified from Admin System.\n' >&2
    return 6
  fi
  printf 'RELEASE_UI_OBSERVED=v%s\n' "$observed_release"
  printf 'RELEASE_UI_EXPECTED=v%s\n' "$EXPECTED_RELEASE"
  if [[ "$observed_release" != "$EXPECTED_RELEASE" ]]; then
    printf 'ERROR: production release v%s differs from candidate v%s; no retry for a deterministic version mismatch.\n' "$observed_release" "$EXPECTED_RELEASE" >&2
    return 6
  fi

  check_workspace_modules "$vault_path" || return $?
  printf 'PASS production smoke: /health exact-main, /, /login, /dashboard, /admin/system, %s + workspace GETs + Traffic CSV\n' "$vault_path"
}

for attempt in $(seq 1 "$ATTEMPTS"); do
  printf 'Production smoke attempt %s/%s against %s\n' "$attempt" "$ATTEMPTS" "$BASE_URL"
  if run_smoke; then exit 0; else smoke_status=$?; fi
  case "$smoke_status" in
    2) printf 'BLOCKED: production smoke stopped on pending migrations after read-only Vault verification; no automatic migration or repeated login requests.\n' >&2; exit 2 ;;
    3) printf 'ERROR: read-only Vault check failed while migrations remain pending; no automatic migration or repeated login requests.\n' >&2; exit 3 ;;
    4) printf 'ERROR: read-only Vault check failed on the current schema; no repeated login requests.\n' >&2; exit 4 ;;
    5) printf 'ERROR: read-only workspace module check failed; no repeated login requests.\n' >&2; exit 5 ;;
    6) printf 'ERROR: production release inventory failed or differs; no repeated login requests.\n' >&2; exit 6 ;;
    7) printf 'ERROR: authentication failure is deterministic; do not retry credentials.\n' >&2; exit 7 ;;
  esac
  if [[ "$attempt" -lt "$ATTEMPTS" ]]; then sleep "$WAIT_SECONDS"; fi
done
printf 'ERROR: production smoke failed after %s attempts.\n' "$ATTEMPTS" >&2
exit 1
