#!/usr/bin/env python3
"""Regression contract for percent-encoded Symfony DATABASE_URL values."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCTRINE = ROOT / "symfony" / "config" / "packages" / "doctrine.yaml"
WORKFLOW = ROOT / ".github" / "workflows" / "grindflow-ci.yml"
RUNBOOK = ROOT / "docs" / "S4-OWNER-RUNBOOK.md"


class S4DatabaseUrlPercentEncodingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doctrine = DOCTRINE.read_text(encoding="utf-8")
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.runbook = RUNBOOK.read_text(encoding="utf-8")

    def test_doctrine_uses_plain_runtime_env_without_resolve_processor(self) -> None:
        self.assertIn("url: '%env(DATABASE_URL)%'", self.doctrine)
        self.assertNotIn("env(resolve:DATABASE_URL)", self.doctrine)

    def test_ci_exercises_multiple_percent_encoded_password_segments_without_persisting_secret(self) -> None:
        start = self.workflow.index("- name: Exercise percent-encoded DATABASE_URL")
        end = self.workflow.index(
            "- name: PHP syntax and isolated MariaDB connectivity",
            start,
        )
        step = self.workflow[start:end]

        for snippet in (
            "random_bytes(16)",
            '":@%/"',
            "rawurlencode($password)",
            "substr_count($encoded, '%') < 4",
            "::add-mask::",
            "ALTER USER 'grindflow'@'%' IDENTIFIED BY",
            "GITHUB_ENV",
            "'DATABASE_URL='.$url.PHP_EOL",
        ):
            self.assertIn(snippet, step)

        self.assertNotRegex(
            step,
            re.compile(r"DB_PASSWORD=['\"][^$][^'\"]+['\"]"),
        )
        self.assertNotIn("symfony/.env.local", step)

    def test_ci_runs_doctrine_status_and_dry_run_before_disposable_migration(self) -> None:
        start = self.workflow.index(
            "- name: PHP syntax and isolated MariaDB connectivity"
        )
        end = self.workflow.index(
            "- name: Verify metadata-only schema snapshot parity",
            start,
        )
        step = self.workflow[start:end]

        status = "php bin/console doctrine:migrations:status --no-interaction"
        dry_run = (
            "php bin/console doctrine:migrations:migrate "
            "--dry-run --no-interaction --no-ansi"
        )
        migrate = "php bin/console doctrine:migrations:migrate --no-interaction"

        for command in (status, dry_run, migrate):
            self.assertIn(command, step)
        self.assertLess(step.index(status), step.index(dry_run))
        self.assertLess(step.index(dry_run), step.index(migrate))

    def test_owner_runbook_supports_arbitrary_passwords_without_url_safe_requirement(self) -> None:
        self.assertIn("contraseñas arbitrarias válidas", self.runbook)
        self.assertIn("rawurlencode()", self.runbook)
        self.assertIn("env(resolve:DATABASE_URL)", self.runbook)
        self.assertIn("mitigación temporal", self.runbook)
        self.assertIn("no es requisito de contraseña ni solución permanente", self.runbook)


if __name__ == "__main__":
    unittest.main()
