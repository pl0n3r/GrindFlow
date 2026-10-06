#!/usr/bin/env bash
set -euo pipefail

: "${HOSTINGER_SSH_HOST:?HOSTINGER_SSH_HOST is required}"
: "${HOSTINGER_SSH_USER:?HOSTINGER_SSH_USER is required}"
: "${HOSTINGER_SSH_PORT:?HOSTINGER_SSH_PORT is required}"
: "${HOSTINGER_RELEASE_ROOT:?HOSTINGER_RELEASE_ROOT is required}"
: "${SSH_KEY_PATH:?SSH_KEY_PATH is required}"
: "${KNOWN_HOSTS_PATH:?KNOWN_HOSTS_PATH is required}"
: "${EXPECTED_PENDING:?EXPECTED_PENDING is required}"
: "${EXPECTED_SHA:?EXPECTED_SHA is required}"
: "${PRODUCTION_RECOVERY_KEY_B64:?PRODUCTION_RECOVERY_KEY_B64 is required}"

[[ "$HOSTINGER_SSH_HOST" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || { echo "invalid SSH host" >&2; exit 2; }
[[ "$HOSTINGER_SSH_USER" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || { echo "invalid SSH user" >&2; exit 2; }
[[ "$HOSTINGER_SSH_PORT" =~ ^[0-9]{1,5}$ ]] || { echo "invalid SSH port" >&2; exit 2; }
(( HOSTINGER_SSH_PORT >= 1 && HOSTINGER_SSH_PORT <= 65535 )) || { echo "invalid SSH port" >&2; exit 2; }
[[ "$EXPECTED_PENDING" =~ ^[1-9][0-9]*$ ]] || { echo "invalid expected pending count" >&2; exit 2; }
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "invalid expected SHA" >&2; exit 2; }
[[ "$HOSTINGER_RELEASE_ROOT" =~ ^(/[A-Za-z0-9._-]+)+$ ]] || { echo "invalid release root" >&2; exit 2; }
[[ "$HOSTINGER_RELEASE_ROOT" != *".."* ]] || { echo "invalid release root" >&2; exit 2; }

[[ -f "$SSH_KEY_PATH" && ! -L "$SSH_KEY_PATH" && -s "$SSH_KEY_PATH" ]] || { echo "SSH private key is unavailable" >&2; exit 2; }
[[ -f "$KNOWN_HOSTS_PATH" && ! -L "$KNOWN_HOSTS_PATH" && -s "$KNOWN_HOSTS_PATH" ]] || { echo "strict known_hosts evidence is unavailable" >&2; exit 2; }

ssh_args=(
  ssh
  -i "$SSH_KEY_PATH"
  -p "$HOSTINGER_SSH_PORT"
  -o BatchMode=yes
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=yes
  -o "UserKnownHostsFile=$KNOWN_HOSTS_PATH"
  "$HOSTINGER_SSH_USER@$HOSTINGER_SSH_HOST"
)

remote_key_file=""
cleanup_local() {
  if [[ -n "$remote_key_file" ]]; then
    "${ssh_args[@]}" sh -c 'rm -f -- "$1"' grindflow-recovery-key "$remote_key_file" >/dev/null 2>&1 || true
  fi
}
trap cleanup_local EXIT

remote_key_file="$(
  printf '%s' "$PRODUCTION_RECOVERY_KEY_B64" |
    "${ssh_args[@]}" sh -c '
      set -eu
      umask 077
      key_file="$(mktemp /tmp/grindflow-recovery-key.XXXXXXXX)"
      cat > "$key_file"
      chmod 600 "$key_file"
      printf "%s\n" "$key_file"
    ' grindflow-recovery-key
)"
[[ "$remote_key_file" =~ ^/tmp/grindflow-recovery-key\.[A-Za-z0-9]+$ ]] || {
  echo "remote recovery key staging failed" >&2
  exit 2
}

"${ssh_args[@]}" bash -s -- "$HOSTINGER_RELEASE_ROOT" "$EXPECTED_PENDING" "$EXPECTED_SHA" "$remote_key_file" <<'REMOTE'
set -euo pipefail

root="$1"
expected_pending="$2"
expected_sha="$3"
key_file="$4"
php_bin="/opt/alt/php85/usr/bin/php"
current="$root/current"

[[ -x "$php_bin" ]] || { echo "production PHP 8.5 CLI is unavailable" >&2; exit 19; }
[[ -L "$current" ]] || { echo "production current release is unavailable" >&2; exit 20; }
release="$(readlink -f "$current")"
[[ "$release" == "$root/releases/$expected_sha" ]] || { echo "production checkout does not match expected SHA" >&2; exit 21; }
[[ -d "$release" && ! -L "$release" ]] || { echo "production release is unsafe" >&2; exit 22; }
[[ -f "$release/.release-sha" && ! -L "$release/.release-sha" ]] || { echo "production release identity is unavailable" >&2; exit 22; }
[[ "$(cat "$release/.release-sha")" == "$expected_sha" ]] || { echo "production release identity does not match" >&2; exit 22; }

key_meta="$(stat -c '%a:%F' "$key_file" 2>/dev/null || true)"
[[ "$key_meta" == "600:regular file" ]] || { rm -f -- "$key_file"; echo "recovery key staging is unsafe" >&2; exit 22; }
recovery_key="$(cat "$key_file")"
rm -f -- "$key_file"
key_file=""

cd "$release"
[[ -f artisan && -f bootstrap/app.php && -f vendor/autoload.php ]] || { echo "Laravel runtime is unavailable" >&2; exit 23; }
[[ -f symfony/bin/console && -f scripts/recovery-secretstream.php && -f scripts/recovery-bundle.php ]] || {
  echo "recovery runtime is unavailable" >&2
  exit 23
}

GF_RECOVERY_KEY_B64="$recovery_key" "$php_bin" -r '
  $raw = getenv("GF_RECOVERY_KEY_B64");
  $key = is_string($raw) ? base64_decode($raw, true) : false;
  $functions = [
      "sodium_crypto_secretstream_xchacha20poly1305_init_push",
      "sodium_crypto_secretstream_xchacha20poly1305_push",
      "sodium_crypto_secretstream_xchacha20poly1305_init_pull",
      "sodium_crypto_secretstream_xchacha20poly1305_pull",
  ];
  if (!extension_loaded("sodium")
      || !is_string($key)
      || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
      exit(2);
  }
  foreach ($functions as $function) {
      if (!function_exists($function)) {
          exit(2);
      }
  }
' || { echo "recovery encryption readiness is unavailable" >&2; exit 23; }

snapshot="$(
  "$php_bin" -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $snapshot = $app->make(App\Support\Operations\MigrationReadiness::class)->snapshot();
    echo count($snapshot["names"])."|".$snapshot["fingerprint"];
  '
)"
IFS='|' read -r pending_count fingerprint <<< "$snapshot"
[[ "$pending_count" =~ ^[0-9]+$ ]] || { echo "pending migration count is invalid" >&2; exit 24; }
[[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]] || { echo "migration fingerprint is invalid" >&2; exit 25; }
[[ "$pending_count" == "$expected_pending" ]] || { echo "pending migration count changed" >&2; exit 26; }

dump_bin="$(command -v mariadb-dump || command -v mysqldump || true)"
[[ -n "$dump_bin" ]] || { echo "database dump utility is unavailable" >&2; exit 27; }
command -v gzip >/dev/null || { echo "gzip is unavailable" >&2; exit 28; }
command -v tar >/dev/null || { echo "tar is unavailable" >&2; exit 28; }

credentials="$(mktemp)"
database_file="$(mktemp)"
tmp_archive=""
promoted_archive=""
recovery_workspace=""
recovery_tar=""
recovery_cipher=""
recovery_committed=0
cleanup_remote() {
  rm -f -- "$credentials" "$database_file"
  [[ -z "$tmp_archive" ]] || rm -f -- "$tmp_archive"
  [[ -z "$promoted_archive" ]] || rm -f -- "$promoted_archive"
  [[ -z "$recovery_tar" ]] || rm -f -- "$recovery_tar"
  [[ -z "$recovery_workspace" ]] || rm -rf -- "$recovery_workspace"
  if [[ "$recovery_committed" != "1" && -n "$recovery_cipher" ]]; then
    rm -f -- "$recovery_cipher"
  fi
  unset recovery_key || true
}
trap cleanup_remote EXIT
chmod 600 "$credentials" "$database_file"

"$php_bin" -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $default = (string) config("database.default");
  $db = config("database.connections.".$default);
  if (!is_array($db) || !in_array($db["driver"] ?? null, ["mysql", "mariadb"], true)) {
      fwrite(STDERR, "unsupported database driver\n");
      exit(2);
  }
  $host = (string) ($db["host"] ?? "");
  $port = (string) ($db["port"] ?? "3306");
  $user = (string) ($db["username"] ?? "");
  $password = (string) ($db["password"] ?? "");
  $database = (string) ($db["database"] ?? "");
  if (
      $host === "" || $user === "" ||
      preg_match("/\A[1-9][0-9]{0,4}\z/", $port) !== 1 ||
      (int) $port > 65535 ||
      preg_match("/\A[A-Za-z0-9_][A-Za-z0-9_.$-]{0,63}\z/", $database) !== 1
  ) {
      fwrite(STDERR, "invalid database configuration\n");
      exit(2);
  }
  $quote = static function (string $value): string {
      $escaped = strtr($value, [
          "\\" => "\\\\",
          chr(8) => "\\b",
          "\t" => "\\t",
          "\n" => "\\n",
          "\r" => "\\r",
          "\"" => "\\\"",
      ]);
      return "\"".$escaped."\"";
  };
  $content = "[client]\n"
      ."host=".$quote($host)."\n"
      ."port=".$port."\n"
      ."user=".$quote($user)."\n"
      ."password=".$quote($password)."\n";
  if (file_put_contents($argv[1], $content) === false || chmod($argv[1], 0600) === false) {
      exit(3);
  }
  if (file_put_contents($argv[2], $database) === false || chmod($argv[2], 0600) === false) {
      exit(3);
  }
' "$credentials" "$database_file"

database="$(cat "$database_file")"
backup_dir="storage/app/private/operations/database-backups"
mkdir -p "$backup_dir"
[[ -d "$backup_dir" && ! -L "$backup_dir" ]] || { echo "backup directory is unsafe" >&2; exit 29; }
chmod 700 "$backup_dir"

timestamp_file="$(date -u +%Y%m%dT%H%M%SZ)"
timestamp_iso="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
umask 077
tmp_archive="$(mktemp "$backup_dir/.tmp-pre-migration-${timestamp_file}-${fingerprint:0:12}-XXXXXXXX.sql.gz")"
tmp_name="${tmp_archive##*/}"
archive_name="${tmp_name#.tmp-}"
archive_relative="operations/database-backups/$archive_name"
archive_path="$backup_dir/$archive_name"

"$dump_bin" \
  --defaults-extra-file="$credentials" \
  --single-transaction \
  --quick \
  --skip-lock-tables \
  --hex-blob \
  --default-character-set=utf8mb4 \
  -- "$database" | gzip -9 > "$tmp_archive"

[[ -s "$tmp_archive" ]] || { echo "database backup is empty" >&2; exit 30; }
gzip -t "$tmp_archive"
chmod 600 "$tmp_archive"
ln -- "$tmp_archive" "$archive_path" || { echo "database backup archive collision" >&2; exit 31; }
promoted_archive="$archive_path"
[[ -f "$archive_path" && ! -L "$archive_path" ]] || { echo "database backup archive is unsafe" >&2; exit 31; }

db_receipt="$("$php_bin" artisan operations:record-db-backup "$archive_relative" "$fingerprint" --no-interaction 2>/dev/null)"
[[ "$db_receipt" =~ ^[0-9a-f]{64}$ ]] || { echo "verified backup receipt was not created" >&2; exit 32; }

rm -f -- "$tmp_archive"
tmp_archive=""
promoted_archive=""

recovery_workspace="$(mktemp -d /tmp/grindflow-recovery-workspace.XXXXXXXX)"
chmod 700 "$recovery_workspace"
bundle_dir="$recovery_workspace/bundle"
vault_dir="$bundle_dir/vault"
mkdir -p "$bundle_dir" "$vault_dir"
chmod 700 "$bundle_dir" "$vault_dir"
install -m 0600 "$archive_path" "$bundle_dir/database.sql.gz"

organizations="$(
  "$php_bin" -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $rows = $app->make("db")->connection()->select(
        "SELECT DISTINCT organization_id FROM gf_vault_assets ORDER BY organization_id"
    );
    foreach ($rows as $row) {
        $id = (string) ($row->organization_id ?? "");
        if (preg_match("/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D", $id) !== 1) {
            exit(3);
        }
        echo strtolower($id), "\n";
    }
  '
)"

