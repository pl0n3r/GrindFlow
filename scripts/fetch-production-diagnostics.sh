#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://www.grindflow.com.co}"
E2E_USER_EMAIL="${E2E_USER_EMAIL:-e2e-admin@grindflow.test}"
OUTPUT_PATH="${OUTPUT_PATH:-production-diagnostics.json}"
LIMIT="${LIMIT:-20}"

: "${E2E_USER_PASSWORD:?E2E_USER_PASSWORD is required}"

if [[ ! "$LIMIT" =~ ^[0-9]+$ ]] || (( LIMIT < 1 || LIMIT > 50 )); then
  printf 'ERROR: LIMIT must be an integer between 1 and 50.\n' >&2
  exit 2
fi

workdir="$(mktemp -d)"
cookie_jar="$workdir/cookies.txt"
login_html="$workdir/login.html"
raw_json="$workdir/diagnostics.json"

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

write_status_artifact() {
  local status="$1"
  local message="$2"

  python3 - "$OUTPUT_PATH" "$status" "$message" <<'PY'
import json
import sys
from datetime import datetime, timezone

output_path, status, message = sys.argv[1:4]

payload = {
    "generated_at": datetime.now(timezone.utc).isoformat(),
    "ok": False,
    "http_status": status,
    "message": message,
    "entries": [],
}

with open(output_path, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
PY
}

curl   --fail   --silent   --show-error   --max-time 20   --cookie-jar "$cookie_jar"   "$BASE_URL/login" > "$login_html"

token="$(extract_csrf)"

login_status="$(curl   --silent   --show-error   --max-time 20   --cookie "$cookie_jar"   --cookie-jar "$cookie_jar"   --output /dev/null   --write-out '%{http_code}'   --request POST   --data-urlencode "_token=$token"   --data-urlencode "email=$E2E_USER_EMAIL"   --data-urlencode "password=$E2E_USER_PASSWORD"   "$BASE_URL/login")"

case "$login_status" in
  302|303) ;;
  *)
    write_status_artifact "$login_status" "Synthetic production login failed."
    printf 'ERROR: synthetic production login returned HTTP %s.\n' "$login_status" >&2
    exit 1
    ;;
esac

diagnostic_status="$(curl   --silent   --show-error   --max-time 20   --cookie "$cookie_jar"   --output "$raw_json"   --write-out '%{http_code}'   "$BASE_URL/admin/diagnostics.json" || true)"

if [[ "$diagnostic_status" != "200" ]]; then
  write_status_artifact "$diagnostic_status" "Diagnostics endpoint is unavailable."
  printf 'ERROR: diagnostics endpoint returned HTTP %s.\n' "$diagnostic_status" >&2
  exit 1
fi

python3 - "$raw_json" "$OUTPUT_PATH" "$LIMIT" <<'PY'
import json
import re
import sys
from datetime import datetime, timezone

raw_path, output_path, raw_limit = sys.argv[1:4]
limit = int(raw_limit)

EMAIL = re.compile(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", re.I)
IPV4 = re.compile(r"(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)")
UUID = re.compile(
    r"\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b",
    re.I,
)
LONG_TOKEN = re.compile(r"\b[A-Za-z0-9_-]{40,}\b")

def clean_text(value, maximum=1200):
    if value is None:
        return None

    text = str(value)
    text = EMAIL.sub("[redacted-email]", text)
    text = IPV4.sub("[redacted-ip]", text)
    text = UUID.sub("[redacted-uuid]", text)
    text = LONG_TOKEN.sub("[redacted-token]", text)

    if len(text) > maximum:
        return text[:maximum] + "..."

    return text

with open(raw_path, encoding="utf-8") as handle:
    source = json.load(handle)

safe_entries = []

for entry in source.get("entries", [])[:limit]:
    request = entry.get("request") or {}

    trace = []

    for frame in (entry.get("trace") or [])[:12]:
        trace.append({
            "file": clean_text(frame.get("file"), 300),
            "line": frame.get("line"),
            "call": clean_text(frame.get("call"), 300),
        })

    safe_entries.append({
        "timestamp": entry.get("timestamp"),
        "incident_id": entry.get("incident_id"),
        "status": entry.get("status"),
        "exception": clean_text(entry.get("exception"), 300),
        "message": clean_text(entry.get("message")),
        "location": clean_text(entry.get("location"), 400),
        "request": {
            "source": request.get("source"),
            "method": request.get("method"),
            "path": clean_text(request.get("path"), 500),
            "route": clean_text(request.get("route"), 300),
        },
        "trace": trace,
    })

payload = {
    "generated_at": datetime.now(timezone.utc).isoformat(),
    "source_generated_at": source.get("generated_at"),
    "ok": True,
    "entry_count": len(safe_entries),
    "entries": safe_entries,
}

with open(output_path, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
PY

printf 'Production diagnostics captured: %s sanitized incident(s).\n'   "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8")).get("entry_count", 0))' "$OUTPUT_PATH")"
