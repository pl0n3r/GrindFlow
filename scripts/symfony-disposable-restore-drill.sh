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

db_parts_raw="$(
  php -r '
    $parts = parse_url((string) getenv("DATABASE_URL"));
    if (!is_array($parts)) {
        exit(2);
    }
    $scheme = strtolower((string) ($parts["scheme"] ?? ""));
    if (!in_array($scheme, ["mysql", "mariadb"], true)) {
        exit(2);
    }

    $host = rawurldecode((string) ($parts["host"] ?? ""));
    $database = rawurldecode(ltrim((string) ($parts["path"] ?? ""), "/"));
    $user = rawurldecode((string) ($parts["user"] ?? ""));
    $password = rawurldecode((string) ($parts["pass"] ?? ""));
    foreach ([$host, $database, $user, $password] as $value) {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            exit(2);
        }
    }

    echo $host, "\n";
    echo (int) ($parts["port"] ?? 3306), "\n";
    echo $database, "\n";
    echo $user, "\n";
    echo $password, "\n";
  '
)" || fail "DATABASE_URL could not be parsed."
mapfile -t db_parts <<< "$db_parts_raw"

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
bundle_source="$base/recovery-source"
stage="$bundle_source/vault/018f0000-0000-7000-8000-000000000002"
dump="$base/database.sql"
bundle_tar="$base/recovery.tar"
encrypted_bundle="$base/recovery.gfrec"
decrypted_tar="$base/recovery-decrypted.tar"
extracted_bundle="$base/recovery-extracted"
source_structure="$base/source-structure.json"
restored_structure="$base/restored-structure.json"
envelope="$base/structure-envelope.json"

cleanup() {
  rm -rf "$base"
}
trap cleanup EXIT

install -d -m 0700 "$source_vault"
install -d -m 0700 "$bundle_source" "$bundle_source/vault"

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

gzip -9 -c "$dump" > "$bundle_source/database.sql.gz"
chmod 0600 "$bundle_source/database.sql.gz"

php scripts/recovery-bundle.php vault-index "$bundle_source/vault" > "$bundle_source/vault-index.json"
chmod 0600 "$bundle_source/vault-index.json"
vault_index_sha="$(
  php -r '
    $value = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    $sha = is_array($value) ? ($value["vault_index_sha256"] ?? null) : null;
    if (!is_string($sha) || preg_match("/\A[0-9a-f]{64}\z/D", $sha) !== 1) {
        exit(2);
    }
    echo $sha;
  ' "$bundle_source/vault-index.json"
)" || fail "Vault recovery index is invalid."

db_fingerprint="$(sha256sum "$source_structure" | awk '{print $1}')"
release_version="$(php -r '$v=require "config/version.php"; echo $v["number"] ?? "";')"
release_sha="$(git rev-parse HEAD)"
created_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
php scripts/recovery-bundle.php metadata \
  "$db_fingerprint" \
  "$vault_index_sha" \
  "$release_version" \
  "$release_sha" \
  "$created_iso" \
  "1" \
  "1" > "$bundle_source/metadata.json"
chmod 0600 "$bundle_source/metadata.json"
php scripts/recovery-bundle.php verify "$bundle_source" >/dev/null

tar -C "$bundle_source" -cf "$bundle_tar" metadata.json vault-index.json database.sql.gz vault
chmod 0600 "$bundle_tar"

export GF_RECOVERY_KEY_B64
GF_RECOVERY_KEY_B64="$(php -r 'echo base64_encode(random_bytes(32));')"
php scripts/recovery-secretstream.php encrypt "$bundle_tar" "$encrypted_bundle" >/dev/null
chmod 0600 "$encrypted_bundle"

tampered="$base/recovery-tampered.gfrec"
cp "$encrypted_bundle" "$tampered"
php -r '
  $path=$argv[1];
  $h=fopen($path,"r+b");
  fseek($h,-8,SEEK_END);
  $b=fread($h,1);
  fseek($h,-1,SEEK_CUR);
  fwrite($h, chr(ord($b) ^ 1));
  fclose($h);
' "$tampered"
if php scripts/recovery-secretstream.php decrypt "$tampered" "$base/tampered.tar" >/dev/null 2>&1; then
  fail "tampered recovery ciphertext was accepted."
