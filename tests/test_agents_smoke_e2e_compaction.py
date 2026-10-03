from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

HEADINGS = (
    "### Credenciales de Smoke: no aceptar verde sin autenticacion",
    "### Smoke de produccion vertical / version observada",
    "### E2E en navegador autenticado (CI aislado)",
    "### Regla de pruebas E2E eficientes",
)


class AgentsSmokeE2ECompactionTests(unittest.TestCase):
    def text(self, relative: str) -> str:
        return (ROOT / relative).read_text(encoding="utf-8")

    def test_smoke_e2e_rules_live_only_in_canonical_document(self):
        agents = self.text("AGENTS.md")
        canonical = self.text("docs/AGENT-SMOKE-E2E.md")

        for heading in HEADINGS:
            self.assertNotIn(heading, agents)
            self.assertEqual(canonical.count(heading), 1)

    def test_agents_links_smoke_e2e_from_startup_map(self):
        agents = self.text("AGENTS.md")
        startup_map = agents.split("### Mapa de lectura y cambios seguros", 1)[1].split(
            "**Regla para cambiar estas instrucciones:**",
            1,
        )[0]

        self.assertIn(
            "[`docs/AGENT-SMOKE-E2E.md`](docs/AGENT-SMOKE-E2E.md)",
            startup_map,
        )
        self.assertIn("Smoke/E2E", startup_map)

    def test_smoke_e2e_normative_contract_is_preserved(self):
        canonical = self.text("docs/AGENT-SMOKE-E2E.md")

        invariants = (
            "PRODUCTION_E2E_PASSWORD",
            "nunca ejecutar\n  links publicos /l/*",
            "Production Smoke conserva GETs solo lectura",
            "::add-mask::",
            "METADATA sintética procesada",
            "Agrupar validaciones E2E en la misma sesion autenticada",
            "ni esconder fallos mediante retries excesivos",
            "No presentar un job omitido como validación de producción satisfactoria",
        )

        for invariant in invariants:
            self.assertIn(invariant, canonical)

    def test_release_identity_matches_across_manifests(self):
        php = self.text("config/version.php")
        package = json.loads(self.text("package.json"))
        lock = json.loads(self.text("package-lock.json"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", php)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), "0.1.173")
        self.assertEqual(package["version"], "0.1.173")
        self.assertEqual(lock["version"], "0.1.173")
        self.assertEqual(lock["packages"][""]["version"], "0.1.173")


if __name__ == "__main__":
    unittest.main()
