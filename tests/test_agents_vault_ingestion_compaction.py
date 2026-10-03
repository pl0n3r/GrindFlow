from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

HEADINGS = (
    "### Regla de ingesta persistente del Vault",
    "### Regla de handoff de fuentes de media",
)


class AgentsVaultIngestionCompactionTests(unittest.TestCase):
    def text(self, relative: str) -> str:
        return (ROOT / relative).read_text(encoding="utf-8")

    def test_vault_ingestion_rules_live_only_in_canonical_document(self):
        agents = self.text("AGENTS.md")
        canonical = self.text("docs/AGENT-VAULT-INGESTION.md")

        for heading in HEADINGS:
            self.assertNotIn(heading, agents)
            self.assertEqual(canonical.count(heading), 1)

    def test_agents_links_vault_ingestion_from_startup_map(self):
        agents = self.text("AGENTS.md")
        startup_map = agents.split("### Mapa de lectura y cambios seguros", 1)[1].split(
            "**Regla para cambiar estas instrucciones:**",
            1,
        )[0]

        self.assertIn(
            "[`docs/AGENT-VAULT-INGESTION.md`](docs/AGENT-VAULT-INGESTION.md)",
            startup_map,
        )
        self.assertIn("Vault ingestion/handoff de fuentes", startup_map)

    def test_vault_ingestion_normative_contract_is_preserved(self):
        canonical = self.text("docs/AGENT-VAULT-INGESTION.md")

        invariants = (
            "tenant debe estar resuelto y autorizado antes de encolar",
            "organizacion + SHA-256 de `source_type\\0source_ref`",
            "OrganizationAwareJob",
            "media_ingestions.last_error",
            "StagedMediaSource` + `MediaIngestionCoordinator::queueSource",
            "Nunca los persisten en source refs, metadata, errores, logs o Diagnostics",
            "MEDIA_STAGING_DISK",
            "Un 429 conserva un Retry-After acotado",
        )

        for invariant in invariants:
            self.assertIn(invariant, canonical)

    def test_release_identity_matches_across_manifests(self):
        php = self.text("config/version.php")
        package = json.loads(self.text("package.json"))
        lock = json.loads(self.text("package-lock.json"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", php)
        self.assertIsNotNone(match)
        release = match.group(1)
        parts = release.split(".")
        self.assertEqual(len(parts), 3)
        self.assertTrue(all(part.isdigit() for part in parts))
        self.assertEqual(package["version"], release)
        self.assertEqual(lock["version"], release)
        self.assertEqual(lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
