from __future__ import annotations

import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/work-coordination.yml"


class PostMergeExactMainCiTests(unittest.TestCase):
    def workflow(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def job_block(self) -> str:
        workflow = self.workflow()
        match = re.search(
            r"(?ms)^  ci-exact-main-post-merge:\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\\Z)",
            workflow,
        )
        self.assertIsNotNone(match, "falta job ci-exact-main-post-merge")
        return match.group(1)

    def test_merged_pr_dispatches_grindflow_ci_on_main(self):
        workflow = self.workflow()
        block = self.job_block()

        self.assertIn("pull_request:", workflow)
        self.assertRegex(workflow, r"types:\s*\[[^\]]*closed[^\]]*\]")
        self.assertIn("github.event_name == 'pull_request'", block)
        self.assertIn("github.event.action == 'closed'", block)
        self.assertIn("github.event.pull_request.merged == true", block)
        self.assertIn(
            'gh workflow run grindflow-ci.yml --repo "$GF_REPOSITORY" --ref main',
            block,
        )

    def test_closed_unmerged_pr_is_excluded(self):
        block = self.job_block()
        self.assertIn("github.event.pull_request.merged == true", block)
        self.assertNotIn("github.event.pull_request.merged != false", block)

    def test_dispatch_job_has_minimal_permissions_and_no_production_workflow(self):
        block = self.job_block()
        permissions = re.search(
            r"(?ms)^    permissions:\n(.*?)(?=^    [A-Za-z0-9_-]+:\n)",
            block,
        )
        self.assertIsNotNone(permissions)
        permission_block = permissions.group(1)

        self.assertIn("      actions: write", permission_block)
        self.assertIn("      contents: read", permission_block)
        self.assertNotIn("issues:", permission_block)
        self.assertNotIn("pull-requests:", permission_block)
        self.assertNotIn("checks:", permission_block)
        self.assertNotIn("contents: write", permission_block)

        self.assertEqual(block.count("gh workflow run "), 1)
        for forbidden in (
            "production-backup.yml",
            "production-migration.yml",
            "production-deploy-observer.yml",
            "production-smoke.yml",
            "deploy-factory.yml",
        ):
            self.assertNotIn(forbidden, block)

    def test_regression_is_wired_into_npm_test(self):
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn(
            "python3 -m unittest tests/test_post_merge_exact_main_ci.py",
            package["scripts"]["test"],
        )


if __name__ == "__main__":
    unittest.main()
