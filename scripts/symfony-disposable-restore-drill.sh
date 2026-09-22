#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SYMFONY_DIR="$ROOT/symfony"
EXPECTED_DB="grindflow_symfony_ci"
MARIADB_IMAGE="mariadb:11.4"

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 2
}

[[ "${GF_RESTORE_DRILL_APPROVED:-}" == "1" ]] || fail "restore drill requires explicit approval."
[[ "${APP_ENV:-}" == "test" ]] || fail "restore drill is test-only."
[[ "${CI:-}" == "true" ]] || fail "restore drill is CI-only."
[[ -n "${DATABASE_URL:-}" ]] || fail "DATABASE_URL is required."

mapfile -t db_parts < <(
  php -r '
    $parts = parse_url((string) getenv("DATABASE_URL"));
    if (!is_array($parts)) {
        exit(2);
    }
    echo rawurldecode((string) ($parts["host"] ?? "")), "\n";
    echo (int) ($parts["port"] ?? 3306), "\n";
    echo rawurldecode(ltrim((string) ($parts["path"] ?? ""), "/")), "\n";
    echo rawurldecode((string) ($parts["user"] ?? "")), "\n";
    echo rawurldecode((string) ($parts["pass"] ?? "")), "\n";
  '
) || fail "DATABASE_URL could not be parsed."

db_host="${db_parts[0]:-}"
db_port="${db_parts[1]:-}"
db_name="${db_parts[2]:-}"
db_user="${db_parts[3]:-}"
db_password="${db_parts[4]:-}"

[[ "$db_host" == "127.0.0.1" || "$db_host" == "localhost" ]] || fail "restore drill requires a loopback MariaDB host."
[[ "$db_port" == "3306" ]] || fail "restore drill requires the disposable MariaDB port."
[[ "$db_name" == "$EXPECTED_DB" ]] || fail "restore drill requires the disposable CI database."
[[ -n "$db_user" && -n "$db_password" ]] || fail "DATABASE_URL credentials are incomplete."
command -v docker >/dev/null 2>&1 || fail "docker is required for the disposable database restore."

base="$(mktemp -d /tmp/grindflow-restore-drill.XXXXXX)"
chmod 0700 "$base"
source_vault="$base/source-vault"
restored_vault="$base/restored-vault"
stage="$base/stage"
dump="$base/database.sql"
source_structure="$base/source-structure.json"
restored_structure="$base/restored-structure.json"
envelope="$base/structure-envelope.json"

cleanup() {
  rm -rf "$base"
}
trap cleanup EXIT

install -d -m 0700 "$source_vault"

user_id="018f0000-0000-7000-8000-000000000001"
org_id="018f0000-0000-7000-8000-000000000002"
asset_id="018f0000-0000-7000-8000-000000000003"
created_at="2026-01-01 00:00:00"
blob="$source_vault/$asset_id.blob"
printf '%s' 'grindflow-disposable-restore-drill' > "$blob"
chmod 0600 "$blob"
blob_sha="$(sha256sum "$blob" | awk '{print $1}')"
blob_size="$(wc -c < "$blob" | tr -d ' ')"

cd "$SYMFONY_DIR"

php bin/console doctrine:query:sql "
  INSERT INTO gf_identity_users
    (id, name, email, password_hash, platform_role, is_active, created_at, updated_at)
  VALUES
    ('$user_id', 'Restore Drill User', 'restore-drill@example.test', 'synthetic-not-a-login-secret', 'admin', 1, '$created_at', '$created_at')
" >/dev/null

php bin/console doctrine:query:sql "
  INSERT INTO gf_identity_organizations
    (id, name, slug, type, created_at, updated_at)
  VALUES
    ('$org_id', 'Restore Drill Org', 'restore-drill-org', 'independent', '$created_at', '$created_at')
" >/dev/null

php bin/console doctrine:query:sql "
  INSERT INTO gf_vault_assets
    (id, organization_id, uploaded_by, original_name, mime_type, size_bytes, sha256, storage_key,
     created_at, deleted_at, deleted_by, private_note, usage_scope)
  VALUES
    ('$asset_id', '$org_id', '$user_id', 'restore-drill.bin', 'image/png', $blob_size, '$blob_sha',
     '$asset_id', '$created_at', NULL, NULL, 'synthetic restore drill', 'internal_only')
" >/dev/null

audit="$(
  GRINDFLOW_VAULT_ROOT="$source_vault"     php bin/console grindflow:vault:audit --organization="$org_id"
)"
manifest_sha="$(
  printf '%s' "$audit" |
    php -r '
      $value = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
      if (($value["status"] ?? null) !== "verified" || !is_string($value["manifest_sha256"] ?? null)) {
          exit(2);
      }
      echo $value["manifest_sha256"];
    '
)"
[[ "$manifest_sha" =~ ^[0-9a-f]{64}$ ]] || fail "source Vault audit did not produce a valid manifest."

