#!/usr/bin/env bash
# Local only: owns its MariaDB container and never consumes DATABASE_URL.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
for tool in php composer node npm python3 curl docker; do
  command -v "$tool" >/dev/null || { echo "Baseline prerequisite missing: $tool" >&2; exit 2; }
done
node -e 'if (process.versions.node.split(".")[0] !== "22") { console.error("Baseline requires Node 22"); process.exit(2); }'
# Force the local socket; never inherit a remote Docker host/context.
unset DOCKER_CONTEXT
export DOCKER_HOST=unix:///var/run/docker.sock
docker info >/dev/null
# Dotenv must not import a developer's or hosting environment into this run.
for file in "$root"/.env "$root"/.env.local "$root"/.env.test "$root"/.env.test.local "$root"/.env.local.php; do
  [[ ! -e "$file" ]] || { echo "Baseline requires a checkout without active .env files" >&2; exit 2; }
done

# Drop inherited provider credentials, storage pointers and database settings.
env -i PATH="$PATH" HOME="$HOME" bash -s -- "$root" <<'BASELINE'
set -euo pipefail
cd "$1"
export DOCKER_HOST=unix:///var/run/docker.sock
tmp="$(mktemp -d)"
container="gf-symfony-baseline-$(basename "$tmp" | tr '[:upper:]' '[:lower:]')"
server_pid=''
cleanup() {
  result=$?
  trap - EXIT
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  docker rm -f "$container" >/dev/null 2>&1 || true
  rm -rf "$tmp"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
export APP_ENV=test APP_DEBUG=0
export APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export SYMFONY_PHPUNIT_VERSION=11.5.56
export SYMFONY_DEPRECATIONS_HELPER='max[total]=30&max[self]=0&max[direct]=0'
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run check
python3 -m unittest discover -s ../tests -p test_parity_matrix_executable.py -v

# Same MariaDB series as CI; no host volume or existing database is mounted.
docker run -d --name "$container" --tmpfs /var/lib/mysql \
  -e MARIADB_DATABASE=grindflow_symfony_ci \
  -e MARIADB_USER=grindflow -e MARIADB_PASSWORD=grindflow \
  -e MARIADB_ROOT_PASSWORD=baseline-disposable \
  -p 127.0.0.1::3306 mariadb:11.4 >/dev/null
ready=false
for attempt in {1..60}; do
  if docker exec "$container" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
    ready=true
    break
  fi
  sleep 1
done
[[ "$ready" == true ]] || { echo 'Disposable MariaDB did not become ready' >&2; exit 1; }
db_port="$(docker port "$container" 3306/tcp | sed 's/.*://')"
export DATABASE_URL="mysql://grindflow:grindflow@127.0.0.1:$db_port/grindflow_symfony_ci?serverVersion=mariadb-11.4.0&charset=utf8mb4"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate --skip-sync
php bin/console doctrine:query:sql 'SELECT 1'
php vendor/bin/simple-phpunit -c phpunit.xml.dist

http_port="$(python3 - <<'PY'
import socket
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    print(sock.getsockname()[1])
PY
)"
export S0_BASE_URL="http://127.0.0.1:$http_port"
php -S "127.0.0.1:$http_port" -t public public/router.php >"$tmp/http.log" 2>&1 &
server_pid=$!
ready=false
for attempt in {1..30}; do
  kill -0 "$server_pid" 2>/dev/null || { echo 'Symfony server exited' >&2; exit 1; }
  if curl --fail --silent "$S0_BASE_URL/health" >/dev/null; then
    ready=true
    break
  fi
  sleep 1
done
[[ "$ready" == true ]] || { echo 'Symfony HTTP did not become ready' >&2; exit 1; }
composer test:contract
# Use the installed, package-lock-bound CLI, never an npx fallback download.
./node_modules/.bin/playwright install chromium
npm run test:e2e
echo 'Disposable Symfony baseline passed; no staging, deployment or production evidence.'
BASELINE
