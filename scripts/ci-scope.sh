#!/usr/bin/env bash

# Deterministic GrindFlow changed-file classifier.
# Source this file and call:
#   grindflow_ci_classify_files "$changed_file_list" "$event_name"
# Or pipe file paths into:
#   bash scripts/ci-scope.sh pull_request

grindflow_ci_scope_reset() {
  GRINDFLOW_SCOPE_FULL=false
  GRINDFLOW_SCOPE_RUN_PHP_QUALITY=false
  GRINDFLOW_SCOPE_RUN_TESTS=false
  GRINDFLOW_SCOPE_RUN_DATABASE=false
  GRINDFLOW_SCOPE_RUN_BROWSER=false
  GRINDFLOW_SCOPE_RUN_LEGACY=false
  GRINDFLOW_SCOPE_AREAS=""
}

grindflow_ci_scope_add_area() {
  local area="$1"
  case ",${GRINDFLOW_SCOPE_AREAS}," in
    *",${area},"*) ;;
    *) GRINDFLOW_SCOPE_AREAS="${GRINDFLOW_SCOPE_AREAS:+${GRINDFLOW_SCOPE_AREAS}, }${area}" ;;
  esac
}

grindflow_ci_scope_all() {
  GRINDFLOW_SCOPE_FULL=true
  GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
  GRINDFLOW_SCOPE_RUN_TESTS=true
  GRINDFLOW_SCOPE_RUN_DATABASE=true
  GRINDFLOW_SCOPE_RUN_BROWSER=true
  GRINDFLOW_SCOPE_RUN_LEGACY=true
}

grindflow_ci_classify_files() {
  local changed_file_list="${1:-}"
  local event_name="${2:-pull_request}"
  local file

  grindflow_ci_scope_reset

  if [[ "$event_name" == "workflow_dispatch" ]]; then
    grindflow_ci_scope_all
    grindflow_ci_scope_add_area "Manual full validation"
  fi

  while IFS= read -r file; do
    [[ -z "$file" ]] && continue

    case "$file" in
      .github/workflows/grindflow-ci.yml|scripts/ci-scope.sh|scripts/ci-scope-contract.sh|scripts/readme-dashboard.py)
        grindflow_ci_scope_add_area "CI/CD core"
        grindflow_ci_scope_all
        ;;
      .github/*|.coderabbit.yaml|.sonarcloud.properties|scripts/sonar-pr-comment.py)
        grindflow_ci_scope_add_area "CI/review policy"
        ;;
      app/Models/*|app/Jobs/*|app/Queue/*|app/Support/Tenancy/*|database/*)
        grindflow_ci_scope_add_area "Database/tenant runtime"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        GRINDFLOW_SCOPE_RUN_DATABASE=true
        ;;
      app/Http/*|app/Livewire/*|app/Providers/*|routes/web.php|resources/views/*|public/css/*|public/js/*)
        grindflow_ci_scope_add_area "Laravel web/UI"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        GRINDFLOW_SCOPE_RUN_BROWSER=true
        ;;
      app/*|bootstrap/*|routes/*)
        grindflow_ci_scope_add_area "Laravel application"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        ;;
      config/*)
        grindflow_ci_scope_add_area "Runtime config"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        GRINDFLOW_SCOPE_RUN_DATABASE=true
        GRINDFLOW_SCOPE_RUN_BROWSER=true
        ;;
      tests/Browser/*|scripts/browser-smoke.sh)
        grindflow_ci_scope_add_area "Browser tests"
        GRINDFLOW_SCOPE_RUN_BROWSER=true
        ;;
      tests/*)
        grindflow_ci_scope_add_area "Laravel tests"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        case "$file" in
          *Database*|*Tenant*|*MediaVault*|*Migration*|*Organization*|*Membership*|*Integration*)
            GRINDFLOW_SCOPE_RUN_DATABASE=true
            ;;
        esac
        ;;
      composer.json|composer.lock|phpstan.neon|phpstan.neon.dist|phpunit.xml|phpunit.xml.dist)
        grindflow_ci_scope_add_area "PHP tooling"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        GRINDFLOW_SCOPE_RUN_DATABASE=true
        GRINDFLOW_SCOPE_RUN_BROWSER=true
        ;;
      src/*|workers/*|supabase/*|package.json|package-lock.json|tsconfig*.json|eslint*|next.config*|postcss*|tailwind*|Dockerfile|workers/Dockerfile|scripts/*.mjs|scripts/*.js|scripts/*.ts)
        grindflow_ci_scope_add_area "Legacy Node"
        GRINDFLOW_SCOPE_RUN_LEGACY=true
        ;;
      README.md|AGENTS.md|docs/*|*.md)
        grindflow_ci_scope_add_area "Docs/operations"
        ;;
      scripts/*.sh|scripts/*.py)
        grindflow_ci_scope_add_area "Operations tooling"
        ;;
      *)
        grindflow_ci_scope_add_area "Other/conservative"
        GRINDFLOW_SCOPE_RUN_PHP_QUALITY=true
        GRINDFLOW_SCOPE_RUN_TESTS=true
        GRINDFLOW_SCOPE_RUN_DATABASE=true
        GRINDFLOW_SCOPE_RUN_BROWSER=true
        GRINDFLOW_SCOPE_RUN_LEGACY=true
        ;;
    esac
  done <<< "$changed_file_list"

  if [[ -z "$GRINDFLOW_SCOPE_AREAS" ]]; then
    GRINDFLOW_SCOPE_AREAS="None detected"
  fi
}

grindflow_ci_scope_print() {
  printf 'full=%s\n' "$GRINDFLOW_SCOPE_FULL"
  printf 'run_php_quality=%s\n' "$GRINDFLOW_SCOPE_RUN_PHP_QUALITY"
  printf 'run_tests=%s\n' "$GRINDFLOW_SCOPE_RUN_TESTS"
  printf 'run_database=%s\n' "$GRINDFLOW_SCOPE_RUN_DATABASE"
  printf 'run_browser=%s\n' "$GRINDFLOW_SCOPE_RUN_BROWSER"
  printf 'run_legacy=%s\n' "$GRINDFLOW_SCOPE_RUN_LEGACY"
  printf 'areas=%s\n' "$GRINDFLOW_SCOPE_AREAS"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  event_name="${1:-pull_request}"
  case "$event_name" in
    pull_request|push|workflow_dispatch) ;;
    *)
      printf 'Unsupported CI scope event: %s\n' "$event_name" >&2
      exit 2
      ;;
  esac

  grindflow_ci_classify_files "$(cat)" "$event_name"
  grindflow_ci_scope_print
fi
