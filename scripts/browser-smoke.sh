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

  printf 'PASS %-16s %s\n' "$name" "$path"
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

echo "Real browser smoke tests passed."
