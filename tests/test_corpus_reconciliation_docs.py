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

        capacity_ranges = [
            (int(start), int(end))
            for start, end in re.findall(
                r"^- \*\*#(\d+)–#(\d+) · .+\*\*$",
                source,
                flags=re.MULTILINE,
            )
        ]
        self.assertEqual(332, len(capacity_ranges))
        self.assertEqual((8341, 8345), capacity_ranges[0])
        self.assertEqual((9996, 10000), capacity_ranges[-1])
        self.assertEqual(len(capacity_ranges), len(set(capacity_ranges)))

        covered = []
        previous_end = 8340
        for start, end in capacity_ranges:
            self.assertEqual(4, end - start)
            self.assertEqual(previous_end + 1, start)
            covered.extend(range(start, end + 1))
            previous_end = end

        self.assertEqual(list(range(8341, 10001)), covered)

    def test_macro_classification_covers_all_domains(self):
        source = read_doc("CORPUS-REQUIREMENTS-8341-10000-CLASSIFICATION.md")
        rows = [
            line
            for line in source.splitlines()
            if line.startswith("| #") and " | **" in line
        ]

        self.assertEqual(17, len(rows))
        domain_ranges = []
        allowed = {
            "YA CUBIERTO",
            "BRECHA MVP",
            "POST-MVP",
            "NECESITA DECISIÓN",
        }

        seen = set()
        for row in rows:
            range_match = re.match(r"\| #(\d+)–#(\d+) \|", row)
            self.assertIsNotNone(range_match, row)
            start, end = map(int, range_match.groups())
            domain_ranges.append((start, end))

            match = re.search(r"\| \*\*(.+?)\*\* \|", row)
            self.assertIsNotNone(match, row)
            classification = match.group(1)
            self.assertIn(classification, allowed, row)
            seen.add(classification)

        self.assertEqual(allowed, seen)
        self.assertEqual((8341, 8440), domain_ranges[0])
        self.assertEqual((9941, 10000), domain_ranges[-1])
        self.assertEqual(len(domain_ranges), len(set(domain_ranges)))

        domain_covered = []
        previous_end = 8340
        for start, end in domain_ranges:
            self.assertEqual(previous_end + 1, start)
            domain_covered.extend(range(start, end + 1))
            previous_end = end

        self.assertEqual(list(range(8341, 10001)), domain_covered)

        consolidated = read_doc("CORPUS-REQUIREMENTS-8341-10000-CONSOLIDATED.md")
        capacity_ranges = [
            (int(start), int(end))
            for start, end in re.findall(
                r"^- \*\*#(\d+)–#(\d+) · .+\*\*$",
                consolidated,
                flags=re.MULTILINE,
            )
        ]
        for capability_start, capability_end in capacity_ranges:
            owners = [
                (domain_start, domain_end)
                for domain_start, domain_end in domain_ranges
                if domain_start <= capability_start
                and capability_end <= domain_end
            ]
            self.assertEqual(
                1,
                len(owners),
                (capability_start, capability_end, owners),
            )

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
