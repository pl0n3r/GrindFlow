#!/usr/bin/env bash
# Deliberately read-only inspection: no console/kernel boot, cache rebuild or DB access.
set -euo pipefail
umask 077

if (( $# != 1 )) || [[ "$1" != '--read-only' ]]; then
  printf 'S4_DIAG_MODE=UNKNOWN\n'
  exit 2
fi
expected="${EXPECTED_SHA:-}"
if [[ ! "$expected" =~ ^[0-9a-f]{40}$ ]]; then
  printf 'S4_DIAG_SHA=UNKNOWN\n'
  exit 2
fi
root="$(pwd -P)"
checkout="$(git -C "$root" rev-parse HEAD 2>/dev/null || true)"
if [[ "$checkout" != "$expected" ]]; then
  printf 'S4_DIAG_CHECKOUT=DRIFT\n'
  exit 1
fi
printf 'S4_DIAG_CHECKOUT=MATCH\n'

# Only print fixed categorical states for public, allowlisted tracked files.
check_tracked() {
  local label="$1" relative="$2" tracked actual
  if [[ ! -f "$relative" || ! -r "$relative" || -L "$relative" ]]; then
    printf 'S4_DIAG_%s=MISSING\n' "$label"
    return
  fi
  tracked="$(git -C "$root" rev-parse "HEAD:$relative" 2>/dev/null || true)"
  actual="$(git -C "$root" hash-object -- "$relative" 2>/dev/null || true)"
  if [[ ! "$tracked" =~ ^[0-9a-f]{40}$ || ! "$actual" =~ ^[0-9a-f]{40}$ ]]; then
    printf 'S4_DIAG_%s=UNKNOWN\n' "$label"
  elif [[ "$tracked" == "$actual" ]]; then
    printf 'S4_DIAG_%s=MATCH\n' "$label"
  else
    printf 'S4_DIAG_%s=DRIFT\n' "$label"
  fi
}
check_tracked ROUTE_IMPORT symfony/config/routes.yaml
check_tracked CONTROLLER_COLLECTIONS symfony/src/Http/Controller/LibraryCollectionsController.php
check_tracked CONTROLLER_PLANS symfony/src/Http/Controller/PlanLimitsAdminController.php
check_tracked CONTROLLER_AUTOMATION symfony/src/Http/Controller/PublishingAutomationController.php
check_tracked CONTROLLER_PRIVACY symfony/src/Http/Controller/PrivacyRightsController.php
check_tracked CONTROLLER_DSR symfony/src/Http/Controller/DataSubjectRequestsController.php
check_tracked CONTROLLER_REPORTS symfony/src/Http/Reports/CrossModuleReportController.php
check_tracked COMPOSER_LOCK symfony/composer.lock

if [[ -f symfony/vendor/autoload.php && -r symfony/vendor/autoload.php && ! -L symfony/vendor/autoload.php ]]; then
  printf 'S4_DIAG_AUTOLOAD=MATCH\n'
else
  printf 'S4_DIAG_AUTOLOAD=MISSING\n'
fi
if [[ -f .release-sha && -r .release-sha && ! -L .release-sha ]]; then
  release="$(head -n 1 .release-sha 2>/dev/null || true)"
  if [[ "$release" == "$expected" ]]; then
    printf 'S4_DIAG_RELEASE_MARKER=MATCH\n'
  else
    printf 'S4_DIAG_RELEASE_MARKER=DRIFT\n'
  fi
else
  printf 'S4_DIAG_RELEASE_MARKER=MISSING\n'
fi

cache='symfony/var/cache/prod'
cache_route_labels=(COLLECTIONS PLANS AUTOMATION PRIVACY DSR REPORTS)

cache_routes_unknown() {
  local label
  for label in "${cache_route_labels[@]}"; do
    printf 'S4_DIAG_CACHE_ROUTE_%s=UNKNOWN\n' "$label"
  done
}

cache_inventory_fallback() {
  printf 'S4_DIAG_CACHE_ROUTER_FILES=%s\n' "$1"
  cache_routes_unknown
}

# Capture enumeration and inspection atomically: do not print partial batches.
scan_cache_routes() (
  set -euo pipefail
  local router_file entry label route state grep_rc
  local -a router_files=()
  # Older Bash without lastpipe fails closed rather than losing the array.
  shopt -s lastpipe || return 1
  find "$cache" -type f \( -iname '*route*.php' -o -iname '*matcher*.php' \) -print0 2>/dev/null |
    while IFS= read -r -d '' router_file || [[ -n "${router_file:-}" ]]; do
      router_files+=("$router_file")
    done || return 1

  if [[ -z "${router_files[0]+present}" ]]; then
    cache_inventory_fallback MISSING
    exit 0
  fi

  for router_file in "${router_files[@]}"; do
    [[ "$router_file" == "$cache/"* && "$router_file" != *"/../"* &&
       -f "$router_file" && -r "$router_file" && ! -L "$router_file" ]] || return 1
  done
  printf 'S4_DIAG_CACHE_ROUTER_FILES=MATCH\n'
  for entry in \
    'COLLECTIONS|/api/admin/library/collections' \
    'PLANS|/api/admin/plans' \
    'AUTOMATION|/api/admin/rules/automation' \
    'PRIVACY|/privacidad-y-mis-derechos' \
    'DSR|/api/admin/privacy/data-subject-requests/csrf' \
    'REPORTS|/api/admin/reports/cross-module'; do
    label="${entry%%|*}"
    route="${entry#*|}"
    state=UNKNOWN
    if [[ -n "${router_files[0]+present}" ]]; then
      if LC_ALL=C grep -Fq -- "$route" "${router_files[@]}" 2>/dev/null; then
        state=MATCH
      else
        grep_rc=$?
        (( grep_rc == 1 )) || return 1
      fi
    fi
    printf 'S4_DIAG_CACHE_ROUTE_%s=%s\n' "$label" "$state"
  done
)

# Reject unexpected stdout even if a command returns zero.
valid_cache_routes() {
  local output="$1" label line index=0
  local -a lines=()
  mapfile -t lines <<< "$output"
  (( ${#lines[@]} == ${#cache_route_labels[@]} + 1 )) || return 1
  [[ "${lines[0]}" =~ ^S4_DIAG_CACHE_ROUTER_FILES=(MATCH|MISSING|UNKNOWN)$ ]] || return 1
  for label in "${cache_route_labels[@]}"; do
    index=$((index + 1))
    line="${lines[index]}"
    [[ "$line" == "S4_DIAG_CACHE_ROUTE_${label}=MATCH" ||
       "$line" == "S4_DIAG_CACHE_ROUTE_${label}=UNKNOWN" ]] || return 1
  done
}

if [[ -d "$cache" && ! -L "$cache" ]]; then
  printf 'S4_DIAG_CACHE=MATCH\n'
  if cache_routes="$(scan_cache_routes 2>/dev/null)" &&
     valid_cache_routes "$cache_routes"; then
    printf '%s\n' "$cache_routes"
  else
    cache_inventory_fallback UNKNOWN
  fi
else
  printf 'S4_DIAG_CACHE=MISSING\n'
  cache_inventory_fallback MISSING
fi
printf 'S4_DIAG_READ_ONLY=MATCH\n'
