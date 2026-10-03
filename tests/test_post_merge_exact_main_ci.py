from __future__ import annotations

import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/work-coordination.yml"
PACKAGE = ROOT / "package.json"
LOCK = ROOT / "package-lock.json"
VERSION = ROOT / "config/version.php"

JOB_NAME = "  post-merge-exact-main-ci:"


class PostMergeExactMainCiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.job = cls.workflow[cls.workflow.index(JOB_NAME):]
        cls.package = json.loads(PACKAGE.read_text(encoding="utf-8"))
        cls.lock = json.loads(LOCK.read_text(encoding="utf-8"))
        cls.version_php = VERSION.read_text(encoding="utf-8")

    def test_merged_pr_dispatches_grindflow_ci_on_main(self):
        self.assertIn("types: [opened, reopened, synchronize, edited, ready_for_review, converted_to_draft, closed]", self.workflow)
        self.assertIn("github.event_name == 'pull_request'", self.job)
        self.assertIn("github.event.action == 'closed'", self.job)
        self.assertIn("github.event.pull_request.merged == true", self.job)
        self.assertIn("github.event.pull_request.base.ref == 'main'", self.job)
        self.assertIn("run: gh workflow run grindflow-ci.yml --ref main", self.job)

    def test_closed_unmerged_pr_is_excluded(self):
        condition = self.job.split("runs-on:", 1)[0]
        self.assertIn("github.event.action == 'closed'", condition)
        self.assertIn("github.event.pull_request.merged == true", condition)
        self.assertIn("github.event.pull_request.base.ref == 'main'", condition)
        self.assertNotIn("merged != false", condition)

    def test_dispatch_job_has_minimal_permissions_and_no_production_workflow(self):
        permissions = self.job.split("permissions:", 1)[1].split("steps:", 1)[0]
        permission_lines = {
            line.strip()
            for line in permissions.splitlines()
            if ":" in line and line.strip()
        }
        self.assertEqual(permission_lines, {"actions: write", "contents: read"})

        self.assertIn("GH_TOKEN: ${{ github.token }}", self.job)
        self.assertIn("GH_REPO: ${{ github.repository }}", self.job)
        self.assertNotIn("actions/checkout", self.job)

        forbidden = (
            "production-backup",
            "production-migration",
            "production-smoke",
            "production-deploy-observer",
            "deploy-factory",
            "artisan migrate",
        )
        lowered = self.job.lower()
        for token in forbidden:
            with self.subTest(token=token):
                self.assertNotIn(token, lowered)

        dispatches = re.findall(r"gh workflow run ([^\s]+)(?:\s+--ref\s+([^\s]+))?", self.job)
        self.assertEqual(dispatches, [("grindflow-ci.yml", "main")])

    def test_regression_is_wired_into_npm_test(self):
        self.assertIn(
            "python3 -m unittest tests/test_post_merge_exact_main_ci.py",
            self.package["scripts"]["test"],
        )

    def test_release_identity_matches_across_manifests(self):
        match = re.search(r"'number'\s*=>\s*'([^']+)'", self.version_php)
        self.assertIsNotNone(match)
        release = match.group(1)
        self.assertRegex(release, r"^\d+\.\d+\.\d+$")
        self.assertEqual(self.package["version"], release)
        self.assertEqual(self.lock["version"], release)
        self.assertEqual(self.lock["packages"][""]["version"], release)


if __name__ == "__main__":
    unittest.main()
