#!/usr/bin/env python3
"""Regression coverage for the deterministic S4 migration fingerprint."""

from __future__ import annotations

import hashlib
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "scripts/s4-migration-fingerprint.php"
RUNBOOK = ROOT / "docs/S4-OWNER-RUNBOOK.md"
SHA = "0" * 40


class S4MigrationFingerprintTests(unittest.TestCase):
    def fingerprint(self, migration_dir: Path, applied_versions: Path, checkout_sha: str = SHA) -> str:
        result = subprocess.run(
            ["php", str(HELPER), checkout_sha, str(migration_dir)],
            cwd=ROOT,
            check=True,
            text=True,
            input=applied_versions.read_text(encoding="utf-8"),
            capture_output=True,
        )
        self.assertEqual(result.stderr, "")
        value = result.stdout.strip()
        self.assertRegex(value, r"^[0-9a-f]{64}$")
        return value

    def fixture(self, root: Path) -> tuple[Path, Path]:
        migrations = root / "private" / "absolute" / "migrations"
        migrations.mkdir(parents=True)
        (migrations / "Version20261001000000.php").write_text(
            "<?php // migration A\n", encoding="utf-8"
        )
        (migrations / "Version20261002000000.php").write_text(
            "<?php // migration B\n", encoding="utf-8"
        )
        versions = root / "applied.txt"
        versions.write_text(
            "GrindFlow\\Migrations\\Version20261001000000\n", encoding="utf-8"
        )
        return migrations, versions

    def test_identical_inputs_are_deterministic(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            migrations, versions = self.fixture(Path(tmp))
            self.assertEqual(
                self.fingerprint(migrations, versions),
                self.fingerprint(migrations, versions),
            )

    def test_migration_content_change_changes_fingerprint(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            migrations, versions = self.fixture(Path(tmp))
            before = self.fingerprint(migrations, versions)
            (migrations / "Version20261002000000.php").write_text(
                "<?php // migration B changed\n", encoding="utf-8"
            )
            self.assertNotEqual(before, self.fingerprint(migrations, versions))

    def test_applied_version_set_change_changes_fingerprint_and_input_order_does_not(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            migrations, versions = self.fixture(Path(tmp))
            before = self.fingerprint(migrations, versions)
            versions.write_text(
                "GrindFlow\\Migrations\\Version20261001000000\n"
                "GrindFlow\\Migrations\\Version20261002000000\n",
                encoding="utf-8",
            )
            with_two_versions = self.fingerprint(migrations, versions)
            self.assertNotEqual(before, with_two_versions)

            versions.write_text(
                "GrindFlow\\Migrations\\Version20261002000000\n"
                "GrindFlow\\Migrations\\Version20261001000000\n",
                encoding="utf-8",
            )
            self.assertEqual(
                with_two_versions,
                self.fingerprint(migrations, versions),
            )

    def test_output_is_single_secret_free_sha256(self) -> None:
        with tempfile.TemporaryDirectory(prefix="dsn-secret-password-") as tmp:
            migrations, versions = self.fixture(Path(tmp))
            value = self.fingerprint(migrations, versions)
            self.assertRegex(value, r"^[0-9a-f]{64}$")
            self.assertNotIn(tmp, value)
            source = HELPER.read_text(encoding="utf-8")
            self.assertNotIn("DATABASE_URL", source)
            self.assertNotIn("getenv(", source)
            self.assertNotIn("dry-run", source)
            self.assertIn("stream_get_contents(STDIN)", source)
            self.assertNotIn("applied-versions-file", source)

    def test_invalid_utf8_fails_closed_without_leaking_paths(self) -> None:
        with tempfile.TemporaryDirectory(prefix="private-fingerprint-path-") as tmp:
            migrations, versions = self.fixture(Path(tmp))
            versions.write_bytes(
                rb"GrindFlow\Migrations\Version20261001000000"
                + bytes([0xFF])
                + b"\n"
            )
            result = subprocess.run(
                ["php", str(HELPER), SHA, str(migrations)],
                cwd=ROOT,
                check=False,
                input=versions.read_bytes(),
                capture_output=True,
            )
            self.assertEqual(2, result.returncode)
            self.assertEqual(b"", result.stdout)
            self.assertEqual(b"cannot encode fingerprint payload\n", result.stderr)
            self.assertNotIn(tmp.encode(), result.stderr)

    def test_runbook_uses_canonical_fingerprint_before_and_after_backup(self) -> None:
        text = RUNBOOK.read_text(encoding="utf-8")
        lock = text.index("flock -n 9")
        first = text.index("s4-migration-fingerprint.php")
        backup = text.index("--single-transaction --quick --skip-lock-tables")
        receipt = text.index("operations:record-db-backup", backup)
        second = text.index("s4-migration-fingerprint.php", first + 1)
        verify = text.index("assertLatestValidForFingerprint")
        migrate = text.index("doctrine:migrations:migrate --no-interaction", verify)
        self.assertLess(lock, first)
        self.assertLess(first, backup)
        self.assertLess(backup, receipt)
        self.assertLess(receipt, second)
        self.assertLess(second, verify)
        self.assertLess(verify, migrate)
        self.assertIn(
            "SELECT version FROM doctrine_migration_versions ORDER BY version", text
        )
        self.assertNotIn("MIGRATION_PLAN=", text)
        self.assertNotIn("CURRENT_PLAN=", text)

    def test_nondeterministic_dry_run_stdout_no_longer_affects_fingerprint(self) -> None:
        old_a = "Migrating up to X in 12ms"
        old_b = "Migrating up to X in 37ms"
        self.assertNotEqual(
            hashlib.sha256(old_a.encode()).hexdigest(),
            hashlib.sha256(old_b.encode()).hexdigest(),
        )
        with tempfile.TemporaryDirectory() as tmp:
            migrations, versions = self.fixture(Path(tmp))
            self.assertEqual(
                self.fingerprint(migrations, versions),
                self.fingerprint(migrations, versions),
            )


if __name__ == "__main__":
    unittest.main()
