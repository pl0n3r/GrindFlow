"""Contrato ejecutable: Vault Library #414, relaciones aditivas y tenant-safe.

Estos guards estructurales acompañan las pruebas de integración Symfony/MariaDB
en symfony/tests/php/LibraryCollectionsTest.php. No fabrican una ejecución DB.
"""
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "symfony/migrations/Version20261009233000.php"
SERVICE = ROOT / "symfony/src/Library/AssetCollectionApplication.php"
CONTROLLER = ROOT / "symfony/src/Http/Controller/LibraryCollectionsController.php"
PHP_TEST = ROOT / "symfony/tests/php/LibraryCollectionsTest.php"
RUNBOOK = ROOT / "docs/AGENT-LIBRARY-COLLECTIONS.md"


class LibraryCollectionsVariantsContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = MIGRATION.read_text(encoding="utf-8")
        cls.service = SERVICE.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.integration = PHP_TEST.read_text(encoding="utf-8")

    def test_collection_membership_many_to_many_without_blob_duplication(self):
        self.assertIn("CREATE TABLE gf_vault_collections", self.migration)
        self.assertIn("CREATE TABLE gf_vault_collection_assets", self.migration)
        self.assertIn("PRIMARY KEY (organization_id, collection_id, asset_id)", self.migration)
        self.assertIn("REFERENCES gf_vault_assets (organization_id, id)", self.migration)
        self.assertIn("ON DUPLICATE KEY UPDATE asset_id = asset_id", self.service)
        self.assertIn("function attachAsset(", self.service)
        self.assertIn("function assetsForCollection(", self.service)
        self.assertIn("function collectionsForAsset(", self.service)
        self.assertNotRegex(self.service, r"\b(?:INSERT INTO|UPDATE|DELETE FROM)\s+gf_vault_assets\b")
        self.assertIn("testAssetInMultipleCollectionsKeepsOnePhysicalBlob", self.integration)
        self.assertIn("COUNT(DISTINCT storage_key)", self.integration)

    def test_variant_master_relationship_with_type_and_listing(self):
        self.assertIn("CREATE TABLE gf_vault_asset_variants", self.migration)
        self.assertIn("PRIMARY KEY (organization_id, variant_asset_id)", self.migration)
        self.assertIn("CHECK (variant_type IN ('format', 'network', 'campaign'))", self.migration)
        self.assertIn("CHECK (master_asset_id <> variant_asset_id)", self.migration)
        self.assertIn("function linkVariant(", self.service)
        self.assertIn("function variantsForMaster(", self.service)
        self.assertIn("ORDER BY id FOR UPDATE", self.service)
        self.assertIn("testVariantsHaveTypedMasterAndCanBeListedWithoutMutation", self.integration)
        self.assertIn("grindflow_library_variant_link", self.controller)

    def test_tenant_scope_is_enforced_in_schema_and_application(self):
        for relationship in ("fk_gf_vault_collection_assets_collection",
                             "fk_gf_vault_collection_assets_asset",
                             "fk_gf_vault_variants_master",
                             "fk_gf_vault_variants_child"):
            self.assertIn(relationship, self.migration)
        self.assertGreaterEqual(
            self.migration.count("FOREIGN KEY (organization_id, "), 4
        )
        self.assertIn("gf_identity_memberships", self.service)
        self.assertIn("actor.is_active = 1", self.service)
        self.assertIn("membership.role IN ('admin', 'studio', 'editor')", self.service)
        self.assertIn("WHERE organization_id = :organization", self.service)
        self.assertIn("grindflow_organization_id", self.controller)
        self.assertIn("isCsrfTokenValid", self.controller)
        self.assertIn("testCrossTenantMembershipAndReferencesFailClosed", self.integration)

    def test_additive_migration_and_reversible_build_ahead(self):
        up = self.migration.split("public function up(", 1)[1].split("public function down(", 1)[0]
        self.assertNotRegex(up, r"\b(?:DROP|TRUNCATE|DELETE FROM|ALTER TABLE)\b")
        self.assertEqual(up.count("CREATE TABLE"), 3)
        self.assertIn("Production rollback requires backup", self.migration)
        self.assertTrue(RUNBOOK.is_file())
        docs = RUNBOOK.read_text(encoding="utf-8").lower()
        for needle in ("construccion", "no merge", "backup", "tenant", "sin datos reales"):
            self.assertIn(needle, docs)
        php = shutil.which("php")
        if php is None:
            self.fail("php CLI es obligatorio para validar sintaxis Symfony")
        for file in (MIGRATION, SERVICE, CONTROLLER, PHP_TEST):
            result = subprocess.run(
                [php, "-l", str(file)],
                text=True, capture_output=True, check=False, timeout=15,
            )
            self.assertEqual(0, result.returncode, result.stderr)


if __name__ == "__main__":
    unittest.main()
