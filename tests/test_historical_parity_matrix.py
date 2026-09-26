#!/usr/bin/env python3
"""Contrato documental de la matriz histórica de paridad."""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs" / "HISTORICAL-PARITY-MATRIX.md"


class HistoricalParityMatrixTests(unittest.TestCase):
    def setUp(self) -> None:
        self.text = MATRIX.read_text(encoding="utf-8")

    def test_matrix_keeps_stable_historical_ids(self) -> None:
        ids = re.findall(r"^\| (HIST-\d{2}) \|", self.text, flags=re.MULTILINE)
        self.assertEqual(ids, [f"HIST-{index:02d}" for index in range(1, 13)])

    def test_matrix_distinguishes_implementation_from_production(self) -> None:
        self.assertIn("no implica cutover ni producción", self.text)
        self.assertIn("CI, deploy y producción se demuestran por separado", self.text)
        self.assertIn("traffic.ready=false", self.text)
        self.assertIn("publishes=false", self.text)

    def test_matrix_does_not_restore_legacy_stack_as_target(self) -> None:
        self.assertIn("Stack Next.js/Supabase/R2/Docker", self.text)
        self.assertIn("Fue sustituido por la transición Symfony/MariaDB", self.text)


if __name__ == "__main__":
    unittest.main()
