from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ProductionWritePolicyAcceptanceTest(unittest.TestCase):
    def test_construction_autonomy_contract(self):
        policy = (ROOT / "app/Support/Operations/ProductionWritePolicy.php").read_text(encoding="utf-8")
        php_tests = (ROOT / "tests/Feature/ProductionWritePolicyTest.php").read_text(encoding="utf-8")
        self.assertIn("PHASE_CONSTRUCTION = 'construccion'", policy)
        self.assertIn("assertAutonomousWriteAllowed", policy)
        self.assertIn("Autonomous production writes must be versioned.", policy)
        self.assertIn("test_construction_allows_non_destructive_versioned_operations", php_tests)

    def test_live_and_destructive_fail_closed_contract(self):
        policy = (ROOT / "app/Support/Operations/ProductionWritePolicy.php").read_text(encoding="utf-8")
        php_tests = (ROOT / "tests/Feature/ProductionWritePolicyTest.php").read_text(encoding="utf-8")
        self.assertIn("PHASE_LIVE = 'live'", policy)
        self.assertIn("disabled outside construction", policy)
        self.assertIn("Destructive production writes require explicit owner authorization.", policy)
        self.assertIn("test_live_and_destructive_operations_fail_closed", php_tests)

    def test_verified_backup_and_lock_contract(self):
        policy = (ROOT / "app/Support/Operations/ProductionWritePolicy.php").read_text(encoding="utf-8")
        evidence = (ROOT / "app/Support/Operations/VerifiedBackupEvidence.php").read_text(encoding="utf-8")
        controller = (ROOT / "app/Http/Controllers/Admin/RunMigrationsController.php").read_text(encoding="utf-8")
        php_tests = (ROOT / "tests/Feature/ProductionWritePolicyTest.php").read_text(encoding="utf-8")
        self.assertIn("MAX_AGE_SECONDS = 900", evidence)
        self.assertIn("archive_sha256", evidence)
        self.assertIn("hash_equals", evidence)
        self.assertIn("A recent verified database backup is required.", policy)
        self.assertIn("An exclusive operation lock is required.", policy)
        self.assertIn("flock($lock, LOCK_EX | LOCK_NB)", controller)
        self.assertIn("$backupEvidence->assertValid", controller)
        self.assertIn("test_bulk_or_migration_requires_recent_verified_backup_and_lock", php_tests)
        self.assertIn("test_backup_receipt_expires_after_fifteen_minutes", php_tests)

    def test_documentation_and_boolean_regression_contract(self):
        controller = (ROOT / "app/Http/Controllers/Admin/RunMigrationsController.php").read_text(encoding="utf-8")
        workflow = (ROOT / ".github/workflows/production-migration.yml").read_text(encoding="utf-8")
        runner = (ROOT / "scripts/run-production-migrations.sh").read_text(encoding="utf-8")
        docs = (ROOT / "docs/DEPLOY-HOSTINGER.md").read_text(encoding="utf-8")
        php_tests = (ROOT / "tests/Feature/ProductionWritePolicyTest.php").read_text(encoding="utf-8")
        self.assertNotIn("'backup_confirmed' => ['required'", controller)
        self.assertIn("'backup_receipt' => ['required'", controller)
        self.assertIn("backup_receipt:", workflow)
        self.assertIn('BACKUP_RECEIPT="${BACKUP_RECEIPT:-}"', runner)
        self.assertIn("backup DB verificable", docs)
        self.assertIn("Nunca recrear el bypass mediante checkbox", docs)
        self.assertIn("test_documentation_and_migration_flow_require_verifiable_backup_evidence", php_tests)


if __name__ == "__main__":
    unittest.main()