fi
[[ ! -e "$base/tampered.tar" ]] || fail "tampered decrypt retained plaintext."

truncated="$base/recovery-truncated.gfrec"
size="$(wc -c < "$encrypted_bundle" | tr -d ' ')"
(( size > 16 )) || fail "encrypted recovery fixture is unexpectedly small."
head -c "$((size - 12))" "$encrypted_bundle" > "$truncated"
chmod 0600 "$truncated"
if php scripts/recovery-secretstream.php decrypt "$truncated" "$base/truncated.tar" >/dev/null 2>&1; then
  fail "truncated recovery ciphertext was accepted."
fi
[[ ! -e "$base/truncated.tar" ]] || fail "truncated decrypt retained plaintext."

wrong_key="$(php -r 'echo base64_encode(random_bytes(32));')"
if GF_RECOVERY_KEY_B64="$wrong_key" php scripts/recovery-secretstream.php decrypt \
  "$encrypted_bundle" "$base/wrong-key.tar" >/dev/null 2>&1; then
  fail "recovery ciphertext accepted a wrong key."
fi
unset wrong_key
[[ ! -e "$base/wrong-key.tar" ]] || fail "wrong-key decrypt retained plaintext."

rm -rf "$bundle_source" "$source_vault"
rm -f "$bundle_tar" "$dump"

php scripts/recovery-secretstream.php decrypt "$encrypted_bundle" "$decrypted_tar" >/dev/null

python3 - "$decrypted_tar" <<'PY'
from pathlib import PurePosixPath
import sys
import tarfile

archive = sys.argv[1]
required = {"metadata.json", "vault-index.json", "database.sql.gz", "vault"}
seen = set()

try:
    with tarfile.open(archive, mode="r:") as bundle:
        members = bundle.getmembers()
        if not members:
            raise ValueError("empty recovery archive")

        for member in members:
            name = member.name
            path = PurePosixPath(name)
            parts = path.parts

            if (
                not name
                or name.startswith("/")
                or "\\" in name
                or not parts
                or any(part in {"", ".", ".."} for part in parts)
            ):
                raise ValueError("unsafe recovery archive path")

            top = parts[0]
            if top not in required:
                raise ValueError("unexpected recovery archive entry")

            if member.issym() or member.islnk() or member.isdev():
                raise ValueError("unsafe recovery archive entry type")
            if not (member.isfile() or member.isdir()):
                raise ValueError("unsupported recovery archive entry type")

            if top != "vault":
                if len(parts) != 1 or not member.isfile():
                    raise ValueError("invalid recovery archive root entry")
                seen.add(top)
                continue

            if len(parts) == 1:
                if not member.isdir():
                    raise ValueError("vault root must be a directory")
                seen.add("vault")

        if seen != required:
            raise ValueError("recovery archive is missing required entries")
except (OSError, tarfile.TarError, ValueError):
    raise SystemExit(3)
PY

install -d -m 0700 "$extracted_bundle"
tar --extract --file="$decrypted_tar" --directory="$extracted_bundle" \
  --no-same-owner --no-same-permissions --delay-directory-restore
php scripts/recovery-bundle.php verify "$extracted_bundle" >/dev/null
rm -f "$decrypted_tar"
unset GF_RECOVERY_KEY_B64

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

gzip -dc "$extracted_bundle/database.sql.gz" |
  docker run --rm --interactive --network host --env MYSQL_PWD "$MARIADB_IMAGE" \
    mariadb \
      --protocol=TCP \
      --host="$db_host" \
      --port="$db_port" \
      --user="$db_user" \
      --database="$db_name" >/dev/null
install -d -m 0700 "$restored_vault"
restored_stage="$extracted_bundle/vault/$org_id"
find "$restored_stage/blobs" -maxdepth 1 -type f -name '*.blob' -exec install -m 0600 {} "$restored_vault/" \;

cd "$SYMFONY_DIR"
GRINDFLOW_VAULT_ROOT="$restored_vault" \
  php bin/console grindflow:vault:verify-restore \
    --organization="$org_id" \
    --directory="$restored_stage" \
    --expect="$manifest_sha" \
    --confirm-writes-stopped >/dev/null

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

printf 'GF-ARCH-002 encrypted production-format MariaDB + Vault restore drill: OK\n'
