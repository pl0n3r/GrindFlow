#!/usr/bin/env bash
# GrindFlow #801: operator-owned, non-mutating Hostinger candidate inspection.
set -euo pipefail

usage() {
  printf 'Usage: %s --read-only [--root CHECKOUT]\n' "$0" >&2
  exit 2
}

[[ "${1:-}" == '--read-only' ]] || usage
shift
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
if (( $# > 0 )); then
  [[ $# -eq 2 && "$1" == '--root' ]] || usage
  root="$2"
fi

# Fail closed. Printing an inspection result never authorizes deployment.
failed=0
report() {
  local label="$1" ok="$2"
  if [[ "$ok" == true ]]; then
    printf 'CHECK %s=PASS\n' "$label"
  else
    printf 'CHECK %s=NO_GO\n' "$label"
    failed=1
  fi
}

if [[ ! -d "$root" ]]; then
  report checkout false
  printf 'READ_ONLY_RESULT=NO_GO\n'
  exit 1
fi
report checkout true

if command -v php >/dev/null 2>&1; then
  php_id="$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || true)"
  if [[ "$php_id" =~ ^[0-9]+$ ]] && (( php_id >= 80300 )); then
    report php_cli_minimum_8_3 true
  else
    report php_cli_minimum_8_3 false
  fi
  extensions="$(php -m 2>/dev/null || true)"
  for extension in ctype iconv pdo_mysql openssl mbstring; do
    if grep -Fxiq -- "$extension" <<< "$extensions"; then
      report "extension_${extension}" true
    else
      report "extension_${extension}" false
    fi
  done
else
  report php_cli_minimum_8_3 false
  for extension in ctype iconv pdo_mysql openssl mbstring; do
    report "extension_${extension}" false
  done
fi

if command -v composer >/dev/null 2>&1; then
  report composer_cli true
else
  report composer_cli false
fi

# Check the source manifest, not the Hostinger environment or private secrets.
for path in symfony/composer.json symfony/public/index.php symfony/config/bootstrap.php; do
  if [[ -r "$root/$path" && -f "$root/$path" ]]; then
    report "file_${path//\//_}" true
  else
    report "file_${path//\//_}" false
  fi
done
if [[ -r "$root/symfony/vendor/autoload.php" && -f "$root/symfony/vendor/autoload.php" ]]; then
  report symfony_vendor_autoload true
else
  report symfony_vendor_autoload false
fi

# Permissions observed by CLI do not prove the PHP-FPM/Apache identity.
if [[ -d "$root/symfony/var" && -w "$root/symfony/var" ]]; then
  report symfony_var_cli_writable true
else
  report symfony_var_cli_writable false
fi

printf 'WEB_PHP_FPM=UNVERIFIED\n'
printf 'DOCUMENT_ROOT=UNVERIFIED\n'
printf 'BACKUP_RESTORED=UNVERIFIED\n'
printf 'SMOKE_TENANT=UNVERIFIED\n'
if (( failed )); then
  printf 'READ_ONLY_RESULT=NO_GO\n'
  exit 1
fi
printf 'READ_ONLY_RESULT=CANDIDATE_ONLY\n'
