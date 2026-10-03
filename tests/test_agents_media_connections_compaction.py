from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / "AGENTS.md"
CANONICAL = ROOT / "docs/AGENT-MEDIA-CONNECTIONS.md"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"

HEADING = "### Regla de conexiones y scans de media"
DIRECT_UPLOAD_HEADING = "### Regla de direct uploads del Vault"


class AgentsMediaConnectionsCompactionTests(unittest.TestCase):
    def test_media_connections_rules_live_only_in_canonical_document(self):
        agents = AGENTS.read_text(encoding="utf-8")
        canonical = CANONICAL.read_text(encoding="utf-8")

        self.assertNotIn(HEADING, agents)
        self.assertEqual(canonical.count(HEADING), 1)

    def test_agents_links_media_connections_from_startup_map(self):
        agents = AGENTS.read_text(encoding="utf-8")
        startup_map = agents.split("### Mapa de lectura y cambios seguros", 1)[1].split(
            "**Regla para cambiar estas instrucciones:**",
            1,
        )[0]

        self.assertIn(
            "[`docs/AGENT-MEDIA-CONNECTIONS.md`](docs/AGENT-MEDIA-CONNECTIONS.md)",
            startup_map,
        )
        self.assertIn("Media connections/scans/OAuth/procesamiento", startup_map)
        self.assertIn("conectores/jobs/cursores aplicables", startup_map)

    def test_media_connections_normative_contract_is_preserved(self):
        canonical = CANONICAL.read_text(encoding="utf-8")

        invariants = (
            "AES-256-GCM",
            "grindflow:cloud:<organization_id>:<provider>",
            "Solo `MediaConnectionManager` cifra o rota access/refresh tokens",
            "Antes de dispatch debe revalidar actor + tenant",
            "`OrganizationAwareJob`",
            "`needs_reconnect`",
            "HTTP 429 solo difiere `next_scan_at`",
            "Los scans guardan el cursor mas reciente",
            "`media_connections` aun no existe, el tick devuelve cero sin romper la app",
            "organization_id nunca",
            "`files.list.nextPageToken` solo continua el bootstrap",
            "`changes.getStartPageToken` ANTES",
            "`media_connections.cursor` guarda JSON versionado",
            "OAuthPendingState",
            "OAuthConnectionCoordinator",
            "MediaConnectionTokenProvider",
            "Todo asset canonico entra al procesamiento por MediaProcessingCoordinator",
            "ProcessMediaAsset es idempotente por organization + asset + processor version",
            "dispatch_failed",
            "probe_v1",
        )

        for invariant in invariants:
            self.assertIn(invariant, canonical)

    def test_direct_upload_rules_remain_in_agents(self):
        agents = AGENTS.read_text(encoding="utf-8")
        canonical = CANONICAL.read_text(encoding="utf-8")

        self.assertEqual(agents.count(DIRECT_UPLOAD_HEADING), 1)
        self.assertNotIn(DIRECT_UPLOAD_HEADING, canonical)

    def test_release_identity_matches_across_manifests(self):
        version_text = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        lock = json.loads(LOCK.read_text(encoding="utf-8"))

        match = re.search(r"'number'\s*=>\s*'([^']+)'", version_text)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(package["version"], release)
        self.assertEqual(lock["version"], release)
        self.assertEqual(lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