if [[ -n "$organizations" ]]; then
  while IFS= read -r organization_id; do
    [[ -n "$organization_id" ]] || continue
    stage="$vault_dir/$organization_id"
    stage_json="$(
      "$php_bin" symfony/bin/console grindflow:vault:stage \
        --organization="$organization_id" \
        --target="$stage" \
        --confirm-writes-stopped \
        --no-interaction
    )" || { echo "Vault stage failed" >&2; exit 33; }
    manifest_sha="$(
      printf '%s' "$stage_json" |
        "$php_bin" -r '
          $payload = json_decode(stream_get_contents(STDIN), true);
          $sha = is_array($payload) ? ($payload["manifest_sha256"] ?? null) : null;
          if (($payload["status"] ?? null) !== "staged"
              || !is_string($sha)
              || preg_match("/\A[0-9a-f]{64}\z/D", $sha) !== 1) {
              exit(2);
          }
          echo $sha;
        '
    )" || { echo "Vault stage evidence is invalid" >&2; exit 33; }

    "$php_bin" symfony/bin/console grindflow:vault:verify-stage \
      --directory="$stage" \
      --expect="$manifest_sha" \
      --no-interaction >/dev/null || { echo "Vault stage verification failed" >&2; exit 33; }
  done <<< "$organizations"
