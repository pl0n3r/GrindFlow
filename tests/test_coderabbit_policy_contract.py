"""Contrato del consumidor para la review CodeRabbit anclada al HEAD exacto."""
from __future__ import annotations
import json
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
REVIEWER = "coderabbitai[bot]"

def substantive_review_satisfies(review: dict, *, required_bot: str, head_sha: str) -> bool:
    user = review.get("user")
    state = review.get("state")
    body = review.get("body")
    return (
        isinstance(user, dict)
        and user.get("type") == "Bot"
        and user.get("login") == required_bot
        and review.get("commit_id") == head_sha
        and state != "CHANGES_REQUESTED"
        and (state == "APPROVED" or (state == "COMMENTED" and isinstance(body, str) and bool(body.strip())))
    )

class CodeRabbitPolicyContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.policy = json.loads((ROOT / ".github/factory-policy.json").read_text(encoding="utf-8"))
        cls.workflow = (ROOT / ".github/workflows/politica.yml").read_text(encoding="utf-8")
        cls.docs = (ROOT / "docs/AGENT-OPERATIONS.md").read_text(encoding="utf-8")

    def test_factory_policy_requires_coderabbit(self) -> None:
        self.assertEqual(self.policy, {"version": 1, "required_review_bot": REVIEWER})

    def test_policy_caller_passes_same_required_bot(self) -> None:
        self.assertEqual(self.workflow.count("required_review_bot: coderabbitai[bot]"), 1)
        self.assertIn("pr_number: ${{ github.event.pull_request.number }}", self.workflow)
        self.assertIn("pl0n3r/factory/.github/workflows/politica.yml@v1", self.workflow)
        self.assertIn("pull-requests: read", self.workflow)
        self.assertNotIn("pull-requests: write", self.workflow)
        self.assertNotIn("secrets:", self.workflow)

    def test_previous_head_review_does_not_satisfy_current_head(self) -> None:
        review={"user":{"type":"Bot","login":REVIEWER},"state":"COMMENTED","body":"review completa","commit_id":"1"*40}
        self.assertFalse(substantive_review_satisfies(review, required_bot=REVIEWER, head_sha="2"*40))

    def test_exact_head_substantive_review_is_required(self) -> None:
        review={"user":{"type":"Bot","login":REVIEWER},"state":"COMMENTED","body":"review completa","commit_id":"2"*40}
        self.assertTrue(substantive_review_satisfies(review, required_bot=REVIEWER, head_sha="2"*40))

    def test_changes_requested_never_satisfies(self) -> None:
        review={"user":{"type":"Bot","login":REVIEWER},"state":"CHANGES_REQUESTED","body":"hay cambios","commit_id":"2"*40}
        self.assertFalse(substantive_review_satisfies(review, required_bot=REVIEWER, head_sha="2"*40))

    def test_rate_limit_or_status_without_review_does_not_satisfy(self) -> None:
        empty={"user":{"type":"Bot","login":REVIEWER},"state":"COMMENTED","body":"","commit_id":"2"*40}
        status={"status":"success","context":"CodeRabbit"}
        self.assertFalse(substantive_review_satisfies(empty, required_bot=REVIEWER, head_sha="2"*40))
        self.assertFalse(substantive_review_satisfies(status, required_bot=REVIEWER, head_sha="2"*40))

    def test_documented_rule_and_machine_policy_stay_aligned(self) -> None:
        for expected in (".github/factory-policy.json","coderabbitai[bot]","HEAD exacto","CHANGES_REQUESTED","rate-limit","check sin review formal"):
            with self.subTest(expected=expected):
                self.assertIn(expected, self.docs)

if __name__ == "__main__":
    unittest.main()
