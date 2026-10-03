#!/usr/bin/env bash
set -euo pipefail

: "${HOSTINGER_SSH_HOST:?HOSTINGER_SSH_HOST is required}"
: "${HOSTINGER_SSH_USER:?HOSTINGER_SSH_USER is required}"
: "${HOSTINGER_SSH_PORT:?HOSTINGER_SSH_PORT is required}"
: "${HOSTINGER_RELEASE_ROOT:?HOSTINGER_RELEASE_ROOT is required}"
: "${DEPLOY_SSH_KEY:?DEPLOY_SSH_KEY is required}"
: "${HOSTINGER_KNOWN_HOSTS:?HOSTINGER_KNOWN_HOSTS is required}"
: "${EXPECTED_PENDING:?EXPECTED_PENDING is required}"

[[ "$HOSTINGER_SSH_HOST" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || { echo "invalid SSH host" >&2; exit 2; }
[[ "$HOSTINGER_SSH_USER" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || { echo "invalid SSH user" >&2; exit 2; }
[[ "$HOSTINGER_SSH_PORT" =~ ^[0-9]{1,5}$ ]] || { echo "invalid SSH port" >&2; exit 2; }
(( HOSTINGER_SSH_PORT >= 1 && HOSTINGER_SSH_PORT <= 65535 )) || { echo "invalid SSH port" >&2; exit 2; }
[[ "$EXPECTED_PENDING" =~ ^[1-9][0-9]*$ ]] || { echo "invalid expected pending count" >&2; exit 2; }
[[ "$HOSTINGER_RELEASE_ROOT" == /* && "$HOSTINGER_RELEASE_ROOT" != "/" ]] || { echo "invalid release root" >&2; exit 2; }
[[ "$HOSTINGER_RELEASE_ROOT" != *".."* && "$HOSTINGER_RELEASE_ROOT" != *\\* ]] || { echo "invalid release root" >&2; exit 2; }

workdir="$(mktemp -d)"
keyfile="$workdir/key"
knownfile="$workdir/known_hosts"
cleanup() { rm -rf "$workdir"; }
trap cleanup EXIT

printf '%s\n' "$DEPLOY_SSH_KEY" > "$keyfile"
printf '%s\n' "$HOSTINGER_KNOWN_HOSTS" > "$knownfile"
chmod 600 "$keyfile" "$knownfile"

ssh_args=(
  ssh
  -i "$keyfile"
  -p "$HOSTINGER_SSH_PORT"
  -o BatchMode=yes
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=yes
  -o "UserKnownHostsFile=$knownfile"
  "$HOSTINGER_SSH_USER@$HOSTINGER_SSH_HOST"
)

"${ssh_args[@]}" bash -s -- "$HOSTINGER_RELEASE_ROOT" "$EXPECTED_PENDING" <<'REMOTE'
set -euo pipefail

root="$1"
expected_pending="$2"
current="$root/current"

[[ -L "$current" ]] || { echo "production current release is unavailable" >&2; exit 20; }
release="$(readlink "$current")"
case "$release" in
  "$root"/releases/*) ;;
  *) echo "production current release is outside the release root" >&2; exit 21 ;;
esac
[[ -d "$release" && ! -L "$release" ]] || { echo "production release is unsafe" >&2; exit 22; }
cd "$release"
[[ -f artisan && -f bootstrap/app.php ]] || { echo "Laravel runtime is unavailable" >&2; exit 23; }

snapshot="$(
  php -r '
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

credentials="$(mktemp)"
database_file="$(mktemp)"
tmp_archive=""
cleanup_remote() {
  rm -f "$credentials" "$database_file"
  [[ -z "$tmp_archive" ]] || rm -f "$tmp_archive"
}
trap cleanup_remote EXIT
chmod 600 "$credentials" "$database_file"

php -r '
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
      preg_match("/\\A[1-9][0-9]{0,4}\\z/", $port) !== 1 ||
      (int) $port > 65535 ||
      preg_match("/\\A[A-Za-z0-9_][A-Za-z0-9_.\$-]{0,63}\\z/", $database) !== 1
  ) {
      fwrite(STDERR, "invalid database configuration\n");
      exit(2);
  }
  $quote = static function (string $value): string {
      return """.str_replace(
          ["\\", """, "\n", "\r"],
          ["\\\\", "\\"", "", ""],
          $value,
      ).""";
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

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive_name="pre-migration-${timestamp}-${fingerprint:0:12}.sql.gz"
archive_relative="operations/database-backups/$archive_name"
archive_path="$backup_dir/$archive_name"
tmp_archive="$backup_dir/.tmp-$archive_name-$$"
umask 077

"$dump_bin"   --defaults-extra-file="$credentials"   --single-transaction   --quick   --skip-lock-tables   --hex-blob   --default-character-set=utf8mb4   -- "$database" | gzip -9 > "$tmp_archive"

[[ -s "$tmp_archive" ]] || { echo "database backup is empty" >&2; exit 30; }
gzip -t "$tmp_archive"
chmod 600 "$tmp_archive"
mv -f "$tmp_archive" "$archive_path"
tmp_archive=""
[[ -f "$archive_path" && ! -L "$archive_path" ]] || { echo "database backup archive is unsafe" >&2; exit 31; }

receipt="$(php artisan operations:record-db-backup "$archive_relative" "$fingerprint" --no-interaction 2>/dev/null)"
[[ "$receipt" =~ ^[0-9a-f]{64}$ ]] || { echo "verified backup receipt was not created" >&2; exit 32; }

printf 'BACKUP_RECEIPT=%s\n' "$receipt"
printf 'MIGRATION_FINGERPRINT=%s\n' "$fingerprint"
printf 'BACKUP_ARCHIVE=%s\n' "$archive_relative"
REMOTE
