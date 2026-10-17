"""Contrato ejecutable: estados del asset (#44) y papelera con eliminación definitiva (#54).

Estos guards estructurales acompañan las pruebas de integración Symfony/MariaDB
de symfony/tests/php/LibraryLifecycleTrashTest.php. No fabrican una ejecución de base de datos.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "symfony/migrations/Version20261014140000.php"
LIFECYCLE = ROOT / "symfony/src/Library/AssetLifecycle.php"
CONTROLLER = ROOT / "symfony/src/Http/Controller/LibraryLifecycleController.php"
VAULT = ROOT / "symfony/src/Http/Controller/VaultController.php"
PERMISSIONS = ROOT / "symfony/src/Library/LibraryPermissions.php"
HISTORY = ROOT / "symfony/src/Library/AssetHistoryService.php"
RESERVE = ROOT / "symfony/src/Scheduling/ScheduleDraftApplicationService.php"
PREVIEW = ROOT / "symfony/src/Http/Controller/ContentRuleController.php"
PHP_TEST = ROOT / "symfony/tests/php/LibraryLifecycleTrashTest.php"
RUNBOOK = ROOT / "docs/AGENT-LIBRARY-LIFECYCLE.md"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"
STATES = ("new", "ready", "in_review", "in_use", "paused", "blocked", "archived")


class LibraryStatesTrashContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = MIGRATION.read_text(encoding="utf-8")
        cls.lifecycle = LIFECYCLE.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.vault = VAULT.read_text(encoding="utf-8")
        cls.permissions = PERMISSIONS.read_text(encoding="utf-8")
        cls.history = HISTORY.read_text(encoding="utf-8")
        cls.reserve = RESERVE.read_text(encoding="utf-8")
        cls.preview = PREVIEW.read_text(encoding="utf-8")
        cls.integration = PHP_TEST.read_text(encoding="utf-8")

    def test_invalid_transitions_are_rejected_and_audited(self):
        # Siete estados persistidos en el esquema; `expired` se deriva y nunca se guarda.
        for state in STATES:
            self.assertIn(f"'{state}'", self.migration)
            self.assertIn(f"'{state}'", self.lifecycle)
        self.assertIn("CHECK (lifecycle_state IN ('new', 'ready', 'in_review', 'in_use', 'paused', 'blocked', 'archived'))", self.migration)
        self.assertIn("public const DERIVED = 'expired';", self.lifecycle)
        self.assertIn("CREATE TABLE gf_vault_asset_lifecycle_events", self.migration)
        self.assertIn("CHECK (outcome IN ('applied', 'rejected'))", self.migration)
        # Cada intento deja un evento, aplicado o rechazado, dentro de la misma transacción.
        for reason in ("derived_state", "same_state", "invalid_transition"):
            self.assertIn(f"'{reason}'", self.lifecycle)
        self.assertIn("$this->db->insert('gf_vault_asset_lifecycle_events'", self.lifecycle)
        self.assertIn("'outcome' => $reason === null ? 'applied' : 'rejected'", self.lifecycle)
        self.assertIn("FOR UPDATE", self.lifecycle)
        # Endpoint estricto: capacidad `edit`, CSRF y cuerpo con la única clave `to`.
        self.assertIn("array_keys($body) !== ['to']", self.controller)
        self.assertIn("'edit'", self.controller)
        self.assertIn("isCsrfTokenValid('grindflow_vault_manage'", self.controller)
        self.assertIn("409", self.controller)
        # Los estados que excluyen se aplican en reserva y vista previa; el historial los muestra.
        self.assertIn("AssetLifecycle::blockingReasons(", self.reserve)
        self.assertIn("AssetLifecycle::blockingReasons(", self.preview)
        self.assertIn("'lifecycle_transition'", self.history)
        for check in ("testInvalidTransitionsAreRejectedAndAudited", "'rejected'", "same_state", "derived_state", "lifecycle_paused"):
            self.assertIn(check, self.integration)

    def test_restore_recovers_asset_with_history(self):
        # El borrado reversible sigue siendo una marca (actor + fecha); la restauración no toca los eventos.
        self.assertIn("asset.deleted_at = :deleted, asset.deleted_by = :deleter", self.vault)
        self.assertNotRegex(self.vault, r"(?i)DELETE\s+FROM\s+gf_(?:vault_assets|content_review|distribution|schedule|vault_asset)")
        restore = self.vault[self.vault.index("private function transition("):self.vault.index("private function context(")]
        self.assertIn("$asset['purged_at'] !== null", restore)
        self.assertIn("'lifecycle_state'", self.vault)
        for check in ("testRestoreRecoversAssetWithHistory", "restore must keep the history", "lifecycle_transition", "content_review"):
            self.assertIn(check, self.integration)

    def test_permanent_delete_requires_authorization_and_preserves_metrics(self):
        # Capacidad `delete` solo para admin, confirmación explícita y solo desde la papelera.
        matrix = self.permissions.split("private const ROLE_MATRIX")[1].split("];")[0]
        self.assertEqual(len(re.findall(r"'delete'", matrix)), 1)
        self.assertRegex(matrix, r"'admin' => \[[^\]]*'delete'")
        purge = self.controller[self.controller.index("public function purge("):self.controller.index("private function statePayload(")]
        self.assertIn("'delete'", purge)
        self.assertIn("isCsrfTokenValid('grindflow_vault_manage'", purge)
        self.assertIn("array_keys($body) !== ['confirm_asset_id']", purge)
        self.assertIn("hash_equals(", purge)
        self.assertIn("purge_requires_trash", purge)
        self.assertIn("$asset['deleted_at'] === null", purge)
        # Se borra el original y se limpian los datos personales; la fila y los eventos se conservan.
        self.assertIn("@unlink($path)", purge)
        self.assertIn("private_note = NULL", purge)
        self.assertIn("purged_at = :purged_at", purge)
        self.assertNotRegex(purge, r"(?i)DELETE\s+FROM")
        self.assertIn("retained_metrics", purge)
        for table in ("gf_schedule_drafts", "gf_content_review_events", "gf_distribution_authorization_events", "gf_vault_asset_variants"):
            self.assertIn(table, purge)
        # Un asset purgado no cuenta en cuota, listado ni deduplicación.
        self.assertGreaterEqual(self.vault.count("purged_at IS NULL"), 3)
        self.assertIn("'purged'", self.history)
        for check in ("testPermanentDeleteRequiresAuthorizationAndPreservesMetrics", "assertSame(403", "retained_metrics", "assertFileDoesNotExist", "used_assets"):
            self.assertIn(check, self.integration)
        runbook = RUNBOOK.read_text(encoding="utf-8")
        for needle in ("expired", "derivado", "eliminación definitiva", "#415"):
            self.assertIn(needle, runbook)
        self.assertIn("GF-LIB-044", REQUIREMENTS.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
