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
    items = [{"name": x, "head_sha": H, "status": "completed", "conclusion": "success",
              "app": {"slug": guard.REQUIRED_APPS[x][0], "id": guard.REQUIRED_APPS[x][1]}}
             for x in guard.REQUIRED]
    return {"switch": {"number": 767,
                       "url": "https://api.github.com/repos/pl0n3r/Factory/issues/767",
                       "repository_url": "https://api.github.com/repos/pl0n3r/Factory",
                       "state": "closed", "user": {"login": "pl0n3r"}, "body": M},
            "pr": {"state": "open", "draft": False, "mergeable": True,
                   "head": {"sha": H, "repo": {"fork": False, "owner": {"login": "pl0n3r"}, "name": "GrindFlow"}},
                   "base": {"sha": B, "ref": "main"}},
            "main_sha": B,
            "checks": {"total_count": len(items), "check_runs": items},
            "reviews": [{"user": {"login": "coderabbitai[bot]", "type": "Bot"},
                         "state": "COMMENTED", "commit_id": H, "body": "Full review finished."}],
            "status_history": [{"id": 20, "context": "CodeRabbit", "state": "success",
                                "description": "Review completed",
                                "created_at": "2026-10-14T08:00:00Z",
                                "creator": {"login": "coderabbitai[bot]", "type": "Bot"}}]}


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

    def test_check_emitter_identity_is_required(self):
        for name in guard.REQUIRED:
            for spoof in ("missing", "wrong_slug", "wrong_id", "bool_id", "null"):
                with self.subTest(check=name, spoof=spoof):
                    case = fixture()
                    row = next(c for c in case["checks"]["check_runs"] if c["name"] == name)
                    if spoof == "missing":
                        row.pop("app")
                    elif spoof == "wrong_slug":
                        row["app"]["slug"] = "different-app"
                    elif spoof == "wrong_id":
                        row["app"]["id"] = 99999999
                    elif spoof == "bool_id":
                        row["app"]["id"] = True
                    else:
                        row["app"] = None
                    self.denied(case)

    def test_coderabbit_incomplete_review_variants_denied(self):
        """An OSS bot COMMENTED status is not a substantive review if unfinished."""
        incomplete = (
            "Skipped review", "Review was skipped", "Review not performed",
            "Review was not completed", "Could not review", "Unable to review",
            "Review pending", "Review in progress", "Analysis queued",
            "Review cancelled", "Review not complete",
            "Incremental reviews are disabled",
        )
        for state in ("COMMENTED", "APPROVED"):
            for message in incomplete:
                with self.subTest(state=state, message=message):
                    case = fixture()
                    case["reviews"][0]["state"] = state
                    case["reviews"][0]["body"] = message
                    self.denied(case)
        case = fixture()
        case["reviews"][0]["body"] = "Full review finished."
        guard.verify(case, H)

    def test_coderabbit_status_history_skipped_after_review_denied(self):
        """A skipped status cannot hide behind an older completed review."""
        guard.verify(fixture(), H)
        for description, state in (
            ("Review skipped: incremental reviews are disabled", "success"),
            ("Review skipped: draft pull request", "success"),
            ("Review rate limited", "success"),
            ("Review in progress", "pending"),
        ):
            with self.subTest(description=description):
                case = fixture()
                case["status_history"].insert(0, {
                    "id": 21, "context": "CodeRabbit", "state": state,
                    "description": description,
                    "created_at": "2026-10-14T08:01:00Z",
                    "creator": {"login": "coderabbitai[bot]", "type": "Bot"},
                })
                self.denied(case)
        case = fixture()
        case["status_history"].append({
            "id": 19, "context": "CodeRabbit", "state": "success",
            "description": "Review skipped: draft pull request",
            "created_at": "2026-10-14T07:59:00Z",
            "creator": {"login": "coderabbitai[bot]", "type": "Bot"},
        })
        guard.verify(case, H)

    def test_coderabbit_status_provenance_required(self):
        for mutation in (
            lambda s: s.pop("status_history"),
            lambda s: s.update(status_history=[]),
            lambda s: s.update(status_history={}),
            lambda s: s["status_history"][0].update(context="other"),
            lambda s: s["status_history"][0]["creator"].update(login="other"),
            lambda s: s["status_history"][0]["creator"].update(type="User"),
            lambda s: s["status_history"][0].pop("creator"),
            lambda s: s["status_history"][0].update(state="pending"),
            lambda s: s["status_history"][0].update(description=""),
            lambda s: s["status_history"][0].update(created_at="not-a-time"),
            lambda s: s["status_history"][0].update(id=True),
            lambda s: s["status_history"].append(dict(s["status_history"][0])),
        ):
            case = fixture()
            mutation(case)
            self.denied(case)
        case = fixture()
        case["status_history"].insert(0, {
            "id": 22, "context": "CodeRabbit", "state": "success",
            "description": "Review completed",
            "created_at": "2026-10-14T08:02:00Z",
            "creator": {"login": "attacker", "type": "User"},
        })
        self.denied(case)
        case = fixture()
        case["status_history"].append({
            "id": 19, "context": "CodeRabbit", "state": "success",
            "description": "Review completed",
            "created_at": "2026-10-14T08:03:00Z",
            "creator": {"login": "coderabbitai[bot]", "type": "Bot"},
        })
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
                       M + M,
                       M + "<!-- factory-unattended-kill-switch malformed -->",
                       "<!-- factory-unattended-kill-switch malformed -->" + M,
                       M + "<!-- factory-unattended-kill-switch {bad} -->"):
            case = fixture()
            case["switch"]["body"] = marker
            self.denied(case)
        for change in (
            lambda s: s["switch"].update(number=768),
            lambda s: s["switch"].update(url="https://api.github.com/repos/pl0n3r/Factory/issues/768"),
            lambda s: s["switch"].update(repository_url="https://api.github.com/repos/other/Factory"),
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

    def test_second_snapshot_denies_policy_review_base_and_switch_races(self):
        """A late security veto must deny, even after a passing first snapshot."""
        import copy
        from unittest.mock import patch

        def responses(before, after):
            rows = []
            for state in (before, after):
                rows.extend([
                    copy.deepcopy(state["pr"]), copy.deepcopy(state["switch"]),
                    copy.deepcopy(state["checks"]), copy.deepcopy(state["reviews"]),
                    copy.deepcopy(state["status_history"]),
                    {"commit": {"sha": state["main_sha"]}},
                ])
            return rows

        races = {
            "policy_failed": lambda s: s["checks"]["check_runs"][-1].update(conclusion="failure"),
            "policy_pending": lambda s: s["checks"]["check_runs"][-1].update(status="in_progress", conclusion=None),
            "review_missing": lambda s: s.update(reviews=[]),
            "coderabbit_skipped": lambda s: s["status_history"][0].update(
                description="Review skipped: incremental reviews are disabled"),
            "review_veto": lambda s: s["reviews"].append({
                "user": {"login": "reviewer"}, "state": "CHANGES_REQUESTED",
                "commit_id": H, "body": "blocking review"}),
            "base_sha_changed": lambda s: s.update(main_sha=H),
            "switch_paused": lambda s: s["switch"].update(body=M.replace("RUNNING", "PAUSED")),
            "pr_head_changed": lambda s: s["pr"]["head"].update(sha=B),
        }
        env = {"GH_TOKEN": "offline-placeholder", "PR_SHA": H,
               "PR_NUMBER": "485", "GITHUB_REPOSITORY": guard.REPO}
        for name, mutation in races.items():
            with self.subTest(race=name):
                before, after = fixture(), fixture()
                mutation(after)
                with patch.dict(guard.os.environ, env, clear=True):
                    with patch.object(guard, "get", side_effect=responses(before, after)) as get_mock:
                        self.assertEqual(guard.main(), 1)
                        self.assertEqual(get_mock.call_count, 12)
        with patch.dict(guard.os.environ, env, clear=True):
            with patch.object(guard, "get", side_effect=responses(fixture(), fixture())) as get_mock:
                self.assertEqual(guard.main(), 0)
                self.assertEqual(get_mock.call_count, 12)

    def test_only_latest_decisive_review_per_reviewer_can_veto(self):
        reviewer = {"login": "security-reviewer"}
        changes = {"user": reviewer, "state": "CHANGES_REQUESTED",
                   "commit_id": H, "body": "changes needed"}
        approved = {"user": reviewer, "state": "APPROVED",
                    "commit_id": H, "body": "addressed"}
        dismissed = {"user": reviewer, "state": "DISMISSED",
                     "commit_id": H, "body": "review dismissed"}
        commented = {"user": reviewer, "state": "COMMENTED",
                     "commit_id": H, "body": "discussion"}

        case = fixture()
        case["reviews"].extend([changes, approved])
        guard.verify(case, H)
        case = fixture()
        case["reviews"].extend([approved, changes])
        self.denied(case)
        case = fixture()
        case["reviews"].extend([changes, dismissed])
        guard.verify(case, H)
        case = fixture()
        case["reviews"].extend([changes, commented])
        self.denied(case)
        case = fixture()
        case["reviews"].extend([changes, approved, changes])
        self.denied(case)
        case = fixture()
        case["reviews"].append({"user": {"login": ""}, "state": "APPROVED"})
        self.denied(case)

    def test_review_event_does_not_run_untrusted_pr_workflow(self):
        yml = (ROOT / ".github/workflows/automerge.yml").read_text(encoding="utf-8")
        self.assertIn("pull_request_target:", yml)
        self.assertNotIn("pull_request_review:", yml)
        self.assertIn("workflow_run:", yml)
        self.assertIn("persist-credentials: false", yml)
        self.assertIn("concurrency:\\n  group: automerge-${{ github.event.pull_request.number || github.event.workflow_run.pull_requests[0].number || github.sha }}\\n  cancel-in-progress: false", yml)
        self.assertIn("permissions:\n  contents: read\njobs:", yml)
        self.assertIn("permissions:\n      contents: write\n      pull-requests: write", yml)

    def test_workflow_run_rechecks_only_trusted_completed_ci_and_current_head(self):
        yml = (ROOT / ".github/workflows/automerge.yml").read_text(encoding="utf-8")
        self.assertIn('workflows: ["GrindFlow CI", "CI Factory v1", "Política Factory v1"', yml)
        self.assertIn("\n  status:\npermissions:", yml)
        self.assertIn("github.event.context == 'CodeRabbit'", yml)
        self.assertIn('elif event_name == "status":', yml)
        self.assertIn('"/commits/" + expected_sha + "/pulls?per_page=100"', yml)
        self.assertIn('deny("status_pr_mapping_ambiguous")', yml)
        for required in (
            'run.get("name") not in trusted_runs',
            'run.get("event") != "pull_request"',
            'run.get("status") != "completed"',
            'run.get("conclusion") != "success"',
            'run.get("pull_requests")',
            'head.get("sha") != expected_sha',
            'base.get("ref") != "main"',
            'deny("authenticated_pr_identity_or_head_changed")',
            "steps.target.outputs.pr_number",
            "steps.target.outputs.pr_sha",
        ):
            with self.subTest(required=required):
                self.assertIn(required, yml)
        self.assertNotIn("pull_request_review:", yml)

    def test_malformed_main_commit_response_fails_closed_without_traceback(self):
        from unittest.mock import patch

        response_rows = [
            fixture()["pr"], fixture()["switch"], fixture()["checks"],
            fixture()["reviews"], fixture()["status_history"], {"commit": []},
        ]
        with patch.object(guard, "get", side_effect=response_rows):
            snapshot = guard.read_snapshot(
                "offline-token", "/repos/pl0n3r/GrindFlow", 485, H
            )
        self.assertIsNone(snapshot["main_sha"])
        with self.assertRaises(guard.GuardError):
            guard.verify(snapshot, H)

    def test_successful_evidence_allows_only_sha_locked_auto_merge(self):
        guard.verify(fixture(), H)
        yml = (ROOT / ".github/workflows/automerge.yml").read_text(encoding="utf-8")
        self.assertIn("persist-credentials: false", yml)
        self.assertIn("ref: main", yml)
        self.assertIn("python3 scripts/automerge_pr_guard.py", yml)
        self.assertIn('gh pr merge --auto --squash --match-head-commit "$PR_SHA"', yml)
        self.assertNotIn("secrets.AUTOMERGE_TOKEN", yml)
        self.assertNotIn("--admin", yml)


if __name__ == "__main__":
    unittest.main()
