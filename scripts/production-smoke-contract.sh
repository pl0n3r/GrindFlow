#!/usr/bin/env bash
set -euo pipefail

# No network, credentials, database, or production mutation. All curl calls are mocked.
script_dir="$(cd "$(dirname "$0")" && pwd)"
workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

cat > "$workdir/mock-curl" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail

method=GET
output=/dev/null
headers=""
write_out=""
url=""

while (( $# > 0 )); do
  case "$1" in
    --output|--dump-header|--write-out|--request)
      case "$1" in
        --output) output="$2" ;;
        --dump-header) headers="$2" ;;
        --write-out) write_out="$2" ;;
        --request) method="$2" ;;
      esac
      shift 2
      ;;
    --cookie|--cookie-jar|--max-time|--user-agent|--header|--data-urlencode)
      shift 2
      ;;
    --silent|--show-error)
      shift
      ;;
    http://mock/*)
      url="$1"
      shift
      ;;
    *)
      printf 'unexpected fake-curl argument: %s\n' "$1" >&2
      exit 2
      ;;
  esac
done

status=200
body=""
case "$url" in
  http://mock/up)
    body="ok"
    ;;
  http://mock/login)
    if [[ "$method" == "POST" ]]; then
      status=302
    else
      body='<form><input name="_token" value="fake-csrf"></form>'
    fi
    ;;
  http://mock/dashboard)
    body='<h1>Overview</h1>Tenant isolation active <a href="/organizations/example/vault">Vault</a>'
    ;;
  http://mock/admin/system)
    body="<h2>Runtime configuration</h2><span data-pending-migrations=\"$MOCK_PENDING\">status</span><span data-media-storage-configured=\"0\">storage</span>"
    ;;
  http://mock/organizations/example/vault)
    body="Organization scoped Direct upload"
    ;;
  *)
    status=404
    ;;
esac

if [[ "$output" != "/dev/null" ]]; then
  printf '%s' "$body" > "$output"
fi
if [[ -n "$headers" ]]; then
  printf 'HTTP/1.1 %s\r\n' "$status" > "$headers"
fi
if [[ -n "$write_out" ]]; then
  printf '%s' "$status"
fi
MOCK
chmod +x "$workdir/mock-curl"

run_case() {
  local label="$1"
  local pending="$2"
  local expected_status="$3"
  local log="$workdir/$label.log"
  local result

  if MOCK_PENDING="$pending" \
    BASE_URL=http://mock \
    E2E_USER_PASSWORD=synthetic-only \
    CURL_BIN="$workdir/mock-curl" \
    ATTEMPTS=15 WAIT_SECONDS=0 \
    bash "$script_dir/production-smoke.sh" > "$log" 2>&1; then
    result=0
  else
    result=$?
  fi

  if [[ "$result" -ne "$expected_status" ]]; then
    printf 'FAIL %s: exit=%s expected=%s\n' "$label" "$result" "$expected_status" >&2
    cat "$log" >&2
    exit 1
  fi

  case "$label" in
    pending)
      grep -Fxq 'MIGRATIONS_PENDING=3' "$log"
      grep -Fq 'BLOCKED: production smoke stopped on pending migrations' "$log"
      if grep -Fq 'Production smoke attempt 2/' "$log"; then
        printf 'FAIL: migration-blocked smoke repeated a request.\n' >&2
        exit 1
      fi
      ;;
    current)
      grep -Fq 'PASS production smoke:' "$log"
      if grep -q '^MIGRATIONS_PENDING=' "$log"; then
        printf 'FAIL: current schema reported pending migrations.\n' >&2
        exit 1
      fi
      ;;
    unknown)
      grep -Fq 'ERROR: production migration inventory is unavailable.' "$log"
      if grep -q '^MIGRATIONS_PENDING=' "$log"; then
        printf 'FAIL: unknown schema was misclassified as pending.\n' >&2
        exit 1
      fi
      ;;
  esac
  printf 'PASS production smoke contract: %s\n' "$label"
}

run_case pending 3 2
run_case current 0 0

# Unknown schema uses the existing bounded retry policy rather than a migration claim.
if MOCK_PENDING=unknown BASE_URL=http://mock E2E_USER_PASSWORD=synthetic-only \
  CURL_BIN="$workdir/mock-curl" ATTEMPTS=1 WAIT_SECONDS=0 \
  bash "$script_dir/production-smoke.sh" > "$workdir/unknown.log" 2>&1; then
  printf 'FAIL: unknown schema passed smoke.\n' >&2
  exit 1
else
  result=$?
fi
[[ "$result" -eq 1 ]]
grep -Fq 'ERROR: production migration inventory is unavailable.' "$workdir/unknown.log"
if grep -q '^MIGRATIONS_PENDING=' "$workdir/unknown.log"; then
  printf 'FAIL: unknown schema was misclassified as pending.\n' >&2
  exit 1
fi
printf 'PASS production smoke contract: unknown\n'
