from __future__ import annotations

import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / "AGENTS.md"
CANONICAL = ROOT / "docs/AGENT-VAULT-UPLOADS.md"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"

HEADING = "### Regla de direct uploads del Vault"
TRAFFIC_HEADING = "### Reglas de dominio Traffic"


def compact(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


class AgentsVaultUploadsCompactionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.agents = AGENTS.read_text(encoding="utf-8")
        cls.canonical = CANONICAL.read_text(encoding="utf-8")
        cls.canonical_compact = compact(cls.canonical)
        cls.package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        cls.lock = json.loads(LOCK.read_text(encoding="utf-8"))
        cls.version_php = VERSION.read_text(encoding="utf-8")

    def test_direct_upload_rules_live_only_in_canonical_document(self):
        self.assertNotIn(HEADING, self.agents)
        self.assertEqual(self.canonical.count(HEADING), 1)
        self.assertIn(TRAFFIC_HEADING, self.agents)
        self.assertNotIn(TRAFFIC_HEADING, self.canonical)

    def test_agents_links_vault_uploads_from_startup_map(self):
        startup_map = self.agents.split("### Mapa de lectura y cambios seguros", 1)[1].split(
            "**Regla para cambiar estas instrucciones:**",
            1,
        )[0]
        self.assertIn(
            "[`docs/AGENT-VAULT-UPLOADS.md`](docs/AGENT-VAULT-UPLOADS.md)",
            startup_map,
        )
        self.assertIn("Vault direct uploads / object storage", startup_map)

    def test_direct_upload_normative_contract_is_preserved(self):
        expected = (
            "Los archivos grandes no atraviesan PHP",
            "URL temporal tenant-bound",
            "expira en un maximo de 60 minutos",
            "token de finalizacion va cifrado",
            "ligado a organizacion, usuario, disk, key, nombre, MIME, tamaño y expiracion",
            "storage key de staging usa UUID",
            "tenga exactamente el tamaño aprobado",
            "SHA-256 antes de crear el blob/asset",
            "deduplicacion sigue siendo por SHA-256 dentro del tenant",
            "limite duro inicial del direct upload es 2 GiB",
            "limite fijo de 8 MB",
            "Si object storage no esta configurado",
            "nunca romper Dashboard/Vault por ausencia de credenciales",
            "smoke normal permanece de solo lectura",
            "`MEDIA_STORAGE_*` -> `AWS_*` -> `R2_*` legado",
            "CORS nunca sustituye la autorizacion tenant ni la URL prefirmada",
            "Nunca muestra key, secret, bucket ni endpoint",
            "`MEDIA_STORAGE_READY` sin hacer un request adicional",
        )
        for snippet in expected:
            with self.subTest(snippet=snippet):
                self.assertIn(snippet, self.canonical_compact)

    def test_document_does_not_claim_storage_is_configured(self):
        lowered = self.canonical.lower()
        self.assertIn("si object storage no esta configurado", lowered)
        self.assertNotIn("object storage esta configurado en produccion", lowered)
        self.assertNotIn("media_storage_ready=1", lowered)
        self.assertIn("#146 conserva esa dependencia", self.canonical)

    def test_release_identity_matches_across_manifests(self):
        match = re.search(r"'number'\s*=>\s*'([^']+)'", self.version_php)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(self.package["version"], release)
        self.assertEqual(self.lock["version"], release)
        self.assertEqual(self.lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
