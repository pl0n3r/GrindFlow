import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/politica.yml"
FACTORY_SHA = "464a06458cfcb749161f0070a46991b780038a60"


class FactoryPolicyBridgeTests(unittest.TestCase):
    def test_policy_reusable_and_internal_checkout_use_same_immutable_factory_sha(self):
        content = WORKFLOW.read_text(encoding="utf-8")
        matches = re.findall(
            r"uses: pl0n3r/factory/\.github/workflows/politica\.yml@([0-9a-f]{40})",
            content,
        )
        self.assertEqual(matches, [FACTORY_SHA])
        self.assertEqual(content.count(f"factory_ref: {FACTORY_SHA}"), 1)
        self.assertNotIn(
            "pl0n3r/factory/.github/workflows/politica.yml@v1",
            content,
        )

    def test_bridge_preserves_reviewer_triggers_and_read_only_permissions(self):
        content = WORKFLOW.read_text(encoding="utf-8")
        for required in (
            "pull_request:",
            "pull_request_review:",
            "contents: read",
            "pull-requests: read",
            "pr_number:",
            "required_review_bot: coderabbitai[bot]",
        ):
            self.assertIn(required, content)
        self.assertNotIn("contents: write", content)
        self.assertNotIn("pull-requests: write", content)
