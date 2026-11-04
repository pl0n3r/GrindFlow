#!/usr/bin/env bash
# Deliberately read-only inspection: no console/kernel boot, cache rebuild or DB access.
set -euo pipefail
umask 077

if (( $# != 1 )) || [[ "$1" != '--read-only' ]]; then
  printf 'S4_DIAG_MODE=INVALID\n'
  exit 2
fi
expected="${EXPECTED_SHA:-}"
if [[ ! "$expected" =~ ^[0-9a-f]{40}$ ]]; then
  printf 'S4_DIAG_SHA=INVALID\n'
  exit 2
fi
root="$(pwd -P)"
checkout="$(git -C "$root" rev-parse HEAD 2>/dev/null || true)"
if [[ "$checkout" != "$expected" ]]; then
  printf 'S4_DIAG_CHECKOUT=MISMATCH\n'
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
  printf 'S4_DIAG_AUTOLOAD=PRESENT\n'
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
if [[ -d "$cache" && ! -L "$cache" ]]; then
  printf 'S4_DIAG_CACHE=PRESENT\n'
  for entry in \
    'COLLECTIONS|/api/admin/library/collections' \
    'PLANS|/api/admin/plans' \
    'AUTOMATION|/api/admin/rules/automation' \
    'PRIVACY|/privacidad-y-mis-derechos' \
    'DSR|/api/admin/privacy/data-subject-requests/csrf'; do
    label="${entry%%|*}"
    route="${entry#*|}"
    if LC_ALL=C grep -r -F -q -- "$route" "$cache" 2>/dev/null; then
      printf 'S4_DIAG_CACHE_ROUTE_%s=PRESENT\n' "$label"
    else
      printf 'S4_DIAG_CACHE_ROUTE_%s=ABSENT\n' "$label"
    fi
  done
else
  printf 'S4_DIAG_CACHE=MISSING\n'
  for label in COLLECTIONS PLANS AUTOMATION PRIVACY DSR; do
    printf 'S4_DIAG_CACHE_ROUTE_%s=UNKNOWN\n' "$label"
  done
fi
printf 'S4_DIAG_RESULT=READ_ONLY_INSPECTION\n'
