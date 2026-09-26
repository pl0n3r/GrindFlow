#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/grindflow-ci.yml"
PHPSTAN="$ROOT/phpstan.neon.dist"
RECTOR_MANIFEST="$ROOT/tools/rector/composer.json"
RECTOR_LOCK="$ROOT/tools/rector/composer.lock"
RECTOR_CONFIG="$ROOT/rector.php"

grep -Fq 'vendor/larastan/larastan/extension.neon' "$PHPSTAN"
grep -Eq '^[[:space:]]*level:[[:space:]]*6[[:space:]]*$' "$PHPSTAN"
grep -Eq '^[[:space:]]*reportUnmatchedIgnoredErrors:[[:space:]]*true[[:space:]]*$' "$PHPSTAN"

if [[ -e "$ROOT/phpstan-baseline.neon" ]]; then
  [[ -s "$ROOT/phpstan-baseline.neon" ]]
  grep -Fq 'phpstan-baseline.neon' "$PHPSTAN"
  grep -Fq 'ignoreErrors:' "$ROOT/phpstan-baseline.neon"
fi

grep -Fq '"driftingly/rector-laravel": "2.6.2"' "$RECTOR_MANIFEST"
grep -Fq '"rector/rector": "2.6.7"' "$RECTOR_MANIFEST"
test -s "$RECTOR_LOCK"
grep -Fq '"name": "driftingly/rector-laravel"' "$RECTOR_LOCK"
grep -Fq '"version": "2.6.2"' "$RECTOR_LOCK"
grep -Fq '"name": "rector/rector"' "$RECTOR_LOCK"
grep -Fq '"version": "2.6.7"' "$RECTOR_LOCK"

grep -Fq -- '->withPhpSets()' "$RECTOR_CONFIG"
grep -Fq -- '->withDeadCodeLevel(0)' "$RECTOR_CONFIG"
grep -Fq -- '->withCodeQualityLevel(0)' "$RECTOR_CONFIG"
grep -Fq 'LaravelSetList::LARAVEL_CODE_QUALITY' "$RECTOR_CONFIG"

php_quality="$(awk '/^  php-quality:/{capture=1} /^  tests:/{capture=0} capture' "$WORKFLOW")"
grep -Fq 'vendor/bin/phpstan analyse --no-progress' <<<"$php_quality"
grep -Fq 'composer install --working-dir=tools/rector' <<<"$php_quality"
grep -Fq 'tools/rector/vendor/bin/rector process --dry-run --no-progress-bar --config=rector.php' <<<"$php_quality"
grep -Fq "hashFiles('composer.lock', 'tools/rector/composer.lock')" <<<"$php_quality"

validate="$(awk '/^  validate:/{capture=1} capture' "$WORKFLOW")"
grep -Fq 'php-quality' <<<"$validate"
grep -Fq 'require_optional php-quality "$RUN_PHP_QUALITY" "$PHP_QUALITY_RESULT"' <<<"$validate"

printf 'GrindFlow static analysis contract passed.\n'
