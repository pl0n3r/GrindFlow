import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
OPERATIONAL_HEADINGS = (
    "### Regla de eficiencia CI",
    "### Regla del README estilo BRVTAL",
    "### Regla BLOQUEANTE de CodeRabbit antes de fusionar",
    "### Factory deploy paralelo y reversible",
    "### Observacion de deploy y real-stack",
    "### Regla de visibilidad de SonarQube Cloud",
    "### Regla de diagnosticos de aplicacion",
)


class AgentsDocumentationCompactionTests(unittest.TestCase):
    def test_operational_rules_live_only_in_canonical_document(self) -> None:
        agents = (ROOT / "AGENTS.md").read_text()
        operations = (ROOT / "docs" / "AGENT-OPERATIONS.md").read_text()

        for heading in OPERATIONAL_HEADINGS:
            self.assertIn(heading, operations)
            self.assertNotIn(heading, agents)

        self.assertIn("GrindFlow CI / validate", operations)
        self.assertIn("CodeRabbit", operations)
        self.assertIn("Production Smoke", operations)
        self.assertIn("SonarQube Cloud", operations)
        self.assertIn("/admin/diagnostics.json", operations)

    def test_agents_links_operational_rules_from_startup_map(self) -> None:
        agents = (ROOT / "AGENTS.md").read_text()
        startup = agents.split("## Decisión vigente y obligatoria", 1)[0]

        self.assertIn("docs/AGENT-OPERATIONS.md", startup)
        self.assertIn("### Reglas operativas detalladas", agents)
        self.assertIn("workflow_dispatch", agents)
        self.assertIn("### Regla de pruebas E2E eficientes", agents)
        self.assertIn("Optimizar E2E significa maximizar evidencia por sesion", agents)
        self.assertIn(
            "[docs/AGENT-OPERATIONS.md](docs/AGENT-OPERATIONS.md)",
            agents,
        )

    def test_release_identity_is_v0155_and_package_versions_match(self) -> None:
        config = (ROOT / "config" / "version.php").read_text()
        package = json.loads((ROOT / "package.json").read_text())
        package_lock = json.loads((ROOT / "package-lock.json").read_text())

        match = re.search(r"'number'\s*=>\s*'([^']+)'", config)
        self.assertIsNotNone(match)
        self.assertEqual("0.1.155", match.group(1))
        self.assertEqual("0.1.155", package["version"])
        self.assertEqual("0.1.155", package_lock["version"])
        self.assertEqual("0.1.155", package_lock["packages"][""]["version"])


if __name__ == "__main__":
    unittest.main()
