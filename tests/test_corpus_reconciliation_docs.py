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

        ranges = [
            (int(start), int(end))
            for start, end in re.findall(
                r"^- \*\*#(\d+)–#(\d+) · .+\*\*$",
                source,
                flags=re.MULTILINE,
            )
        ]
        self.assertEqual(332, len(ranges))
        self.assertTrue(all(end - start == 4 for start, end in ranges))

        covered = [
            number
            for start, end in ranges
            for number in range(start, end + 1)
        ]
        self.assertEqual(list(range(8341, 10001)), covered)
        self.assertEqual(len(covered), len(set(covered)))

    def test_macro_classification_covers_all_domains(self):
        consolidated = read_doc("CORPUS-REQUIREMENTS-8341-10000-CONSOLIDATED.md")
        classification = read_doc("CORPUS-REQUIREMENTS-8341-10000-CLASSIFICATION.md")
        domains = [
            (int(start), int(end))
            for start, end in re.findall(
                r"^## .+ · #(\d+)–#(\d+)$",
                consolidated,
                flags=re.MULTILINE,
            )
        ]
        rows = re.findall(
            r"^\| #(\d+)–#(\d+) \| .+? \| \*\*(.+?)\*\* \|",
            classification,
            flags=re.MULTILINE,
        )

        self.assertEqual(17, len(domains))
        self.assertEqual(17, len(rows))
        self.assertEqual(
            domains,
            [(int(start), int(end)) for start, end, _ in rows],
        )

        allowed = {
            "YA CUBIERTO",
            "BRECHA MVP",
            "POST-MVP",
            "NECESITA DECISIÓN",
        }
        seen = {category for _, _, category in rows}
        self.assertEqual(allowed, seen)

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

        self.assertIn(
            "No releer ni regenerar #1–#8040 requisito por requisito",
            baseline,
        )
        self.assertIn("#8041–#8340", historical)
        self.assertIn("300", historical)
        self.assertIn("No aparece una nueva brecha P0/P1", historical)

    def test_historical_recovery_preserves_collisions_and_gaps(self):
        index = read_doc("CORPUS-HISTORICAL-BLOCK-INDEX.md")
        pass2 = read_doc("CORPUS-HISTORICAL-RECOVERY-PASS-2.md")
        pass3 = read_doc("CORPUS-HISTORICAL-RECOVERY-PASS-3.md")
        current = read_doc("CORPUS-CURRENT-SESSION-66-1000-INDEX.md")

        self.assertIn("Dos bloques con el mismo rango pero tema distinto", index)
        self.assertIn("`NO RECUPERADO` significa", index)
        self.assertIn(
            "Zonas todavía no recuperadas con evidencia suficiente",
            index,
        )
        self.assertIn(
            "Recuperar únicamente resúmenes/índices de las zonas marcadas",
            index,
        )
        self.assertIn("Estado de huecos después de la pasada 2", pass2)
        self.assertIn("siguen parcialmente sin título original", pass3)
        self.assertIn("mantiene huecos", pass3)
        self.assertIn("Los huecos remanentes ya están acotados", pass3)
        self.assertIn("#66–#1000", current)
        self.assertIn("Se conserva como fuente independiente", current)
        self.assertIn("conservando procedencia de ambas series", current)

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
