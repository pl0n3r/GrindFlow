#!/usr/bin/env python3
"""Contrato documental de la matriz histórica de paridad."""

import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs" / "HISTORICAL-PARITY-MATRIX.md"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"
VERSION = ROOT / "config" / "version.php"
TEST_COMMAND = "python3 -m unittest tests/test_historical_parity_matrix.py"


class HistoricalParityMatrixTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.text = MATRIX.read_text(encoding="utf-8")
        cls.package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        cls.lock = json.loads(LOCK.read_text(encoding="utf-8"))
        cls.version_php = VERSION.read_text(encoding="utf-8")

    def test_matrix_keeps_stable_historical_ids(self) -> None:
        ids = re.findall(r"^\| (HIST-\d{2}) \|", self.text, flags=re.MULTILINE)
        self.assertEqual(ids, [f"HIST-{index:02d}" for index in range(1, 13)])

    def test_matrix_distinguishes_implementation_from_production(self) -> None:
        self.assertIn("CI, deploy y producción se demuestran por separado", self.text)
        self.assertIn("Production Smoke #165", self.text)
        self.assertIn("MIGRATIONS_PENDING=3", self.text)
        self.assertIn("no equivale a producción verde", self.text)

    def test_matrix_does_not_restore_legacy_stack_as_target(self) -> None:
        self.assertIn("Stack Next.js/Supabase/R2/Docker", self.text)
        self.assertIn("No vuelve a ser objetivo por defecto", self.text)
        self.assertIn("Symfony 7.4/MariaDB", self.text)

    def test_regression_is_wired_once(self) -> None:
        script = self.package["scripts"]["test"]
        self.assertEqual(script.count(TEST_COMMAND), 1)

    def test_release_identity_matches_manifests(self) -> None:
        match = re.search(r"'number'\s*=>\s*'([^']+)'", self.version_php)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(self.package["version"], release)
        self.assertEqual(self.lock["version"], release)
        self.assertEqual(self.lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
