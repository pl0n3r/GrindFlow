from __future__ import annotations

import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / "AGENTS.md"
DISTRIBUTION = ROOT / "docs/AGENT-DISTRIBUTION.md"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"

HEADINGS = (
    "### Distribution: historial paginado y conteo real",
    "### Regla de enlaces de campana en publicaciones",
    "### Regla de distribucion",
    "### Regla de auditoria de intentos de distribucion",
)


class AgentsDistributionCompactionTests(unittest.TestCase):
    def test_distribution_rules_live_only_in_canonical_document(self):
        agents = AGENTS.read_text(encoding="utf-8")
        distribution = DISTRIBUTION.read_text(encoding="utf-8")

        for heading in HEADINGS:
            self.assertEqual(distribution.count(heading), 1, heading)
            self.assertNotIn(heading, agents)

    def test_agents_links_distribution_from_startup_map(self):
        agents = AGENTS.read_text(encoding="utf-8")

        self.assertIn(
            "[`docs/AGENT-DISTRIBUTION.md`](docs/AGENT-DISTRIBUTION.md)",
            agents,
        )
        self.assertIn(
            "Distribution (historial, tracked links, entregas, auditoría)",
            agents,
        )

    def test_distribution_normative_contract_is_preserved(self):
        distribution = DISTRIBUTION.read_text(encoding="utf-8")

        for phrase in (
            "25 por pagina",
            "conteo total filtrado",
            "revalida tenant y estado active",
            "clave de idempotencia enviada al provider es estable",
            "Workers obsoletos nunca escriben resultados de audit",
            "eventos append-only",
            "No copiar excepciones, credenciales, bodies ni headers de proveedores",
            "Ningun provider real, secreto o mutacion externa se habilita",
        ):
            self.assertIn(phrase, distribution)

    def test_release_identity_matches_across_manifests(self):
        version_text = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        lock = json.loads(LOCK.read_text(encoding="utf-8"))

        version_line = next(
            line for line in version_text.splitlines() if "'number' =>" in line
        )
        release = version_line.split("'")[3]
        parts = release.split(".")
        self.assertEqual(len(parts), 3)
        self.assertTrue(all(part.isdigit() for part in parts))
        self.assertEqual(package["version"], release)
        self.assertEqual(lock["version"], release)
        self.assertEqual(lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
