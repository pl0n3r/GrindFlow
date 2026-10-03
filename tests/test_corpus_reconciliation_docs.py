"""Executable contracts for the historical requirements reconciliation docs."""

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"


def read_doc(name: str) -> str:
    return (DOCS / name).read_text(encoding="utf-8")


class CorpusReconciliationDocsTests(unittest.TestCase):
    def test_consolidation_preserves_declared_scope(self):
        source = read_doc("CORPUS-REQUIREMENTS-8341-10000-CONSOLIDATED.md")

        self.assertIn("#8341–#10000", source)
        self.assertIn("Requisitos originales cubiertos: **1660**", source)
        self.assertIn("Capacidades compactadas: **332**", source)
        self.assertIn("Dominios: **17**", source)

        capacity_lines = re.findall(r"^- \*\*#\d+–#\d+ · .+\*\*$", source, flags=re.MULTILINE)
        self.assertEqual(332, len(capacity_lines))

    def test_macro_classification_covers_all_domains(self):
        source = read_doc("CORPUS-REQUIREMENTS-8341-10000-CLASSIFICATION.md")
        rows = [
            line
            for line in source.splitlines()
            if line.startswith("| #") and " | **" in line
        ]

        self.assertEqual(17, len(rows))
        allowed = {
            "YA CUBIERTO",
            "BRECHA MVP",
            "POST-MVP",
            "NECESITA DECISIÓN",
        }

        seen = set()
        for row in rows:
            match = re.search(r"\| \*\*(.+?)\*\* \|", row)
            self.assertIsNotNone(match, row)
            classification = match.group(1)
            self.assertIn(classification, allowed, row)
            seen.add(classification)

        self.assertIn("YA CUBIERTO", seen)
        self.assertIn("BRECHA MVP", seen)
        self.assertIn("POST-MVP", seen)
        self.assertIn("NECESITA DECISIÓN", seen)

    def test_reconciliation_method_preserves_provenance(self):
        method = read_doc("CORPUS-RECONCILIATION-METHOD.md")
        baseline = read_doc("CORPUS-HISTORICAL-BASELINE-1-8040.md")
        historical = read_doc("CORPUS-REQUIREMENTS-8041-8340-CLASSIFICATION.md")

        for rule in (
            "fuente/sesión + rango original + título/tema",
            "procedencia nunca se elimina",
            "No volver a generar requisitos para rellenar rangos numéricos",
            "no se relee ninguno de los tramos ya congelados",
        ):
            self.assertIn(rule, method)

        self.assertIn("No releer ni regenerar #1–#8040 requisito por requisito", baseline)
        self.assertIn("#8041–#8340", historical)
        self.assertIn("300", historical)
        self.assertIn("No aparece una nueva brecha P0/P1", historical)

    def test_reused_ranges_remain_separate_sources(self):
        historical = read_doc("CORPUS-HISTORICAL-RECOVERY-PASS-3.md")
        current = read_doc("CORPUS-CURRENT-SESSION-66-1000-INDEX.md")

        # Historical numbering was reused across sessions: keep provenance instead
        # of pretending the numeric range is a globally unique identifier.
        self.assertGreaterEqual(historical.count("`#1741–#1760`"), 5)
        self.assertIn("`#7021–#7040` · Risk Appetite Management", historical)
        self.assertIn("Numeración deliberadamente NO se mezcla", current)
        self.assertIn("#901–#1000 · SaaS y plataforma", current)

    def test_release_identity_matches_across_manifests(self):
        config = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        package_lock = json.loads((ROOT / "package-lock.json").read_text(encoding="utf-8"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", config)
        self.assertIsNotNone(match)
        php_version = match.group(1)

        self.assertRegex(php_version, r"^\d+\.\d+\.\d+$")
        self.assertEqual(php_version, package["version"])
        self.assertEqual(php_version, package_lock["version"])
        self.assertEqual(php_version, package_lock["packages"][""]["version"])


if __name__ == "__main__":
    unittest.main()
