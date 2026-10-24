"""AC-01: inventario histórico verificable, sin dependencias de red ni escrituras."""
from __future__ import annotations

import unittest
from pathlib import Path

DOCUMENT = Path(__file__).resolve().parents[1] / "docs/reconciliacion-issues-20261024.md"
EXPECTED_OPEN = [2,146,250,294,342,343,344,345,346,347,348,350,351,352,353,355,356,358,405,406,457,574,575,580,582]


class ReconciliacionIssuesTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        doc = DOCUMENT.read_text(encoding="utf-8")
        self.assertIn("GF582-SNAPSHOT-20261024", doc)
        self.assertIn("main@d84a1ba0cef8ba189d38c11238c15942299c3663", doc)
        self.assertIn("25 evaluados; 0 cierres justificados", doc)
        rows = [line.split("|") for line in doc.splitlines() if line.startswith("| #")]
        self.assertEqual(len(rows), len(EXPECTED_OPEN))
        seen = []
        for row in rows:
            self.assertEqual(len(row), 6, row)
            number = int(row[1].strip().removeprefix("#"))
            decision, pending, evidence = (item.strip() for item in row[2:5])
            with self.subTest(issue=number):
                self.assertIn(decision, ("Mantener abierto", "Mantener abierto al corte"))
                self.assertTrue(pending, "Debe identificar el desbloqueador")
                self.assertIn(
                    f"https://github.com/pl0n3r/GrindFlow/issues/{number}",
                    evidence,
                )
                self.assertIn("https://github.com/pl0n3r/GrindFlow/", evidence)
                self.assertNotIn("sin evidencia", evidence.lower())
            seen.append(number)
        self.assertEqual(sorted(seen), EXPECTED_OPEN)
        self.assertEqual(len(seen), len(set(seen)))
        for closed in (471, 576, 577, 578, 579, 581):
            self.assertNotIn(closed, seen)
        self.assertIn("opción B", doc)
        self.assertIn("no se invaden", doc)
        self.assertIn("No sustituye las puertas", doc)


if __name__ == "__main__":
    unittest.main()
