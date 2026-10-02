import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
FINANCE_HEADINGS = (
    "### Finance: reconciliacion por eventos, no saldo bancario",
    "### Regla de Finance",
)


class AgentsFinanceCompactionTests(unittest.TestCase):
    def test_finance_rules_live_only_in_canonical_document(self) -> None:
        agents = (ROOT / "AGENTS.md").read_text()
        finance = (ROOT / "docs" / "AGENT-FINANCE.md").read_text()

        for heading in FINANCE_HEADINGS:
            self.assertEqual(1, finance.count(heading))
            self.assertNotIn(heading, agents)

        for phrase in (
            "Una reversa copia monto, moneda, fuente y beneficiario del original",
            "Nunca reescribir, borrar ni compensar el ledger automaticamente",
        ):
            self.assertIn(phrase, finance)
            self.assertNotIn(phrase, agents)

    def test_agents_links_finance_rules_from_startup_map(self) -> None:
        agents = (ROOT / "AGENTS.md").read_text()
        startup = agents.split("## Decisión vigente y obligatoria", 1)[0]
        self.assertIn("docs/AGENT-FINANCE.md", startup)
        self.assertIn("Finance (ledger, reversas, reportes)", startup)

    def test_finance_normative_contract_is_preserved(self) -> None:
        finance = (ROOT / "docs" / "AGENT-FINANCE.md").read_text()
        for invariant in (
            "`occurred_on` del asiento",
            "Un SOLO builder tenant-scoped",
            "Finance solo Admin/Studio autorizados",
            "`revenue_allocations` es un ledger tenant-owned **append-only**",
            "`amount_minor`; nunca usar",
            "Totales netos se derivan",
            "Finance core v1 no implementa cobros",
        ):
            self.assertIn(invariant, finance)

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
