"""Contrato ejecutable: reglas por red (#45) y reutilización (#46) de Library.

Estos guards estructurales acompañan las pruebas de integración Symfony/MariaDB
de symfony/tests/php/LibraryNetworkReuseTest.php. No fabrican una ejecución de base de datos.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "symfony/migrations/Version20261014130000.php"
SERVICE = ROOT / "symfony/src/Library/AssetNetworkRules.php"
CONTROLLER = ROOT / "symfony/src/Http/Controller/LibraryNetworkRulesController.php"
RESERVE = ROOT / "symfony/src/Scheduling/ScheduleDraftApplicationService.php"
SCHEDULER = ROOT / "symfony/src/Scheduling/PublishingAutomationRuleScheduler.php"
PREVIEW = ROOT / "symfony/src/Http/Controller/ContentRuleController.php"
DRAFTS = ROOT / "symfony/src/Http/Controller/ScheduleDraftController.php"
PHP_TEST = ROOT / "symfony/tests/php/LibraryNetworkReuseTest.php"
RUNBOOK = ROOT / "docs/AGENT-LIBRARY-NETWORK-REUSE.md"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"


class LibraryNetworkReuseContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = MIGRATION.read_text(encoding="utf-8")
        cls.service = SERVICE.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.reserve = RESERVE.read_text(encoding="utf-8")
        cls.scheduler = SCHEDULER.read_text(encoding="utf-8")
        cls.preview = PREVIEW.read_text(encoding="utf-8")
        cls.drafts = DRAFTS.read_text(encoding="utf-8")
        cls.integration = PHP_TEST.read_text(encoding="utf-8")

    def test_denied_network_is_never_scheduled(self):
        # Esquema: regla por asset×red con efecto cerrado y claves compuestas por tenant.
        self.assertIn("CREATE TABLE gf_vault_asset_network_rules", self.migration)
        self.assertIn("CHECK (effect IN ('allow', 'deny'))", self.migration)
        self.assertIn("FOREIGN KEY (organization_id, asset_id)", self.migration)
        # Un único evaluador, usado por reserva (manual y automática), vista previa,
        # asignación de proveedor y publicación; deny gana siempre.
        self.assertIn("if ($effect === 'deny')", self.service)
        self.assertIn("return ['network_denied'];", self.service)
        self.assertIn("$this->networkRules->blockingReasons(", self.reserve)
        self.assertLess(self.reserve.index("$this->networkRules->blockingReasons("), self.reserve.index("if ($reasons !== [])"))
        self.assertIn("$networkRules->blockingReasons(", self.preview)
        self.assertIn("(new AssetNetworkRules($db))->isDenied(", self.drafts)
        self.assertEqual(self.drafts.count("(new AssetNetworkRules($db))->isDenied("), 2)
        self.assertIn("'network_denied'", self.drafts)
        # Ninguna ruta de reglas concede publicación.
        self.assertNotRegex(self.controller, r"(?i)can_publish\s*=>\s*true")
        for check in ("testDeniedNetworkIsNeverScheduled", "network_denied", "asset_not_eligible",
                      "assertSame('blocked'", "SELECT COUNT(*) FROM gf_schedule_drafts"):
            self.assertIn(check, self.integration)

    def test_max_n_and_minimum_interval_are_enforced(self):
        self.assertIn("CREATE TABLE gf_vault_asset_reuse_policies", self.migration)
        self.assertIn("CHECK (mode IN ('single_use', 'max_n', 'unlimited'))", self.migration)
        self.assertIn("max_uses BETWEEN 1 AND 1000", self.migration)
        self.assertIn("min_interval_hours <= 8760", self.migration)
        for fragment in ("'reuse_exhausted'", "'reuse_interval'", "mode'] === 'single_use'",
                         "mode'] === 'max_n'", "TIMESTAMPDIFF(MINUTE", "status = 'draft'",
                         "scheduled_at_utc <> :at"):
            self.assertIn(fragment, self.service)
        # La política específica de red gana a `any`; los borradores cancelados no cuentan.
        self.assertIn("ORDER BY network = 'any' ASC", self.service)
        self.assertNotIn("'cancelled'", self.service.split("private function reuseReasons")[1].split("public function forAsset")[0])
        # Los motivos que dependen del horario no descartan el asset para siempre.
        self.assertIn("SLOT_DEPENDENT_REASONS = ['asset_not_yet_valid', 'asset_expired', 'reuse_interval']", self.reserve)
        self.assertIn("ScheduleDraftApplicationService::SLOT_DEPENDENT_REASONS", self.scheduler)
        for check in ("testMaxNAndMinimumIntervalAreEnforced", "'max_n', 2", "reuse_interval", "exactly 24h away"):
            self.assertIn(check, self.integration)

    def test_missing_rules_apply_documented_safe_default(self):
        # Sin regla = permitido; sin política = ilimitado; red desconocida o fallo = denegado.
        self.assertIn("if ($policy === false) {\n            return [];", self.service)
        self.assertIn("return ['network_unknown'];", self.service)
        self.assertIn("catch (\\Throwable)", self.service)
        self.assertEqual(self.service.count("catch (\\Throwable)"), 2)
        self.assertRegex(self.service, r"if \(!in_array\(\$network, self::NETWORKS, true\)\) \{\s+return true;")
        self.assertIn("public const NETWORKS = ['facebook_page', 'instagram_reels'];", self.service)
        # La API es estricta, CSRF y capacidad `edit`, y el tenant sale de la sesión.
        endpoint = self.controller
        self.assertIn("isCsrfTokenValid('grindflow_vault_manage'", endpoint)
        self.assertIn("'edit'", endpoint)
        self.assertIn("array_keys($body) !== ['network', 'effect']", endpoint)
        self.assertIn("array_keys($body) !== ['network', 'mode', 'max_uses', 'min_interval_hours']", endpoint)
        self.assertNotIn("$body['organization", endpoint)
        runbook = RUNBOOK.read_text(encoding="utf-8")
        for needle in ("Sin regla", "ilimitada", "denegado", "#416"):
            self.assertIn(needle, runbook)
        self.assertIn("GF-LIB-045", REQUIREMENTS.read_text(encoding="utf-8"))
        for check in ("testMissingRulesApplyDocumentedSafeDefault", "network_unknown", "createMock(Connection::class)"):
            self.assertIn(check, self.integration)


if __name__ == "__main__":
    unittest.main()
