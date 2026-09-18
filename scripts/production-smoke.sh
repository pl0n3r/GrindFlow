#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://www.grindflow.com.co}"
E2E_USER_EMAIL="${E2E_USER_EMAIL:-e2e-admin@grindflow.test}"
: "${E2E_USER_PASSWORD:?E2E_USER_PASSWORD is required}"

ATTEMPTS="${ATTEMPTS:-12}"
WAIT_SECONDS="${WAIT_SECONDS:-20}"

workdir="$(mktemp -d)"
cookie_jar="$workdir/cookies.txt"
login_html="$workdir/login.html"
dashboard_html="$workdir/dashboard.html"
system_html="$workdir/system.html"

cleanup() {
  rm -rf "$workdir"
}
trap cleanup EXIT

extract_csrf() {
  python3 - "$login_html" <<'PY'
from html.parser import HTMLParser
import sys

class TokenParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.token = None

    def handle_starttag(self, tag, attrs):
        if tag != "input":
            return
        attrs = dict(attrs)
        if attrs.get("name") == "_token" and attrs.get("value"):
            self.token = attrs["value"]

parser = TokenParser()
with open(sys.argv[1], encoding="utf-8") as handle:
    parser.feed(handle.read())

if not parser.token:
    raise SystemExit(2)

print(parser.token)
PY
}

assert_contains() {
  local file="$1"
  local expected="$2"

  if ! grep -Fq "$expected" "$file"; then
    printf 'ERROR: expected text not found: %s\n' "$expected" >&2
    return 1
  fi
}

run_smoke() {
  rm -f "$cookie_jar" "$login_html" "$dashboard_html" "$system_html"

  curl --fail --silent --show-error --max-time 20     "$BASE_URL/up" >/dev/null

  curl --fail --silent --show-error --max-time 20     --cookie-jar "$cookie_jar"     "$BASE_URL/login" > "$login_html"

  local token
  token="$(extract_csrf)"

  local login_status
  login_status="$(curl --silent --show-error --max-time 20     --cookie "$cookie_jar"     --cookie-jar "$cookie_jar"     --output /dev/null     --write-out '%{http_code}'     --request POST     --data-urlencode "_token=$token"     --data-urlencode "email=$E2E_USER_EMAIL"     --data-urlencode "password=$E2E_USER_PASSWORD"     "$BASE_URL/login")"

  case "$login_status" in
    302|303) ;;
    *)
      printf 'ERROR: login returned HTTP %s\n' "$login_status" >&2
      return 1
      ;;
  esac

  local dashboard_status
  dashboard_status="$(curl --silent --show-error --max-time 20     --cookie "$cookie_jar"     --output "$dashboard_html"     --write-out '%{http_code}'     "$BASE_URL/dashboard")"

  if [[ "$dashboard_status" != "200" ]]; then
    printf 'ERROR: authenticated dashboard returned HTTP %s\n' "$dashboard_status" >&2
    return 1
  fi

  assert_contains "$dashboard_html" "Overview"
  assert_contains "$dashboard_html" "Tenant isolation active"

  local system_status
  system_status="$(curl --silent --show-error --max-time 20     --cookie "$cookie_jar"     --output "$system_html"     --write-out '%{http_code}'     "$BASE_URL/admin/system")"

  if [[ "$system_status" != "200" ]]; then
    printf 'ERROR: admin system page returned HTTP %s\n' "$system_status" >&2
    return 1
  fi

  assert_contains "$system_html" "Runtime configuration"
  assert_contains "$system_html" "Database connection"

  printf 'PASS production smoke: /up, /login, /dashboard, /admin/system\n'
}

for attempt in $(seq 1 "$ATTEMPTS"); do
  printf 'Production smoke attempt %s/%s against %s\n' "$attempt" "$ATTEMPTS" "$BASE_URL"

  if run_smoke; then
    exit 0
  fi

  if [[ "$attempt" -lt "$ATTEMPTS" ]]; then
    sleep "$WAIT_SECONDS"
  fi
done

printf 'ERROR: production smoke failed after %s attempts.\n' "$ATTEMPTS" >&2
exit 1
