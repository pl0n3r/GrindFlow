#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ARTIFACT_DIR="${ARTIFACT_DIR:-tests/Browser/artifacts}"

mkdir -p "$ARTIFACT_DIR"

find_chrome() {
  local candidate

  if [[ -n "${CHROME_BIN:-}" && -x "${CHROME_BIN}" ]]; then
    printf '%s\n' "$CHROME_BIN"
    return 0
  fi

  for candidate in google-chrome google-chrome-stable chromium chromium-browser; do
    if command -v "$candidate" >/dev/null 2>&1; then
      command -v "$candidate"
      return 0
    fi
  done

  return 1
}

CHROME="$(find_chrome)" || {
  echo "ERROR: Chrome/Chromium is required for browser smoke tests." >&2
  exit 1
}

printf 'Browser: %s\n' "$("$CHROME" --version)"
printf 'Base URL: %s\n' "$BASE_URL"

assert_contains() {
  local file="$1"
  local expected="$2"

  if ! grep -Fq "$expected" "$file"; then
    echo "ERROR: expected text not found: $expected" >&2
    echo "DOM artifact: $file" >&2
    return 1
  fi
}

capture_page() {
  local name="$1"
  local path="$2"
  shift 2

  local profile
  profile="$(mktemp -d)"

  local dom="$ARTIFACT_DIR/$name.html"
  local screenshot="$ARTIFACT_DIR/$name.png"

  "$CHROME" \
    --headless=new \
    --no-sandbox \
    --disable-dev-shm-usage \
    --disable-gpu \
    --hide-scrollbars \
    --window-size=1440,1000 \
    --virtual-time-budget=2500 \
    --user-data-dir="$profile" \
    --dump-dom \
    "$BASE_URL$path" > "$dom"

  "$CHROME" \
    --headless=new \
    --no-sandbox \
    --disable-dev-shm-usage \
    --disable-gpu \
    --hide-scrollbars \
    --window-size=1440,1000 \
    --virtual-time-budget=2500 \
    --user-data-dir="$profile" \
    --screenshot="$screenshot" \
    "$BASE_URL$path" >/dev/null 2>&1

  rm -rf "$profile"

  local expected
  for expected in "$@"; do
    assert_contains "$dom" "$expected"
  done

  printf 'PASS %-20s %s\n' "$name" "$path"
}

capture_authenticated_dashboard() {
  : "${E2E_USER_EMAIL:?E2E_USER_EMAIL is required}"
  : "${E2E_USER_PASSWORD:?E2E_USER_PASSWORD is required}"
  : "${E2E_USER_NAME:?E2E_USER_NAME is required}"
  : "${E2E_ORG_NAME:?E2E_ORG_NAME is required}"

  local profile
  profile="$(mktemp -d)"

  local login_page="public/__grindflow_e2e_login.html"
  local dom="$ARTIFACT_DIR/dashboard-authenticated.html"

  cleanup_auth() {
    rm -f "$login_page"
    rm -rf "$profile"
  }
  trap cleanup_auth RETURN

  python3 - "$login_page" <<'PY'
import html
import json
import os
import pathlib
import sys

target = pathlib.Path(sys.argv[1])
email = json.dumps(os.environ["E2E_USER_EMAIL"])
password = json.dumps(os.environ["E2E_USER_PASSWORD"])

target.write_text(
    f"""<!doctype html>
<html>
<head><meta charset="utf-8"><title>GrindFlow E2E Login</title></head>
<body>
<p id="status">Authenticating…</p>
<script>
(async () => {{
  const loginResponse = await fetch('/login', {{credentials: 'include'}});
  const loginHtml = await loginResponse.text();
  const parsed = new DOMParser().parseFromString(loginHtml, 'text/html');
  const token = parsed.querySelector('input[name="_token"]')?.value;

  if (!token) {{
    document.getElementById('status').textContent = 'ERROR: CSRF token missing';
    return;
  }}

  const body = new URLSearchParams({{
    _token: token,
    email: {email},
    password: {password}
  }});

  const response = await fetch('/login', {{
    method: 'POST',
    credentials: 'include',
    headers: {{'Content-Type': 'application/x-www-form-urlencoded'}},
    body,
    redirect: 'follow'
  }});

  if (!response.ok) {{
    document.getElementById('status').textContent = 'ERROR: login request failed';
    return;
  }}

  window.location.assign('/dashboard');
}})();
</script>
</body>
</html>
""",
    encoding="utf-8",
)
PY

  "$CHROME" \
    --headless=new \
    --no-sandbox \
    --disable-dev-shm-usage \
    --disable-gpu \
    --hide-scrollbars \
    --window-size=1440,1000 \
    --virtual-time-budget=6000 \
    --user-data-dir="$profile" \
    --dump-dom \
    "$BASE_URL/__grindflow_e2e_login.html" > "$dom"

  assert_contains "$dom" "Overview"
  assert_contains "$dom" "$E2E_USER_NAME"
  assert_contains "$dom" "$E2E_ORG_NAME"
  assert_contains "$dom" "Tenant isolation active"

  printf 'PASS %-20s %s\n' "dashboard-auth" "/dashboard"
}

capture_page \
  "landing" \
  "/" \
  "Control operativo" \
  "Laravel core online" \
  "css/grindflow.css"

capture_page \
  "login" \
  "/login" \
  "Bienvenido." \
  "Entrar al workspace" \
  "css/grindflow.css"

headers="$ARTIFACT_DIR/dashboard-guest-headers.txt"

curl \
  --silent \
  --show-error \
  --max-redirs 0 \
  --dump-header "$headers" \
  --output /dev/null \
  "$BASE_URL/dashboard" || true

if ! grep -Eq '^HTTP/.* 30[12378]' "$headers"; then
  echo "ERROR: guest dashboard request did not redirect." >&2
  cat "$headers" >&2
  exit 1
fi

if ! grep -Eiq '^location:[[:space:]]+.*/login[[:space:]]*$' "$headers"; then
  echo "ERROR: guest dashboard redirect did not target /login." >&2
  cat "$headers" >&2
  exit 1
fi

capture_page \
  "dashboard-guest" \
  "/dashboard" \
  "Bienvenido." \
  "Entrar al workspace"

capture_authenticated_dashboard

echo "Real browser smoke tests passed."
