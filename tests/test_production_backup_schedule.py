#!/usr/bin/env python3
"""#780: future backup job is disabled and fails closed without real secrets."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-backup-scheduled.yml"
DOCS = ROOT / "docs/PRODUCTION-BACKUP-SCHEDULE.md"
SCRIPT = ROOT / "scripts/run-production-backup.sh"
MANUAL = ROOT / ".github/workflows/production-backup.yml"


class ProductionBackupScheduleTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.docs = DOCS.read_text(encoding="utf-8")
        cls.script = SCRIPT.read_text(encoding="utf-8")
        cls.manual = MANUAL.read_text(encoding="utf-8")

    def test_criterio_principal(self) -> None:
        """AC-01: no active schedule and checksum/retention contract present."""
        w = self.workflow
        self.assertIn("on:\n  workflow_dispatch:\n", w)
        self.assertIn("# schedule:", w)
        self.assertIn("cron: '17 3 * * *'", w)
        self.assertNotRegex(w, r"(?m)^  schedule:\s*$")
        self.assertIn("github.event_name == 'schedule'", w)
        self.assertIn("GRINDFLOW_DAILY_BACKUP_ENABLED == 'true'", w)
        self.assertIn("bash scripts/run-production-backup.sh", w)
        self.assertIn("RECOVERY_BACKUP_ENABLED: 'false'", w)
        self.assertIn("BACKUP_RETENTION_DAYS: '30'", w)
        self.assertIn("sha256sum -c", w)
        self.assertIn("30 dias", self.docs)
        self.assertIn("NO-GO", self.docs)

    def test_fail_closed_until_explicit_activation(self) -> None:
        w = self.workflow
        guard = re.search(r"(?m)^    if: (.*)$", w)
        self.assertIsNotNone(guard)
        self.assertIn("github.event_name == 'schedule'", guard.group(1))
        self.assertIn("vars.GRINDFLOW_DAILY_BACKUP_ENABLED == 'true'", guard.group(1))
        self.assertNotIn("\n  push:", w)
        self.assertNotIn("\n  pull_request:", w)
        self.assertRegex(w.replace("  # schedule:", "  schedule:", 1),
                         r"(?m)^  schedule:\s*$")

    def test_private_checksum_and_limited_retention(self) -> None:
        w = self.workflow
        for token in (
            "StrictHostKeyChecking=yes", "UserKnownHostsFile=",
            ".release-sha", "BACKUP_EXPECTED_PENDING", "BACKUP_EXPECTED_SHA",
            "gzip -t --", "sha256sum -c --", "chmod 0600",
            "ln --", "-maxdepth 1", "-mmin +43200", "rm -f --",
            "cancel-in-progress: false", "find \"$directory\"",
        ):
            self.assertIn(token, w, token)
        self.assertIn("operations:record-db-backup", self.script)
        self.assertIn("EXPECTED_PENDING", self.manual)
        self.assertNotIn("actions/upload-artifact", w)
        self.assertNotIn("PRODUCTION_RECOVERY_KEY_B64", w)
        self.assertNotIn("artisan down", w)
        self.assertNotIn("artisan migrate", w)

    def test_no_inline_secrets_or_real_side_effect(self) -> None:
        w = self.workflow
        self.assertIn("secrets.DEPLOY_SSH_KEY", w)
        self.assertIn("secrets.HOSTINGER_KNOWN_HOSTS", w)
        self.assertNotRegex(w, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotIn("DB_PASSWORD=", w)
        self.assertNotIn("set -x", w)
        self.assertNotIn("cat .env", w)
        self.assertIn("failure()", w)
        self.assertIn("gh issue", w)
        self.assertIn("No backup payload", w)
        self.assertIn("cero", self.docs)
        self.assertIn("PR revisado", self.docs)


if __name__ == "__main__":
    unittest.main()
