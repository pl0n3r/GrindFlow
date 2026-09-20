#!/usr/bin/env bash
set -euo pipefail
base="${S0_BASE_URL:-http://127.0.0.1:8765}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "S0 smoke: health response"
curl -fsS "$base/health" -D "$tmp/health-headers" >"$tmp/health.json"
python3 - "$tmp/health.json" <<'PY'
import json,sys
with open(sys.argv[1],encoding="utf-8") as f: body=json.load(f)
assert body["status"] == "ok" and body["stage"] == "s0-preview"
assert isinstance(body["version"],str) and body["version"] != "unavailable"
assert "release_sha" not in body and "database_url" not in body
PY
grep -qi '^x-request-id: [0-9a-f]\{24\}' "$tmp/health-headers"
grep -qi '^content-security-policy:' "$tmp/health-headers"
grep -qi '^cache-control: no-store' "$tmp/health-headers"

echo "S0 smoke: public home response"
curl -fsS "$base/" >"$tmp/home"
grep -q 'Una carga' "$tmp/home"
grep -q 'GrindFlow' "$tmp/home"
echo "S0 smoke: global CSS response"
curl -fsS "$base/assets/grindflow.css" -D "$tmp/cssheaders" >"$tmp/style"
grep -qi 'text/css' "$tmp/cssheaders"

echo "S0 smoke: React preview markup"
curl -fsS "$base/preview" >"$tmp/preview"
grep -q 'id="grindflow-preview"' "$tmp/preview"
grep -Eq '/build/assets/preview-[A-Za-z0-9_-]+\.js' "$tmp/preview"
grep -Eq '/build/assets/[^"]+\.css' "$tmp/preview"
echo "S0 smoke: preview manifest asset references"
js="$(grep -oE '/build/assets/preview-[A-Za-z0-9_-]+\.js' "$tmp/preview" | head -1)"
css="$(grep -oE '/build/assets/[^"]+\.css' "$tmp/preview" | head -1)"
echo "S0 smoke: asset HTTP responses"
curl -fsS "$base$js" >/dev/null
curl -fsS "$base$css" >/dev/null

echo "S0 smoke: private admin redirect"
code="$(curl -sS -o "$tmp/admin" -w '%{http_code}' "$base/admin")"
test "$code" = '302'
curl -fsS "$base/login" > "$tmp/login"
grep -q 'name="_csrf_token"' "$tmp/login"
echo "S0 smoke: unknown route status"
code="$(curl -sS -o "$tmp/notfound" -w '%{http_code}' "$base/not-found-S0")"
test "$code" = '404'
if grep -Eiq 'APP_SECRET|DATABASE_URL|Stack trace' "$tmp/home" "$tmp/preview" "$tmp/admin" "$tmp/notfound" "$tmp/health.json" "$tmp/login"; then
  echo 'S0 public responses exposed a sensitive diagnostic' >&2
  exit 1
fi
echo "S0 Symfony/Twig/React build, headers, login guard, private admin and error statuses passed."
