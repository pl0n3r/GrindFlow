from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / "AGENTS.md"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"
VERSION = ROOT / "config/version.php"

MAX_AGENTS_LINES = 500

CANONICAL_DOCS = (
    "docs/AGENT-TRAFFIC.md",
    "docs/AGENT-SCHEDULER.md",
    "docs/AGENT-FINANCE.md",
    "docs/AGENT-DISTRIBUTION.md",
    "docs/AGENT-VAULT-INGESTION.md",
    "docs/AGENT-MEDIA-CONNECTIONS.md",
    "docs/AGENT-VAULT-UPLOADS.md",
    "docs/AGENT-SMOKE-E2E.md",
    "docs/AGENT-OPERATIONS.md",
    "docs/AGENT-PRODUCT-SCOPE-FREEZE.md",
)

REQUIRED_PROCESS_HEADINGS = (
    "## Inicio rápido obligatorio y lectura por relevancia",
    "### Mapa de lectura y cambios seguros",
    "## Protocolo de inicio para agentes y sesiones",
    "### Precedencia de fuentes",
    "### Regla de paralelizacion",
)

POINTER_HEADINGS_ALLOWED_IN_AGENTS = {
    "Reglas de dominio Traffic",
    "Reglas operativas detalladas",
}


class AgentsContextBudgetTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.agents = AGENTS.read_text(encoding="utf-8")
        cls.package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        cls.lock = json.loads(LOCK.read_text(encoding="utf-8"))
        cls.version_php = VERSION.read_text(encoding="utf-8")
        cls.product_scope_freeze = (ROOT / "docs/AGENT-PRODUCT-SCOPE-FREEZE.md").read_text(encoding="utf-8")

    def test_agents_stays_within_local_line_budget(self):
        line_count = len(self.agents.splitlines())
        self.assertLessEqual(line_count, MAX_AGENTS_LINES)
        self.assertIn("<= 500 líneas", self.agents)
        self.assertIn("guardrail propio de GrindFlow", self.agents)
        self.assertIn("no una restricción de Factory", self.agents)

    def test_bootstrap_process_headings_remain(self):
        for heading in REQUIRED_PROCESS_HEADINGS:
            with self.subTest(heading=heading):
                self.assertIn(heading, self.agents)

    def test_startup_map_links_canonical_docs_that_exist(self):
        startup_map = self.agents.split("### Mapa de lectura y cambios seguros", 1)[1].split(
            "**Regla para cambiar estas instrucciones:**",
            1,
        )[0]
        for relative in CANONICAL_DOCS:
            with self.subTest(relative=relative):
                path = ROOT / relative
                self.assertTrue(path.is_file(), relative)
                self.assertIn(f"]({relative})", startup_map)

    def test_extracted_domain_headings_do_not_return(self):
        agents_h3 = {
            match.group(1)
            for match in re.finditer(r"(?m)^###\s+(.+?)\s*$", self.agents)
        }
        canonical_h3 = set()
        for relative in CANONICAL_DOCS:
            content = (ROOT / relative).read_text(encoding="utf-8")
            canonical_h3.update(
                match.group(1)
                for match in re.finditer(r"(?m)^###\s+(.+?)\s*$", content)
            )

        overlap = sorted(
            (agents_h3 & canonical_h3) - POINTER_HEADINGS_ALLOWED_IN_AGENTS
        )
        self.assertEqual(overlap, [])

    def test_context_budget_regression_is_wired_into_npm_test(self):
        expected = "python3 -m unittest tests/test_agents_context_budget.py"
        self.assertIn(expected, self.package["scripts"]["test"])
        self.assertEqual(self.package["scripts"]["test"].count(expected), 1)

    def test_agents_links_product_scope_freeze(self):
        self.assertIn(
            "[`docs/AGENT-PRODUCT-SCOPE-FREEZE.md`](docs/AGENT-PRODUCT-SCOPE-FREEZE.md)",
            self.agents,
        )
        self.assertIn("`sigue` no genera requisitos", self.agents)

    def test_product_scope_freeze_preserves_no_spec_loop(self):
        self.assertIn("NO SPEC LOOP", self.product_scope_freeze)
        self.assertIn("Prohibido continuar numeraciones masivas", self.product_scope_freeze)
        self.assertIn("decisión explícita del owner", self.product_scope_freeze)

    def test_product_scope_freeze_regression_is_wired_into_npm_test(self):
        expected = "python3 -m unittest tests/test_agents_context_budget.py"
        self.assertIn(expected, self.package["scripts"]["test"])
        self.assertEqual(self.package["scripts"]["test"].count(expected), 1)

    def test_product_scope_freeze_preserves_canonical_flow(self):
        expected = (
            "fuente histórica → capacidad canónica → estado real → brecha → "
            "leaf ejecutable → código/tests/evidencia"
        )
        self.assertIn(expected, self.product_scope_freeze)
        self.assertIn(expected, self.agents)

    def test_release_version_is_synchronized(self):
        match = re.search(r"'number'\s*=>\s*'([^']+)'", self.version_php)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(self.package["version"], release)
        self.assertEqual(self.lock["version"], release)
        self.assertEqual(self.lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