fi

"$php_bin" scripts/recovery-bundle.php vault-index "$vault_dir" > "$bundle_dir/vault-index.json"
chmod 600 "$bundle_dir/vault-index.json"
vault_counts="$(
  "$php_bin" -r '
    $payload = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)
        || !is_string($payload["vault_index_sha256"] ?? null)
        || preg_match("/\A[0-9a-f]{64}\z/D", $payload["vault_index_sha256"]) !== 1
        || !is_array($payload["organizations"] ?? null)) {
        exit(2);
    }
    $assets = 0;
    foreach ($payload["organizations"] as $entry) {
        if (!is_array($entry) || !is_int($entry["assets"] ?? null) || $entry["assets"] < 0) {
            exit(2);
        }
        $assets += $entry["assets"];
    }
    echo $payload["vault_index_sha256"], "|", count($payload["organizations"]), "|", $assets;
  ' "$bundle_dir/vault-index.json"
)" || { echo "Vault recovery index is invalid" >&2; exit 34; }
IFS='|' read -r vault_index_sha organization_count asset_count <<< "$vault_counts"

release_version="$(
  "$php_bin" -r '
    $version = require "config/version.php";
    $value = is_array($version) ? ($version["number"] ?? null) : null;
    if (!is_string($value) || preg_match("/\A0\.[0-9]+\.[0-9]+\z/D", $value) !== 1) {
        exit(2);
    }
    echo $value;
  '
)" || { echo "release version is invalid" >&2; exit 35; }

