#!/usr/bin/env python3
"""Contracts for the owner-only S4 configuration, schema and identity runbook."""
from __future__ import annotations

import json
import os
import re
import subprocess
import tempfile
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

        # Execute the real CLI against a disposable bootstrap. The empty
        # autoloader keeps the contract offline: the connection scenario is
        # classified by the real Throwable path without touching a database.
        with tempfile.TemporaryDirectory() as temp_dir:
            sandbox = Path(temp_dir)
            sandbox_diagnostic = sandbox / "scripts" / DIAGNOSTIC.name
            sandbox_diagnostic.parent.mkdir(parents=True)
            sandbox_diagnostic.write_text(self.diagnostic, encoding="utf-8")

            bootstrap = sandbox / "symfony" / "config" / "bootstrap.php"
            bootstrap.parent.mkdir(parents=True)
            bootstrap.write_text("<?php\n", encoding="utf-8")
            autoload = sandbox / "symfony" / "vendor" / "autoload.php"
            autoload.parent.mkdir(parents=True)
            autoload.write_text("<?php\n", encoding="utf-8")

            base_env = os.environ.copy()
            base_env.pop("APP_SECRET", None)
            base_env.pop("DATABASE_URL", None)
            valid_secret = "s4-contract-secret-0123456789abcdef"
            scenarios = (
                ("secret missing", {}, "app_secret_missing"),
                (
                    "secret too short",
                    {"APP_SECRET": "too-short"},
                    "app_secret_too_short",
                ),
                (
                    "database missing",
                    {"APP_SECRET": valid_secret},
                    "database_url_missing",
                ),
                (
                    "database url invalid",
                    {
                        "APP_SECRET": valid_secret,
                        "DATABASE_URL": "not-a-database-url",
                    },
                    "database_url_invalid",
                ),
                (
                    "database connection failure",
                    {
                        "APP_SECRET": valid_secret,
                        "DATABASE_URL": "mysql://s4-user:s4-password@127.0.0.1:1/grindflow",
                    },
                    "database_connection_failed",
                ),
            )

            for label, overrides, expected_code in scenarios:
                with self.subTest(label=label):
                    env = base_env.copy()
                    env.update(overrides)
                    result = subprocess.run(
                        ["php", str(sandbox_diagnostic)],
                        cwd=sandbox,
                        env=env,
                        text=True,
                        capture_output=True,
                        check=False,
                    )
                    self.assertEqual(result.returncode, 2, result.stderr)
                    payload = json.loads(result.stdout)
                    self.assertEqual(payload, {"status": "blocked", "code": expected_code})
                    self.assertIn(payload["code"], expected_codes)

                    combined_output = result.stdout + result.stderr
                    for sensitive_value in overrides.values():
                        self.assertNotIn(sensitive_value, combined_output)

        for snippet in (
            "strlen($appSecret) < 32",
            "databaseUrlIsValid($databaseUrl)",
            "$connection->connect()",
            "foreach (S4_DIAGNOSTIC_TABLES as $table)",
            "diagnosticFinish('schema_missing:'.$table)",
            "INNER JOIN gf_identity_memberships",
            "diagnosticFinish('identity_missing')",
            "diagnosticFinish('ready')",
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
            r"mysql://[^\s:$<{]+:[^\s@$<{]+@",
        )
        for pattern in secret_patterns:
            with self.subTest(pattern=pattern):
                self.assertIsNone(re.search(pattern, self.runbook))


if __name__ == "__main__":
    unittest.main()
