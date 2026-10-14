"""Contrato ejecutable: vigencia UTC de assets Library (#407, decisión #41).

Estos guards estructurales acompañan las pruebas de integración Symfony/MariaDB
de symfony/tests/php/VaultValidityTest.php. No fabrican una ejecución de base de datos.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "symfony/migrations/Version20261009190000.php"
VAULT = ROOT / "symfony/src/Http/Controller/VaultController.php"
RULES = ROOT / "symfony/src/Http/Controller/ContentRuleController.php"
SERVICE = ROOT / "symfony/src/Scheduling/ScheduleDraftApplicationService.php"
PANEL = ROOT / "symfony/frontend/admin/VaultPanel.tsx"
PHP_TEST = ROOT / "symfony/tests/php/VaultValidityTest.php"
VERSION = ROOT / "config/version.php"
README = ROOT / "README.md"
SPEC = ROOT / "docs/GRINDFLOW-SPEC.md"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"


class LibraryAssetValidityContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = MIGRATION.read_text(encoding="utf-8")
        cls.vault = VAULT.read_text(encoding="utf-8")
        cls.rules = RULES.read_text(encoding="utf-8")
        cls.service = SERVICE.read_text(encoding="utf-8")
        cls.panel = PANEL.read_text(encoding="utf-8")
        cls.integration = PHP_TEST.read_text(encoding="utf-8")

    def _validity_endpoint(self) -> str:
        start = self.vault.index("public function validity(")
        end = self.vault.index("public function bulkUsage(")
        return self.vault[start:end]

    def test_content_prepare_can_set_and_clear_validity_tenant_safely(self):
        # Migración aditiva, nullable y con ventana coherente.
        self.assertIn("ADD valid_from_at DATETIME DEFAULT NULL", self.migration)
        self.assertIn("ADD valid_until_at DATETIME DEFAULT NULL", self.migration)
        self.assertIn("valid_until_at > valid_from_at", self.migration)
        self.assertNotRegex(self.migration, r"(?i)\bDROP\s+TABLE\b")
        endpoint = self._validity_endpoint()
        self.assertIn("/api/admin/vault/{id}/validity", self.vault)
        self.assertIn("['content_prepare']", endpoint)
        self.assertIn("isCsrfTokenValid('grindflow_vault_manage'", endpoint)
        # Cuerpo estricto: sólo las dos fechas; el tenant sale de la sesión.
        self.assertIn("array_keys($body) !== ['valid_from_at', 'valid_until_at']", endpoint)
        self.assertIn("asset.organization_id = :organization", endpoint)
        self.assertIn("asset.deleted_at IS NULL", endpoint)
        self.assertNotIn("$body['organization", endpoint)
        for clause in ("gf_identity_organizations", "FOR UPDATE", "membership.role IN ('admin', 'studio', 'editor')"):
            self.assertIn(clause, endpoint)
        for method in ("testContentPrepareCanSetAndClearUtcValidityWithoutCrossTenantMutation",):
            self.assertIn(f"function {method}", self.integration)
        for check in ("assertResponseStatusCodeSame(401)", "assertResponseStatusCodeSame(403)",
                      "assertResponseStatusCodeSame(404)", "assertResponseStatusCodeSame(422)",
                      "foreign_asset"):
            self.assertIn(check, self.integration)

    def test_expired_asset_remains_visible_and_state_is_stable(self):
        for state in ("not_yet_valid", "valid", "expired"):
            self.assertIn(f"'{state}'", self.service)
            self.assertIn(state, self.panel)
        # Listado y detalle exponen sólo fechas y estado derivado.
        self.assertGreaterEqual(self.vault.count("asset.valid_from_at, asset.valid_until_at"), 2)
        self.assertIn("function validityPayload(", self.vault)
        self.assertIn("] + $this->validityPayload($asset);", self.vault)
        # El estado derivado jamás filtra el lifecycle: papelera/restauración no miran la vigencia.
        lifecycle = self.vault[self.vault.index("private function transition("):]
        self.assertNotRegex(lifecycle.split("private function context(")[0], r"valid_(?:from|until)_at|validity")
        self.assertIn("function testExpiredAssetRemainsVisibleWithStableValidityState", self.integration)
        self.assertIn("view=trash", self.integration)

    def test_preview_and_draft_block_out_of_window(self):
        # Preview S3: mismos bloqueos estables.
        self.assertIn("asset.valid_from_at, asset.valid_until_at", self.rules)
        self.assertIn("ScheduleDraftApplicationService::validityState(", self.rules)
        self.assertIn("'asset_'.$validityState", self.rules)
        self.assertIn("'validity_state' => $validityState", self.rules)
        # Reserva transaccional: bajo el FOR UPDATE del asset, ahora y en el slot.
        reserve = self.service[self.service.index("public function reserve("):]
        self.assertIn("valid_from_at, valid_until_at", reserve)
        self.assertLess(reserve.index("FOR UPDATE"), reserve.index("self::validityState("))
        self.assertIn("[$nowUtc, $slotUtc]", reserve)
        self.assertIn("'asset_'.$validity", reserve)
        self.assertLess(reserve.index("self::validityState("), reserve.index("if ($reasons !== [])"))
        self.assertIn("function testPreviewAndDraftFailClosedOutsideValidityWindow", self.integration)
        for reason in ("asset_expired", "asset_not_yet_valid"):
            self.assertIn(reason, self.integration)

    def test_validity_is_single_source_utc_and_not_a_publication_grant(self):
        # Una sola semántica: sólo el servicio define la comparación de ventana.
        self.assertEqual(self.service.count("public static function validityState("), 1)
        for text in (self.vault, self.rules):
            self.assertNotIn("function validityState(", text)
            self.assertNotRegex(text, r"\$at(Utc)?\s*[<>]=?\s*\$validFrom")
        self.assertIn("gmdate('Y-m-d H:i:s')", self.service)
        self.assertIn("$atUtc < $validFromAt", self.service)
        self.assertIn("$atUtc >= $validUntilAt", self.service)
        # Estrictamente UTC: formato con Z y sin zonas horarias locales.
        self.assertIn("new \\DateTimeZone('UTC')", self.vault)
        self.assertRegex(self.vault, r"\\d\{4\}-\\d\{2\}-\\d\{2\}T\\d\{2\}:\\d\{2\}:\\d\{2\}Z")
        self.assertNotIn("date_default_timezone_set", self.vault + self.rules + self.service)
        # Separada de review/autorización: no altera sus consultas ni concede permiso.
        self.assertEqual(self.rules.count("'can_publish' => false"), 1)
        self.assertNotRegex(self.service, r"(?s)gf_content_review_events[^;]{0,300}valid_(?:from|until)_at")
        self.assertNotRegex(self.service, r"(?s)gf_distribution_authorization_events[^;]{0,300}valid_(?:from|until)_at")
        self.assertIn("no es una licencia", self.panel)
        self.assertNotRegex(self.panel, r"(?i)autoriza(?:r|ción)? publicar(?!\s*\.)")

    def test_release_identity_follows_serialization_and_is_consistent(self):
        # #407 ya no reclama la identidad de release: no hay bump propio y cada
        # copia de la versión declarada debe coincidir con la fuente única.
        version = re.search(r"'number'\s*=>\s*'(\d+\.\d+\.\d+)'", VERSION.read_text(encoding="utf-8"))
        self.assertIsNotNone(version)
        declared = version.group(1)
        self.assertRegex(README.read_text(encoding="utf-8"), rf"V{re.escape(declared)}\b")
        package = (ROOT / "package.json").read_text(encoding="utf-8")
        self.assertIn(f'"version": "{declared}"', package)
        # La migración usa el identificador reclamado y es la última de la hoja.
        self.assertEqual(MIGRATION.name, "Version20261009190000.php")
        self.assertIn("final class Version20261009190000", self.migration)
        # La especificación y los requisitos reflejan la brecha #41, sin #42–#54.
        self.assertIn("GF-LIB-041", REQUIREMENTS.read_text(encoding="utf-8"))
        for document in (SPEC, REQUIREMENTS):
            text = document.read_text(encoding="utf-8")
            self.assertIn("#407", text)
            self.assertIn("not_yet_valid", text)


if __name__ == "__main__":
    unittest.main()
