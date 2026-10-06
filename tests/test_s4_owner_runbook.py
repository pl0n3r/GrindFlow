#!/usr/bin/env python3
"""Contracts for the owner-only S4 configuration, schema and identity runbook."""
from __future__ import annotations

import json
import os
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIAGNOSTIC = ROOT / "scripts" / "s4-bridge-diagnostic.php"
RUNBOOK = ROOT / "docs" / "S4-OWNER-RUNBOOK.md"


class S4OwnerRunbookTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.diagnostic = DIAGNOSTIC.read_text(encoding="utf-8")
        cls.runbook = RUNBOOK.read_text(encoding="utf-8")

    def test_diagnostic_classifies_each_layer_with_allowlisted_codes_and_prints_no_secrets(self) -> None:
        syntax = subprocess.run(
            ["php", "-l", str(DIAGNOSTIC)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(syntax.returncode, 0, syntax.stderr)

        expected_codes = {
            "app_secret_missing",
            "app_secret_too_short",
            "database_url_missing",
            "database_url_invalid",
            "database_connection_failed",
            "schema_missing:gf_identity_users",
            "schema_missing:gf_identity_organizations",
            "schema_missing:gf_identity_memberships",
            "identity_missing",
            "ready",
        }
        constant = re.search(
            r"const S4_DIAGNOSTIC_CODES = \[(?P<body>.*?)\];",
            self.diagnostic,
            re.DOTALL,
        )
        self.assertIsNotNone(constant)
        declared_codes = set(re.findall(r"'([^']+)'", constant.group("body")))
        self.assertEqual(declared_codes, expected_codes)

        sentinel_secret = "S4_TEST_SECRET_DO_NOT_PRINT_" + ("x" * 40)
        sentinel_password = "S4_TEST_DB_PASSWORD_DO_NOT_PRINT"
        sentinel_database_url = (
            "mysql://offline_user:"
            f"{sentinel_password}@offline.invalid/offline_db"
        )

        # The test-only seam runs before Symfony/vendor loading and feeds
        # synthetic probe states through the same classification functions used
        # by the real CLI. It never opens a socket or touches a database.
        for expected_code in sorted(expected_codes):
            with self.subTest(expected_code=expected_code):
                env = os.environ.copy()
                env.update(
                    {
                        "APP_ENV": "test",
                        "GRINDFLOW_S4_DIAGNOSTIC_TEST_SCENARIO": expected_code,
                        "GRINDFLOW_S4_DIAGNOSTIC_TEST_SECRET": sentinel_secret,
                        "GRINDFLOW_S4_DIAGNOSTIC_TEST_DATABASE_URL": sentinel_database_url,
                    }
                )
                result = subprocess.run(
                    ["php", str(DIAGNOSTIC)],
                    cwd=ROOT,
                    env=env,
                    text=True,
                    capture_output=True,
                    check=False,
                )

                self.assertEqual(
                    result.returncode,
                    0 if expected_code == "ready" else 2,
                    result.stderr,
                )
                self.assertEqual(result.stderr, "")
                self.assertEqual(len(result.stdout.splitlines()), 1)

                payload = json.loads(result.stdout)
                self.assertEqual(set(payload), {"status", "code"})
                self.assertEqual(payload["code"], expected_code)
                self.assertEqual(
                    payload["status"],
                    "ready" if expected_code == "ready" else "blocked",
                )

                combined_output = result.stdout + result.stderr
                for sensitive_value in (
                    sentinel_secret,
                    sentinel_password,
                    sentinel_database_url,
                ):
                    self.assertNotIn(sensitive_value, combined_output)

        for snippet in (
            "diagnosticConfigurationCode",
            "diagnosticDatabaseCode",
            "GRINDFLOW_S4_DIAGNOSTIC_TEST_SCENARIO",
            "diagnosticEnv('APP_ENV') !== 'test'",
            "$connection->connect()",
            "$schema->tablesExist([$table])",
            "INNER JOIN gf_identity_memberships",
            "'status' => $code === 'ready' ? 'ready' : 'blocked'",
            "'code' => $code",
        ):
            self.assertIn(snippet, self.diagnostic)

        for forbidden in (
            "getMessage(",
            "var_dump(",
            "print_r(",
            "password_hash",
            "SELECT *",
            " INSERT ",
            " UPDATE ",
            " DELETE ",
            " DROP ",
            " ALTER ",
        ):
            self.assertNotIn(forbidden, self.diagnostic.upper() if forbidden.startswith(" ") else self.diagnostic)

    def test_runbook_covers_config_schema_and_identity_with_backup_and_rollback(self) -> None:
        required = (
            "symfony/.env.local",
            "APP_SECRET",
            "DATABASE_URL",
            "base MariaDB aislada",
            "scripts/s4-bridge-diagnostic.php",
            "mysqldump",
            "gzip -t",
            "doctrine:migrations:migrate --dry-run --no-interaction",
            "doctrine:migrations:migrate --no-interaction",
            "grindflow:s4:provision-smoke-identity",
            "PRODUCTION_E2E_PASSWORD",
            "ready_for_web_probe",
            "production-smoke.yml",
            "Vuelta atrás",
            "gunzip -c \"$BACKUP\" | mysql",
            "symfony/var/vault",
            "no garantiza un rollback exacto del esquema",
            "base limpia/fresca",
            "no elimina por sí solo tablas creadas después del backup",
        )
        for snippet in required:
            self.assertIn(snippet, self.runbook)

        self.assertLess(
            self.runbook.index("mysqldump"),
            self.runbook.index("doctrine:migrations:migrate --no-interaction"),
        )
        self.assertLess(
            self.runbook.index("doctrine:migrations:migrate --no-interaction"),
            self.runbook.index("grindflow:s4:provision-smoke-identity"),
        )

        # Both backup and restore are pipelines. Each must fail closed if either
        # side fails and must clean the temporary credentials on every exit.
        self.assertGreaterEqual(self.runbook.count("set -euo pipefail"), 2)
        self.assertGreaterEqual(
            self.runbook.count("trap 'rm -f \"$DB_CNF\"' EXIT"),
            2,
        )
        self.assertIn(
            'mysqldump --defaults-extra-file="$DB_CNF" --single-transaction --routines --triggers "$DB_NAME" | gzip > "$BACKUP"',
            self.runbook,
        )
        self.assertIn(
            'gunzip -c "$BACKUP" | mysql --defaults-extra-file="$DB_CNF" "$DB_NAME"',
            self.runbook,
        )

    def test_runbook_contains_no_secret_values_and_uses_php85_paths(self) -> None:
        self.assertGreaterEqual(
            self.runbook.count("/opt/alt/php85/usr/bin/php"),
            2,
        )
        self.assertIn("git check-ignore -q symfony/.env.local", self.runbook)
        self.assertIn("read -rsp 'DB password", self.runbook)
        self.assertIn("read -rsp 'Password sintético S4:", self.runbook)
        self.assertIn("openssl rand -hex 32", self.runbook)
        self.assertIn("No uses `set -x`", self.runbook)

        secret_patterns = (
            r"APP_SECRET=[0-9a-fA-F]{32,}",
            r"PRODUCTION_E2E_PASSWORD=[^$<{\s][^\s]*",
            r"GRINDFLOW_S4_SMOKE_PASSWORD=[^$<{\s][^\s]*",
            r"mysql://(?!%s:%s@)[^\s:$<{]+:[^\s@$<{]+@",
        )
        for pattern in secret_patterns:
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, self.runbook))


if __name__ == "__main__":
    unittest.main()