GRINDFLOW_VAULT_ROOT="$source_vault"   php bin/console grindflow:vault:stage     --organization="$org_id"     --target="$stage"     --confirm-writes-stopped >/dev/null

php bin/console grindflow:vault:verify-stage   --directory="$stage"   --expect="$manifest_sha" >/dev/null

cd "$ROOT"
GF_METADATA_SNAPSHOT_APPROVED=1   php scripts/mariadb-structure-snapshot.php > "$source_structure"

export MYSQL_PWD="$db_password"
docker run --rm --network host --env MYSQL_PWD "$MARIADB_IMAGE"   mariadb-dump     --protocol=TCP     --host="$db_host"     --port="$db_port"     --user="$db_user"     --single-transaction     --quick     --skip-lock-tables     --triggers     --hex-blob     --skip-comments "$db_name" > "$dump"
chmod 0600 "$dump"
[[ -s "$dump" ]] || fail "database dump is empty."

tables="$(docker run --rm --network host --env MYSQL_PWD "$MARIADB_IMAGE"   mariadb     --protocol=TCP     --host="$db_host"     --port="$db_port"     --user="$db_user"     --database="$db_name"     --batch --skip-column-names     --execute='SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;')"
{
  printf 'SET FOREIGN_KEY_CHECKS=0;\n'
  while IFS= read -r table; do
    [[ -z "$table" ]] && continue
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail "unexpected table name in disposable database."
    printf 'DROP TABLE IF EXISTS `%s`;\n' "$table"
  done <<< "$tables"
  printf 'SET FOREIGN_KEY_CHECKS=1;\n'
} | docker run --rm --interactive --network host --env MYSQL_PWD "$MARIADB_IMAGE"   mariadb     --protocol=TCP     --host="$db_host"     --port="$db_port"     --user="$db_user"     --database="$db_name" >/dev/null

remaining="$(docker run --rm --network host --env MYSQL_PWD "$MARIADB_IMAGE"   mariadb     --protocol=TCP     --host="$db_host"     --port="$db_port"     --user="$db_user"     --database="$db_name"     --batch --skip-column-names     --execute='SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();' | tr -d '\r\n')"
[[ "$remaining" == "0" ]] || fail "disposable database was not fully cleared before restore."

rm -rf "$source_vault"
docker run --rm --interactive --network host --env MYSQL_PWD "$MARIADB_IMAGE"   mariadb     --protocol=TCP     --host="$db_host"     --port="$db_port"     --user="$db_user"     --database="$db_name" < "$dump"
install -d -m 0700 "$restored_vault"
find "$stage/blobs" -maxdepth 1 -type f -name '*.blob' -exec install -m 0600 {} "$restored_vault/" \;

cd "$SYMFONY_DIR"
GRINDFLOW_VAULT_ROOT="$restored_vault"   php bin/console grindflow:vault:verify-restore     --organization="$org_id"     --directory="$stage"     --expect="$manifest_sha"     --confirm-writes-stopped >/dev/null

php bin/console doctrine:schema:validate --skip-sync >/dev/null

cd "$ROOT"
GF_METADATA_SNAPSHOT_APPROVED=1   php scripts/mariadb-structure-snapshot.php > "$restored_structure"

python3 scripts/symfony-schema-structure.py --json > "$base/expected-structure.json"
python3 - "$base/expected-structure.json" "$restored_structure" > "$envelope" <<'PY'
import json
import sys
from pathlib import Path

source = json.loads(Path(sys.argv[1]).read_text())
snapshot = json.loads(Path(sys.argv[2]).read_text())
print(json.dumps({"source": source, "snapshot": snapshot}, sort_keys=True))
PY
python3 scripts/data-schema-structure-parity.py < "$envelope" >/dev/null

if ! cmp -s "$source_structure" "$restored_structure"; then
  fail "restored structural metadata differs from the pre-backup snapshot."
fi

cd "$SYMFONY_DIR"
php bin/console doctrine:query:sql "DELETE FROM gf_vault_assets WHERE id = '$asset_id'" >/dev/null
php bin/console doctrine:query:sql "DELETE FROM gf_identity_organizations WHERE id = '$org_id'" >/dev/null
php bin/console doctrine:query:sql "DELETE FROM gf_identity_users WHERE id = '$user_id'" >/dev/null

printf 'GF-ARCH-002 disposable MariaDB + Vault restore drill: OK\n'
