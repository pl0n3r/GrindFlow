"""Deterministic offline contracts for the exact-head CodeRabbit merge gate."""

import importlib.util
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "coderabbit-final-review.py"
spec = importlib.util.spec_from_file_location("coderabbit_final_review", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

HEAD = "a" * 40


def evidence(state="success", description="Review completed", sha=HEAD):
    return {
        "sha": sha,
        "statuses": [
            {"context": "CodeRabbit", "state": state, "description": description},
        ],
    }


class ReviewGateTest(unittest.TestCase):
    def test_completed_review_with_resolved_threads_passes(self):
        self.assertEqual(module.verify(HEAD, evidence(), [], [{"is_resolved": True}]), [])

    def test_skipped_success_is_not_a_completed_review(self):
        self.assertIn("coderabbit_not_completed", module.verify(HEAD, evidence(description="Review skipped: incremental reviews are disabled"), [], []))

    def test_processing_and_missing_status_fail_closed(self):
        self.assertIn("coderabbit_not_completed", module.verify(HEAD, evidence(state="pending", description="Currently processing"), [], []))
        self.assertIn("missing_coderabbit_status", module.verify(HEAD, {"sha": HEAD, "statuses": []}, [], []))

    def test_later_skipped_overrides_earlier_completed(self):
        status = evidence(description="Review skipped: incremental reviews are disabled")
        status["statuses"].append({"context": "CodeRabbit", "state": "success", "description": "Review completed"})
        self.assertIn("coderabbit_not_completed", module.verify(HEAD, status, [], []))

    def test_sha_and_shape_fail_closed(self):
        self.assertEqual(module.verify("not-sha", evidence(), [], []), ["invalid_head_sha"])
        self.assertEqual(module.verify(HEAD, evidence(sha="b" * 40), [], []), ["status_sha_mismatch"])
        self.assertIn("missing_reviews", module.verify(HEAD, evidence(), {}, []))
        self.assertIn("missing_review_threads", module.verify(HEAD, evidence(), [], {}))

    def test_unresolved_or_unknown_threads_block(self):
        self.assertIn("unresolved_review_threads", module.verify(HEAD, evidence(), [], [{"is_resolved": False}]))
        self.assertIn("unresolved_review_threads", module.verify(HEAD, evidence(), [], [{"id": 1}]))

    def test_changes_requested_on_final_head_blocks(self):
        reviews = [{"commit_id": HEAD, "state": "CHANGES_REQUESTED"}]
        self.assertIn("changes_requested_on_head", module.verify(HEAD, evidence(), reviews, []))
        reviews[0]["commit_id"] = "b" * 40
        self.assertEqual(module.verify(HEAD, evidence(), reviews, []), [])

    def test_review_bodies_are_not_used_as_terminal_evidence(self):
        reviews = [{"commit_id": HEAD, "state": "COMMENTED", "body": "Review complete"}]
        self.assertIn("coderabbit_not_completed", module.verify(HEAD, evidence(description="Review skipped: incremental reviews are disabled"), reviews, []))


if __name__ == "__main__":
    unittest.main()
