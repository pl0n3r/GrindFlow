import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
TRAFFIC_HEADINGS = (
    "### Regla de reportes CSV de Traffic",
    "### Traffic: ciclo de vida reversible y export de calendario",
    "### Traffic: gestion completa de links existentes",
    "### Regla de atribucion de trafico",
)


class AgentsTrafficCompactionTests(unittest.TestCase):
    def test_traffic_rules_live_only_in_canonical_document(self) -> None:
        agents = (ROOT / "AGENTS.md").read_text()
        traffic = (ROOT / "docs" / "AGENT-TRAFFIC.md").read_text()

        for heading in TRAFFIC_HEADINGS:
            self.assertIn(heading, traffic)
            self.assertNotIn(heading, agents)

        for phrase in (
            "El limite CSV de 366 dias es INCLUSIVO",
            "`insertOrIgnore + lockForUpdate`",
            "Pausar/reanudar link no elimina metricas",
            "Reusar StoreTrackedLinkRequest",
        ):
            self.assertNotIn(phrase, agents)

    def test_agents_links_traffic_rules_from_startup_map(self) -> None:
        agents = (ROOT / "AGENTS.md").read_text()
        startup = agents.split("## Decisión vigente y obligatoria", 1)[0]

        self.assertIn("docs/AGENT-TRAFFIC.md", startup)
        self.assertIn("### Reglas de dominio Traffic", agents)
        self.assertIn(
            "[docs/AGENT-TRAFFIC.md](docs/AGENT-TRAFFIC.md)",
            agents,
        )

    def test_traffic_normative_contract_is_preserved(self) -> None:
        traffic = (ROOT / "docs" / "AGENT-TRAFFIC.md").read_text()

        for invariant in (
            "nunca IP, hash de visitante",
            "filtros explicitos de organizacion en ambas tablas",
            "Pausar/reanudar link no elimina metricas",
            "El limite CSV de 366 dias es INCLUSIVO",
            "`tracked_links` es tenant-owned",
            "Nunca se persisten IP, User-Agent, referrer ni country",
            "`insertOrIgnore + lockForUpdate`",
            "formula injection",
        ):
            self.assertIn(invariant, traffic)

    def test_release_identity_matches_across_manifests(self) -> None:
        config = (ROOT / "config" / "version.php").read_text()
        package = json.loads((ROOT / "package.json").read_text())
        package_lock = json.loads((ROOT / "package-lock.json").read_text())

        match = re.search(r"'number'\s*=>\s*'([^']+)'", config)
        self.assertIsNotNone(match)
        expected = match.group(1)
        self.assertEqual(expected, package["version"])
        self.assertEqual(expected, package_lock["version"])
        self.assertEqual(expected, package_lock["packages"][""]["version"])


if __name__ == "__main__":
    unittest.main()
