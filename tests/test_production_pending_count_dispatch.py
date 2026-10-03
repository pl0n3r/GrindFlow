from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ProductionPendingCountDispatchTests(unittest.TestCase):
    def setUp(self):
        self.backup = (ROOT / ".github/workflows/production-backup.yml").read_text(encoding="utf-8")
        self.migration = (ROOT / ".github/workflows/production-migration.yml").read_text(encoding="utf-8")
        self.backup_script = (ROOT / "scripts/run-production-backup.sh").read_text(encoding="utf-8")
        self.migration_script = (ROOT / "scripts/run-production-migrations.sh").read_text(encoding="utf-8")

    def _expected_pending_block(self, workflow):
        match = re.search(
            r"(?ms)^      expected_pending:\n(?P<body>(?:        .+\n)+)",
            workflow,
        )
        self.assertIsNotNone(match)
        return match.group("body")

    def test_backup_accepts_required_exact_count_without_stale_enum(self):
        block = self._expected_pending_block(self.backup)
        self.assertIn("required: true", block)
        self.assertIn("type: string", block)
        self.assertNotIn("type: choice", block)
        self.assertNotIn("options:", block)
        self.assertIn("EXPECTED_PENDING: ${{ inputs.expected_pending }}", self.backup)
        self.assertIn("bash scripts/run-production-backup.sh", self.backup)

    def test_migration_accepts_required_exact_count_without_stale_enum(self):
        block = self._expected_pending_block(self.migration)
        self.assertIn("required: true", block)
        self.assertIn("type: string", block)
        self.assertNotIn("type: choice", block)
        self.assertNotIn("options:", block)
        self.assertIn("EXPECTED_PENDING: ${{ inputs.expected_pending }}", self.migration)
        self.assertIn("bash scripts/run-production-migrations.sh", self.migration)

    def test_backup_script_fails_closed_on_count_mismatch(self):
        self.assertIn(
            '[[ "$EXPECTED_PENDING" =~ ^[1-9][0-9]*$ ]]',
            self.backup_script,
        )
        readiness = self.backup_script.index("MigrationReadiness::class")
        mismatch = self.backup_script.index(
            '[[ "$pending_count" == "$expected_pending" ]]'
        )
        dump_lookup = self.backup_script.index('dump_bin="$(command -v mariadb-dump')
        self.assertLess(readiness, mismatch)
        self.assertLess(mismatch, dump_lookup)

    def test_migration_script_fails_closed_before_post_on_count_mismatch(self):
        self.assertIn(
            'if [[ ! "$EXPECTED_PENDING" =~ ^[0-9]+$ ]] || (( EXPECTED_PENDING < 1 )); then',
            self.migration_script,
        )
        mismatch = self.migration_script.index(
            'if [[ "$pending_before" != "$EXPECTED_PENDING" ]]; then'
        )
        post = self.migration_script.index('curl', mismatch)
        self.assertLess(mismatch, post)

    def test_existing_authority_and_separation_guards_remain(self):
        for workflow in (self.backup, self.migration):
            self.assertIn("github.actor == github.repository_owner", workflow)
            self.assertIn(
                "github.triggering_actor == github.repository_owner",
                workflow,
            )

        self.assertIn("This workflow does not run migrations.", self.backup)
        self.assertNotIn("run-production-migrations.sh", self.backup)
        self.assertIn(
            "Verified backup evidence: **resolved and validated server-side**",
            self.migration,
        )
        self.assertNotIn("backup_receipt:", self.migration.lower())


if __name__ == "__main__":
    unittest.main()
