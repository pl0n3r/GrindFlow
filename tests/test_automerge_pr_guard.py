"""Synthetic acceptance for guard. All tests have zero network calls."""
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD = importlib.util.spec_from_file_location("automerge_guard", ROOT / "scripts/automerge_pr_guard.py")
assert MOD and MOD.loader
guard = importlib.util.module_from_spec(MOD)
MOD.loader.exec_module(guard)
H, B = "a" * 40, "b" * 40
M = '<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'


def fixture():
    items = [{"name": x, "head_sha": H, "status": "completed", "conclusion": "success"}
             for x in guard.REQUIRED]
    return {"switch": {"state": "closed", "user": {"login": "pl0n3r"}, "body": M},
            "pr": {"state": "open", "draft": False, "mergeable": True,
                   "head": {"sha": H, "repo": {"fork": False, "owner": {"login": "pl0n3r"}, "name": "GrindFlow"}},
                   "base": {"sha": B, "ref": "main"}},
            "main_sha": B,
            "checks": {"total_count": len(items), "check_runs": items},
            "reviews": [{"user": {"login": "coderabbitai[bot]", "type": "Bot"},
                         "state": "COMMENTED", "commit_id": H, "body": "Full review finished."}]}


class AutoMergePrGuardTests(unittest.TestCase):
    def denied(self, sample):
        with self.assertRaises(guard.GuardError):
            guard.verify(sample, H)

    def test_failed_pending_or_missing_policy_denied(self):
        for result in ("failure", "pending", "skipped", None):
            case = fixture()
            check = case["checks"]["check_runs"][-1]
            check["status"] = "in_progress" if result == "pending" else "completed"
            check["conclusion"] = result
            self.denied(case)
        case = fixture()
        case["checks"]["check_runs"].pop()
        case["checks"]["total_count"] -= 1
        self.denied(case)
        case = fixture()
        case["checks"]["total_count"] = 100
        self.denied(case)

    def test_coderabbit_skipped_and_changes_requested_denied(self):
        for body in ("Review skipped: manual review required", "skip review", "rate limit", ""):
            case = fixture()
            case["reviews"][0]["body"] = body
            self.denied(case)
        case = fixture()
        case["reviews"] = []
        self.denied(case)
        case = fixture()
        case["reviews"][0]["commit_id"] = B
        self.denied(case)
        case = fixture()
        case["reviews"].append({"user": {"login": "other"}, "state": "CHANGES_REQUESTED",
                                "commit_id": B, "body": "veto"})
        self.denied(case)

    def test_pause_malformed_fork_sha_and_incomplete_evidence_denied(self):
        for marker in (M.replace("RUNNING", "PAUSED"),
                       M.replace('"version":1', '"version":true'),
                       M.replace('"version":1', '"version":1,"version":1'),
                       M + M):
            case = fixture()
            case["switch"]["body"] = marker
            self.denied(case)
        for change in (
            lambda s: s["pr"]["head"]["repo"].update(fork=True),
            lambda s: s["pr"].update(draft=True),
            lambda s: s["pr"]["head"].update(sha=B),
            lambda s: s.update(main_sha=H),
            lambda s: s.pop("reviews"),
        ):
            case = fixture()
            change(case)
            self.denied(case)
        with self.assertRaises(guard.GuardError):
            json.loads('{"a":1,"a":2}', object_pairs_hook=guard.unique)

    def test_successful_evidence_allows_only_sha_locked_auto_merge(self):
        guard.verify(fixture(), H)
        yml = (ROOT / ".github/workflows/automerge.yml").read_text(encoding="utf-8")
        self.assertIn("persist-credentials: false", yml)
        self.assertIn("pull_request.base.sha", yml)
        self.assertIn("python3 scripts/automerge_pr_guard.py", yml)
        self.assertIn('gh pr merge --auto --squash --match-head-commit "$PR_SHA"', yml)
        self.assertNotIn("secrets.AUTOMERGE_TOKEN", yml)
        self.assertNotIn("--admin", yml)


if __name__ == "__main__":
    unittest.main()
