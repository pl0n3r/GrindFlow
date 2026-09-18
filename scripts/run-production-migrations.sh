#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://www.grindflow.com.co}"
E2E_USER_EMAIL="${E2E_USER_EMAIL:-e2e-admin@grindflow.test}"
OUTPUT_PATH="${OUTPUT_PATH:-production-migration-result.json}"
EXPECTED_PENDING="${EXPECTED_PENDING:-1}"

: "${E2E_USER_PASSWORD:?E2E_USER_PASSWORD is required}"

if [[ ! "$EXPECTED_PENDING" =~ ^[0-9]+$ ]] || (( EXPECTED_PENDING < 1 )); then
  printf 'ERROR: EXPECTED_PENDING must be a positive integer.\n' >&2
  exit 2
fi

workdir="$(mktemp -d)"
cookie_jar="$workdir/cookies.txt"
login_html="$workdir/login.html"
system_before="$workdir/system-before.html"
system_after="$workdir/system-after.html"

cleanup() {
  rm -rf "$workdir"
}
trap cleanup EXIT

write_result() {
  local ok="$1"
  local before="$2"
  local after="$3"
  local message="$4"

  python3 - "$OUTPUT_PATH" "$ok" "$before" "$after" "$message" <<'PY'
import json
import sys
from datetime import datetime, timezone

output_path, ok, before, after, message = sys.argv[1:6]

payload = {
    "generated_at": datetime.now(timezone.utc).isoformat(),
    "ok": ok == "true",
    "pending_before": None if before == "unknown" else int(before),
    "pending_after": None if after == "unknown" else int(after),
    "message": message,
}

with open(output_path, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
PY
}

extract_login_csrf() {
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

        values = dict(attrs)

        if values.get("name") == "_token" and values.get("value"):
            self.token = values["value"]

parser = TokenParser()

with open(sys.argv[1], encoding="utf-8") as handle:
    parser.feed(handle.read())

if not parser.token:
    raise SystemExit(2)

print(parser.token)
PY
}

extract_pending_count() {
  local file="$1"

  python3 - "$file" <<'PY'
from html.parser import HTMLParser
import sys

class PendingParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value = None

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)

        if "data-pending-migrations" in values:
            self.value = values["data-pending-migrations"]

parser = PendingParser()

with open(sys.argv[1], encoding="utf-8") as handle:
    parser.feed(handle.read())

if parser.value is None or parser.value == "unknown":
    raise SystemExit(2)

print(parser.value)
PY
}

extract_migration_csrf() {
  local file="$1"

  python3 - "$file" <<'PY'
from html.parser import HTMLParser
from urllib.parse import urlparse
import sys

class MigrationFormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_target_form = False
        self.token = None

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)

        if tag == "form":
            action = values.get("action", "")
            path = urlparse(action).path
            self.in_target_form = path == "/admin/system/migrations"
            return

        if (
            self.in_target_form
            and tag == "input"
            and values.get("name") == "_token"
            and values.get("value")
        ):
            self.token = values["value"]

    def handle_endtag(self, tag):
        if tag == "form":
            self.in_target_form = False

parser = MigrationFormParser()

with open(sys.argv[1], encoding="utf-8") as handle:
    parser.feed(handle.read())

if not parser.token:
    raise SystemExit(2)

print(parser.token)
PY
}

curl   --fail   --silent   --show-error   --max-time 20   --cookie-jar "$cookie_jar"   "$BASE_URL/login" > "$login_html"

login_token="$(extract_login_csrf)"

login_status="$(curl   --silent   --show-error   --max-time 20   --cookie "$cookie_jar"   --cookie-jar "$cookie_jar"   --output /dev/null   --write-out '%{http_code}'   --request POST   --data-urlencode "_token=$login_token"   --data-urlencode "email=$E2E_USER_EMAIL"   --data-urlencode "password=$E2E_USER_PASSWORD"   "$BASE_URL/login")"

case "$login_status" in
  302|303) ;;
  *)
    write_result false unknown unknown "Synthetic production login failed."
    printf 'ERROR: login returned HTTP %s.\n' "$login_status" >&2
    exit 1
    ;;
esac

system_status="$(curl   --silent   --show-error   --max-time 20   --cookie "$cookie_jar"   --output "$system_before"   --write-out '%{http_code}'   "$BASE_URL/admin/system")"

if [[ "$system_status" != "200" ]]; then
  write_result false unknown unknown "Admin System is unavailable."
  printf 'ERROR: Admin System returned HTTP %s.\n' "$system_status" >&2
  exit 1
fi

if ! pending_before="$(extract_pending_count "$system_before")"; then
  write_result false unknown unknown "Unable to determine pending migration count."
  printf 'ERROR: pending migration count is unavailable.\n' >&2
  exit 1
fi

if [[ "$pending_before" == "0" ]]; then
  write_result true 0 0 "Database schema is already current."
  printf 'PASS: production schema is already current.\n'
  exit 0
fi

if [[ "$pending_before" != "$EXPECTED_PENDING" ]]; then
  write_result false "$pending_before" unknown "Pending migration count does not match the explicit operator approval."
  printf 'ERROR: expected exactly %s pending migration(s), found %s. No migration was executed.\n'     "$EXPECTED_PENDING" "$pending_before" >&2
  exit 1
fi

migration_token="$(extract_migration_csrf "$system_before")"

migration_status="$(curl   --silent   --show-error   --max-time 90   --cookie "$cookie_jar"   --cookie-jar "$cookie_jar"   --output /dev/null   --write-out '%{http_code}'   --request POST   --data-urlencode "_token=$migration_token"   "$BASE_URL/admin/system/migrations")"

case "$migration_status" in
  302|303) ;;
  *)
    write_result false "$pending_before" unknown "Migration endpoint did not return a successful redirect."
    printf 'ERROR: migration endpoint returned HTTP %s.\n' "$migration_status" >&2
    exit 1
    ;;
esac

system_after_status="$(curl   --silent   --show-error   --max-time 20   --cookie "$cookie_jar"   --output "$system_after"   --write-out '%{http_code}'   "$BASE_URL/admin/system")"

if [[ "$system_after_status" != "200" ]]; then
  write_result false "$pending_before" unknown "Migration ran, but post-migration verification could not load Admin System."
  printf 'ERROR: post-migration Admin System returned HTTP %s.\n' "$system_after_status" >&2
  exit 1
fi

if ! pending_after="$(extract_pending_count "$system_after")"; then
  write_result false "$pending_before" unknown "Migration ran, but pending migration count could not be verified."
  printf 'ERROR: post-migration pending count is unavailable.\n' >&2
  exit 1
fi

if [[ "$pending_after" != "0" ]]; then
  write_result false "$pending_before" "$pending_after" "Migration endpoint completed, but pending migrations remain."
  printf 'ERROR: %s migration(s) remain pending after execution.\n' "$pending_after" >&2
  exit 1
fi

write_result true "$pending_before" "$pending_after" "Approved production migration completed and schema is current."
printf 'PASS: production migrations %s -> %s.\n' "$pending_before" "$pending_after"
