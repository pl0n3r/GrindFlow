from __future__ import annotations

import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / "AGENTS.md"
SCHEDULER = ROOT / "docs/AGENT-SCHEDULER.md"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"

HEADINGS = (
    "### Scheduler: edicion segura de asociaciones Traffic",
    "### Scheduler: calendario paginado sin cortes silenciosos",
    "### Scheduler: search sobre opciones de creacion (mas de 100)",
)


class AgentsSchedulerCompactionTests(unittest.TestCase):
    def test_scheduler_rules_live_only_in_canonical_document(self):
        agents = AGENTS.read_text(encoding="utf-8")
        scheduler = SCHEDULER.read_text(encoding="utf-8")

        for heading in HEADINGS:
            self.assertEqual(scheduler.count(heading), 1, heading)
            self.assertNotIn(heading, agents)

    def test_agents_links_scheduler_rules_from_startup_map(self):
        agents = AGENTS.read_text(encoding="utf-8")

        self.assertIn(
            "[`docs/AGENT-SCHEDULER.md`](docs/AGENT-SCHEDULER.md)",
            agents,
        )
        self.assertIn(
            "Scheduler (asociaciones Traffic, calendario, opciones de creación)",
            agents,
        )

    def test_scheduler_normative_contract_is_preserved(self):
        scheduler = SCHEDULER.read_text(encoding="utf-8")

        for phrase in (
            "revalida actor+tenant",
            "lock del schedule y luego del enlace",
            "25 items con total SQL real",
            "orden estable por UTC + id",
            "no propagar query params ajenos",
            "GET autenticado tenant-scoped",
            "filename/UUID exacto",
            "label/campaign/token exacto",
            "fallback amigable",
            "Schema\n  incompleto mantiene GET seguro",
        ):
            self.assertIn(phrase, scheduler)

    def test_release_identity_matches_across_manifests(self):
        version_text = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        lock = json.loads(LOCK.read_text(encoding="utf-8"))

        self.assertIn("'number' => '0.1.169'", version_text)
        self.assertEqual(package["version"], "0.1.169")
        self.assertEqual(lock["version"], "0.1.169")
        self.assertEqual(lock["packages"][""]["version"], "0.1.169")


if __name__ == "__main__":
    unittest.main()
