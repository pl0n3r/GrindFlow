"""Contrato ejecutable: permisos Library por miembro (#53) e historial por asset (#52).

Estos guards estructurales acompañan las pruebas de integración Symfony/MariaDB
de symfony/tests/php/LibraryPermissionsHistoryTest.php. No fabrican una ejecución de base de datos.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "symfony/migrations/Version20261014120000.php"
PERMISSIONS = ROOT / "symfony/src/Library/LibraryPermissions.php"
HISTORY = ROOT / "symfony/src/Library/AssetHistoryService.php"
HISTORY_CONTROLLER = ROOT / "symfony/src/Http/Controller/LibraryHistoryController.php"
VAULT = ROOT / "symfony/src/Http/Controller/VaultController.php"
PHP_TEST = ROOT / "symfony/tests/php/LibraryPermissionsHistoryTest.php"
RUNBOOK = ROOT / "docs/AGENT-LIBRARY-PERMISSIONS.md"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"
CAPABILITIES = ("view", "upload", "edit", "approve", "authorize_publication", "archive", "delete")


class LibraryPermissionsHistoryContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = MIGRATION.read_text(encoding="utf-8")
        cls.permissions = PERMISSIONS.read_text(encoding="utf-8")
        cls.history = HISTORY.read_text(encoding="utf-8")
        cls.controller = HISTORY_CONTROLLER.read_text(encoding="utf-8")
        cls.vault = VAULT.read_text(encoding="utf-8")
        cls.integration = PHP_TEST.read_text(encoding="utf-8")

    def test_member_permissions_enforced_on_server(self):
        # Las siete capacidades existen en el servicio y en la restricción de esquema.
        for capability in CAPABILITIES:
            self.assertIn(f"'{capability}'", self.permissions)
            self.assertIn(f"'{capability}'", self.migration)
        # Sólo denegación: el miembro nunca supera a su rol.
        self.assertNotRegex(self.permissions, r"(?i)\bgrant\w*\(")
        self.assertNotRegex(self.permissions.split("private const ROLE_MATRIX")[1].split("];")[0], r"'delete'")
        # El servidor aplica cada capacidad: ninguna decisión sale del navegador.
        for capability in ("upload", "edit", "archive", "view"):
            self.assertIn(f"->allows(", self.vault)
            self.assertIn(f"'{capability}'", self.vault)
        # El rol sigue siendo la base (content_prepare) y la capacidad la afina por miembro.
        self.assertEqual(self.vault.count("['content_prepare']\n            || !$this->library->allows("), 7)
        self.assertIn("library_view_forbidden", self.vault)
        for check in ("testMemberPermissionsAreEnforcedOnServer", "roleMatrix", "->deny(", "assertSame(403"):
            self.assertIn(check, self.integration)

    def test_history_contains_asset_origin_changes_variants_publications(self):
        for source in ("gf_vault_assets", "gf_content_review_events", "gf_distribution_authorization_events",
                       "gf_vault_asset_variants", "gf_schedule_drafts", "gf_manual_handoff_events"):
            self.assertIn(source, self.history)
        for event in ("origin", "content_review", "distribution_authorization", "variant_linked",
                      "variant_of", "schedule_draft", "manual_handoff", "archived"):
            self.assertIn(f"'{event}'", self.history)
        self.assertIn("public const LIMIT = 100;", self.history)
        self.assertIn("/api/admin/vault/{id}/history", self.controller)
        self.assertIn("methods: ['GET']", self.controller)
        # Sólo lectura: ninguna escritura en el servicio ni en el controlador de historial.
        self.assertNotRegex(self.history + self.controller, r"(?i)\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO|FROM)?\s*gf_")
        self.assertIn("testHistoryContainsAssetOriginChangesVariantsPublications", self.integration)

    def test_history_redacts_pii_and_secrets(self):
        # Actor opaco por organización; ninguna columna sensible se selecciona.
        self.assertIn("grindflow-library-history|", self.history)
        self.assertIn("substr(hash('sha256'", self.history)
        forbidden = r"\b(?:email|password|token|csrf|caption|private_note|storage_key|sha256|delivery_destination_id|name)\b"
        selects = " ".join(re.findall(r"'SELECT .*?'", self.history, flags=re.S))
        self.assertNotRegex(selects.replace("original_name", ""), forbidden)
        self.assertIn("'actor_ref'", self.history)
        self.assertIn("testHistoryRedactsPiiAndSecrets", self.integration)
        for leak in ("SECRET-CAPTION-42", "SECRET-NOTE-42", "assertStringNotContainsString"):
            self.assertIn(leak, self.integration)

    def test_deny_cross_tenant_and_missing_role(self):
        # Todas las lecturas van acotadas por organización, incluso por acceso directo al servicio.
        self.assertGreaterEqual(self.history.count("organization_id = :organization"), 5)
        self.assertIn("return null;", self.history)
        self.assertIn("array_fill_keys(self::CAPABILITIES, false)", self.permissions)
        self.assertIn("if (!is_string($role) ||", self.permissions)
        self.assertIn("actor.is_active = 1", self.permissions)
        self.assertIn("return $none;", self.permissions)
        for check in ("testDenyCrossTenantAndMissingRole", "forAsset($f['foreign_org']", "assertResponseStatusCodeSame(404)"):
            self.assertIn(check, self.integration)

    def test_runbook_and_requirements_document_assumptions(self):
        text = RUNBOOK.read_text(encoding="utf-8")
        for needle in ("solo denegación", "delete", "actor_ref", "#417"):
            self.assertIn(needle, text)
        self.assertIn("GF-LIB-053", REQUIREMENTS.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
