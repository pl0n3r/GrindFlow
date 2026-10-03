from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/run-production-backup.sh"
WORKFLOW = ROOT / ".github/workflows/production-backup.yml"


class ProductionBackupWorkflowTests(unittest.TestCase):
    def test_script_fails_closed_and_validates_remote_preconditions(self):
        script = SCRIPT.read_text(encoding="utf-8")

        for signal in (
            "StrictHostKeyChecking=yes",
            "UserKnownHostsFile=",
            '[[ -L "$current" ]]',
            'case "$release" in',
            'release="$(readlink -f "$current")"',
            'php_bin="/opt/alt/php85/usr/bin/php"',
            "database dump utility is unavailable",
            "pending migration count changed",
            '[[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]]',
        ):
            self.assertIn(signal, script)

    def test_dump_is_private_atomic_and_gzip_verified(self):
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("storage/app/private/operations/database-backups", script)
        self.assertIn("umask 077", script)
        self.assertIn("gzip -t", script)
        self.assertIn('chmod 600 "$tmp_archive"', script)
        self.assertIn('mv -f "$tmp_archive" "$archive_path"', script)
        self.assertNotIn("actions/upload-artifact", script)

    def test_database_credentials_never_leave_remote_host(self):
        script = SCRIPT.read_text(encoding="utf-8")

        remote_start = script.index("<<'REMOTE'")
        remote = script[remote_start:]
        self.assertIn('config("database.connections.".$default)', remote)
        self.assertIn('--defaults-extra-file="$credentials"', remote)
        self.assertIn('chmod 600 "$credentials" "$database_file"', remote)
        self.assertNotIn("DB_PASSWORD", script)
        self.assertNotIn("set -x", script)
        self.assertNotIn('return """', script)
        self.assertNotIn("cat .env", script)

    def test_receipt_uses_canonical_verified_backup_command(self):
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("operations:record-db-backup", script)
        self.assertIn('[[ "$receipt" =~ ^[0-9a-f]{64}$ ]]', script)
        self.assertIn("BACKUP_RECEIPT=", script)
        self.assertIn("MIGRATION_FINGERPRINT=", script)

    def test_workflow_is_manual_owner_only_and_never_runs_migrations(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("github.actor == github.repository_owner", workflow)
        self.assertIn("run-production-backup.sh", workflow)
        self.assertIn("retention-days: 1", workflow)
        self.assertNotIn("production-migration.yml", workflow)
        self.assertNotIn("artisan migrate", script)
        self.assertNotIn("/admin/system/migrations", script)
        self.assertNotIn("curl ", script)


if __name__ == "__main__":
    unittest.main()
