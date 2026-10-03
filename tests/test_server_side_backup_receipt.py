from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / "app/Support/Operations/VerifiedBackupEvidence.php"
CONTROLLER = ROOT / "app/Http/Controllers/Admin/RunMigrationsController.php"
WORKFLOW = ROOT / ".github/workflows/production-migration.yml"
SCRIPT = ROOT / "scripts/run-production-migrations.sh"


class ServerSideBackupReceiptTests(unittest.TestCase):
    def test_record_writes_private_fingerprint_pointer_and_latest_validation_reuses_canonical_receipt_checks(self):
        evidence = EVIDENCE.read_text(encoding="utf-8")

        self.assertIn("LATEST_POINTER_DIRECTORY", evidence)
        self.assertIn("operations/database-backups/latest", evidence)
        self.assertIn("writeLatestPointer($receiptId, $migrationFingerprint)", evidence)
        self.assertIn("assertLatestValidForFingerprint", evidence)
        self.assertIn("$this->assertValid($receiptId, $migrationFingerprint);", evidence)
        self.assertIn("tempnam($directory, '.tmp-receipt-')", evidence)
        self.assertIn("@rename($temporaryPath, $pointerPath)", evidence)
        self.assertIn("@chmod($pointerPath, 0600)", evidence)

        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("assertLatestValidForFingerprint", controller)
        self.assertNotIn("'backup_receipt' =>", controller)

    def test_missing_stale_mismatched_or_tampered_evidence_fails_closed(self):
        evidence = EVIDENCE.read_text(encoding="utf-8")

        self.assertIn("Verified database backup pointer is missing or unsafe.", evidence)
        self.assertIn("Verified database backup pointer is malformed.", evidence)
        self.assertIn("Database backup receipt is not recent enough.", evidence)
        self.assertIn("Database backup receipt belongs to another migration batch.", evidence)
        self.assertIn("Verified database backup archive checksum does not match.", evidence)
        self.assertRegex(
            evidence,
            re.compile(
                r"if \(! is_file\(\$pointerPath\) \|\| is_link\(\$pointerPath\)\).*?"
                r"throw new RuntimeException",
                re.DOTALL,
            ),
        )

    def test_production_migration_has_no_backup_receipt_input_env_or_post_field(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertNotIn("backup_receipt:", workflow)
        self.assertNotIn("BACKUP_RECEIPT", workflow)
        self.assertNotIn("BACKUP_RECEIPT", script)
        self.assertNotIn('backup_receipt=', script)
        self.assertIn('confirmation=MIGRAR', script)
        self.assertIn('migration_batch=', script)
        self.assertIn("server-side", workflow)

    def test_migration_rerun_requires_original_and_triggering_actor_owner(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")

        self.assertIn("github.actor == github.repository_owner", workflow)
        self.assertIn("github.triggering_actor == github.repository_owner", workflow)
        self.assertIn(
            "production-migration-${{ github.run_id }}-${{ github.run_attempt }}",
            workflow,
        )


if __name__ == "__main__":
    unittest.main()
