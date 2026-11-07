#!/usr/bin/env bash
# Local only: owns its MariaDB data directory/container; ignores DATABASE_URL.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
mode="${GF_BASELINE_DB:-docker}"
tools=(php composer node npm python3 curl)
case "$mode" in
  docker) tools+=(docker) ;;
  native) tools+=(mariadbd mariadb-install-db mariadb mariadb-admin) ;;
  *) echo 'GF_BASELINE_DB must be docker or native' >&2; exit 2 ;;
esac
for tool in "${tools[@]}"; do
  command -v "$tool" >/dev/null || { echo "Baseline prerequisite missing: $tool" >&2; exit 2; }
done
node -e 'if (process.versions.node.split(".")[0] !== "22") { console.error("Baseline requires Node 22"); process.exit(2); }'
if [[ "$mode" == docker ]]; then
  # Force the local socket; never inherit a remote Docker host/context.
  unset DOCKER_CONTEXT
  export DOCKER_HOST=unix:///var/run/docker.sock
  docker info >/dev/null
fi
# Dotenv must not import a developer's or hosting environment into this run.
for file in "$root"/.env "$root"/.env.local "$root"/.env.test "$root"/.env.test.local "$root"/.env.local.php; do
  [[ ! -e "$file" ]] || { echo "Baseline requires a checkout without active .env files" >&2; exit 2; }
done

# Drop inherited provider credentials, storage pointers and database settings.
env -i PATH="$PATH" HOME="$HOME" bash -s -- "$root" "$mode" <<'BASELINE'
set -euo pipefail
cd "$1"
mode="$2"
export DOCKER_HOST=unix:///var/run/docker.sock
tmp="$(mktemp -d /tmp/gf-symfony-baseline.XXXXXX)"
tmp="$(cd "$tmp" && pwd -P)"
container="gf-symfony-baseline-$(basename "$tmp" | tr '[:upper:]' '[:lower:]')"
server_pid=''
db_pid=''
cleanup() {
  result=$?
  trap - EXIT
  if [[ "$result" != 0 ]]; then
    for log in "$tmp/db-init.log" "$tmp/db.log" "$tmp/http.log"; do
      if [[ -f "$log" ]]; then tail -60 "$log" >&2; fi
    done
  fi
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  if [[ -n "$db_pid" ]]; then
    kill "$db_pid" 2>/dev/null || true
    wait "$db_pid" 2>/dev/null || true
  fi
  if [[ "$mode" == docker ]]; then
    docker rm -f "$container" >/dev/null 2>&1 || true
  fi
  rm -rf "$tmp"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
export APP_ENV=test APP_DEBUG=0
export TMPDIR="$tmp"
export COMPOSER_CACHE_DIR="$tmp/composer-cache"
export npm_config_cache="$tmp/npm-cache"
export PLAYWRIGHT_BROWSERS_PATH="$tmp/browsers"
export APP_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export SYMFONY_DEPRECATIONS_HELPER='max[total]=30&max[self]=0&max[direct]=0'
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run check
python3 -m unittest discover -s ../tests -p test_parity_matrix_executable.py -v

if [[ "$mode" == docker ]]; then
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
  db_version=11.4.0
else
  db_version="$(mariadbd --version | sed -nE 's/.*Ver ([0-9]+\.[0-9]+\.[0-9]+)-MariaDB.*/\1/p')"
  [[ -n "$db_version" ]] || { echo 'Cannot identify native MariaDB version' >&2; exit 2; }
  python3 - "$db_version" <<'PY_VERSION'
import sys
assert tuple(map(int, sys.argv[1].split('.'))) >= (11, 4, 0), 'MariaDB 11.4+ required'
PY_VERSION
  db_port="$(python3 - <<'PY_PORT'
import socket
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    print(sock.getsockname()[1])
PY_PORT
)"
  mariadb-install-db --no-defaults --datadir="$tmp/db" \
    --auth-root-authentication-method=normal --skip-test-db >"$tmp/db-init.log" 2>&1
  mariadbd --no-defaults --datadir="$tmp/db" --socket="$tmp/db.sock" \
    --bind-address=127.0.0.1 --port="$db_port" --pid-file="$tmp/db.pid" \
    --log-error="$tmp/db.log" >"$tmp/db-stdout.log" 2>&1 &
  db_pid=$!
  ready=false
  for attempt in {1..60}; do
    kill -0 "$db_pid" 2>/dev/null || { cat "$tmp/db.log" >&2; exit 1; }
    if mariadb-admin --no-defaults --socket="$tmp/db.sock" -u root ping >/dev/null 2>&1; then
      ready=true
      break
    fi
    sleep 1
  done
  [[ "$ready" == true ]] || { echo 'Native disposable MariaDB did not become ready' >&2; exit 1; }
  mariadb --no-defaults --socket="$tmp/db.sock" -u root <<'SQL'
CREATE DATABASE grindflow_symfony_ci;
CREATE USER 'grindflow'@'127.0.0.1' IDENTIFIED BY 'grindflow';
GRANT ALL PRIVILEGES ON grindflow_symfony_ci.* TO 'grindflow'@'127.0.0.1';
SQL
fi
echo "Disposable MariaDB mode=$mode version=$db_version"
export DATABASE_URL="mysql://grindflow:grindflow@127.0.0.1:$db_port/grindflow_symfony_ci?serverVersion=mariadb-$db_version&charset=utf8mb4"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate --skip-sync
php bin/console doctrine:query:sql 'SELECT 1'
php vendor/bin/phpunit -c phpunit.xml.dist

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
