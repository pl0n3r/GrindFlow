import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/politica.yml"
FACTORY_SHA = "b276fd6230a7f15c2c91fd7a35743e61210ee0d5"


def _factory_policy_job(content: str) -> str:
    match = re.search(
        r"(?ms)^  factory-policy:\n(?P<body>(?:(?!^  [A-Za-z0-9_-]+:\n).)*)",
        content,
    )
    if match is None:
        raise AssertionError("workflow no define el job factory-policy")
    return match.group(0)


class FactoryPolicyBridgeTests(unittest.TestCase):
    def test_policy_reusable_and_internal_checkout_use_same_immutable_factory_sha(self):
        content = WORKFLOW.read_text(encoding="utf-8")
        job = _factory_policy_job(content)
        uses = re.findall(
            r"^    uses: pl0n3r/factory/\.github/workflows/politica\.yml@([0-9a-f]{40})\s*$",
            job,
            re.MULTILINE,
        )
        factory_refs = re.findall(
            r"^      factory_ref:\s*([0-9a-f]{40})\s*$",
            job,
            re.MULTILINE,
        )
        self.assertEqual(uses, [FACTORY_SHA])
        self.assertEqual(factory_refs, uses)
        self.assertNotIn(
            "pl0n3r/factory/.github/workflows/politica.yml@v1",
            job,
        )

    def test_bridge_preserves_reviewer_triggers_and_read_only_permissions(self):
        content = WORKFLOW.read_text(encoding="utf-8")
        job = _factory_policy_job(content)
        for required in (
            "pull_request:",
            "pull_request_review:",
            "contents: read",
            "pull-requests: read",
        ):
            self.assertIn(required, content)
        self.assertRegex(
            job,
            r"(?m)^      pr_number:\s*\$\{\{ github\.event\.pull_request\.number \}\}\s*$",
        )
        reviewer = re.findall(
            r"^      required_review_bot:\s*([^\s]+)\s*$",
            job,
            re.MULTILINE,
        )
        self.assertEqual(reviewer, ["coderabbitai[bot]"])
        self.assertNotIn("contents: write", content)
        self.assertNotIn("pull-requests: write", content)