"$php_bin" scripts/recovery-bundle.php metadata \
  "$fingerprint" \
  "$vault_index_sha" \
  "$release_version" \
  "$expected_sha" \
  "$timestamp_iso" \
  "$organization_count" \
  "$asset_count" > "$bundle_dir/metadata.json"
chmod 600 "$bundle_dir/metadata.json"
"$php_bin" scripts/recovery-bundle.php verify "$bundle_dir" >/dev/null

recovery_tar="$recovery_workspace/recovery.tar"
tar -C "$bundle_dir" -cf "$recovery_tar" metadata.json vault-index.json database.sql.gz vault
chmod 600 "$recovery_tar"

recovery_dir="storage/app/private/operations/recovery-backups"
mkdir -p "$recovery_dir"
[[ -d "$recovery_dir" && ! -L "$recovery_dir" ]] || { echo "recovery directory is unsafe" >&2; exit 36; }
chmod 700 "$recovery_dir"
cipher_relative="operations/recovery-backups/recovery-${timestamp_file}-${expected_sha:0:12}.gfrec"
recovery_cipher="storage/app/private/$cipher_relative"
[[ ! -e "$recovery_cipher" && ! -L "$recovery_cipher" ]] || { echo "recovery ciphertext collision" >&2; exit 36; }

GF_RECOVERY_KEY_B64="$recovery_key" "$php_bin" scripts/recovery-secretstream.php \
  encrypt "$recovery_tar" "$recovery_cipher" >/dev/null
unset recovery_key
chmod 600 "$recovery_cipher"
cipher_sha="$(sha256sum "$recovery_cipher" | awk '{print $1}')"
[[ "$cipher_sha" =~ ^[0-9a-f]{64}$ ]] || { echo "recovery ciphertext hash is invalid" >&2; exit 37; }

recovery_receipt="$(
  "$php_bin" -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo $app->make(App\Support\Operations\VerifiedRecoveryEvidence::class)->record(
        $argv[1],
        $argv[2],
        $argv[3],
        $argv[4],
        $argv[5],
        (int) $argv[6],
        (int) $argv[7],
    );
  ' "$cipher_relative" "$fingerprint" "$vault_index_sha" "$release_version" "$expected_sha" "$organization_count" "$asset_count"
)" || { echo "verified recovery receipt was not created" >&2; exit 38; }
[[ "$recovery_receipt" =~ ^[0-9a-f]{64}$ ]] || { echo "verified recovery receipt was not created" >&2; exit 38; }

rm -rf -- "$recovery_workspace"
recovery_workspace=""
recovery_tar=""
recovery_committed=1

printf 'MIGRATION_FINGERPRINT=%s\n' "$fingerprint"
printf 'BACKUP_ARCHIVE=%s\n' "$archive_relative"
printf 'RECOVERY_CIPHERTEXT_SHA256=%s\n' "$cipher_sha"
printf 'RECOVERY_VAULT_INDEX_SHA256=%s\n' "$vault_index_sha"
printf 'RECOVERY_RECEIPT=%s\n' "$recovery_receipt"
printf 'RECOVERY_ORGANIZATIONS=%s\n' "$organization_count"
printf 'RECOVERY_ASSETS=%s\n' "$asset_count"
REMOTE

remote_key_file=""
