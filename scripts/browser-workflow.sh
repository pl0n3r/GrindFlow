#!/usr/bin/env bash
set -euo pipefail

: "${E2E_USER_EMAIL:?E2E_USER_EMAIL is required}"
: "${E2E_USER_PASSWORD:?E2E_USER_PASSWORD is required}"
: "${E2E_ORG_NAME:?E2E_ORG_NAME is required}"

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ARTIFACT_DIR="${ARTIFACT_DIR:-tests/Browser/artifacts}"
CHROME="${GRINDFLOW_BROWSER_CHROME:-${CHROME_BIN:-google-chrome}}"

if ! command -v "$CHROME" >/dev/null 2>&1 && [[ ! -x "$CHROME" ]]; then
  echo "ERROR: Chromium is required for the authenticated workflow." >&2
  exit 1
fi

# Keep generated password-bearing bootstrap out of GitHub artifacts.
# Its script node removes itself from the dumped browser DOM at runtime.
bootstrap="public/__grindflow_e2e_workflow.html"
profile="$(mktemp -d)"
dom="$ARTIFACT_DIR/workflow-authenticated.html"

cleanup_workflow() {
  rm -f "$bootstrap"
  rm -rf "$profile"
}
trap cleanup_workflow EXIT

mkdir -p "$ARTIFACT_DIR"

python3 - "$bootstrap" <<'PY'
import json
import os
from pathlib import Path
import sys

template = Path("tests/Browser/workflow-template.html").read_text(encoding="utf-8")

def safe_json(value: str) -> str:
    return json.dumps(value).replace("<", "\\u003c").replace(">", "\\u003e")

replacements = {
    "__E2E_EMAIL_JSON__": safe_json(os.environ["E2E_USER_EMAIL"]),
    "__E2E_PASSWORD_JSON__": safe_json(os.environ["E2E_USER_PASSWORD"]),
    "__E2E_ORG_NAME_JSON__": safe_json(os.environ["E2E_ORG_NAME"]),
}

for key, value in replacements.items():
    if template.count(key) != 1:
        raise RuntimeError("Browser workflow template placeholder invalid: " + key)
    template = template.replace(key, value)

Path(sys.argv[1]).write_text(template, encoding="utf-8")
PY

"$CHROME" \
  --headless=new \
  --no-sandbox \
  --disable-dev-shm-usage \
  --disable-gpu \
  --hide-scrollbars \
  --window-size=1440,1000 \
  --virtual-time-budget=30000 \
  --user-data-dir="$profile" \
  --dump-dom \
  "$BASE_URL/__grindflow_e2e_workflow.html" > "$dom"

if grep -Fq "$E2E_USER_PASSWORD" "$dom"; then
  rm -f "$dom"
  echo "ERROR: discarded browser DOM because it included an E2E credential." >&2
  exit 1
fi

if ! grep -Fq 'SUCCESS: authenticated Scheduler' "$dom"; then
  echo "ERROR: authenticated Scheduler → Traffic → Finance workflow failed." >&2
  grep -o 'ERROR: [^<]*' "$dom" | head -1 >&2 || true
  echo "DOM artifact (sanitized): $dom" >&2
  exit 1
fi

for gate in \
  'PASS: real browser login' \
  'PASS: Scheduler 106 ready assets' \
  'PASS: search deep media/link' \
  'PASS: navigate real calendar page 2' \
  'PASS: detach and reattach tracked link' \
  'PASS: edit existing Traffic link' \
  'PASS: pause/resume link' \
  'PASS: authenticated Traffic CSV' \
  'PASS: Finance append-only allocation' \
  'PASS: reversal and filtered grouped Finance CSV'
do
  if ! grep -Fq "$gate" "$dom"; then
    echo "ERROR: authenticated browser workflow missing gate: $gate" >&2
    exit 1
  fi
done

echo "PASS browser-workflow: authenticated Scheduler → Traffic → Finance, 10 behavioral gates"
