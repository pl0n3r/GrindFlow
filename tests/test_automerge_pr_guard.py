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
    for index, item in enumerate(items):
        item.update(id=105 - index, started_at="2026-10-14T08:00:00Z",
                    completed_at="2026-10-14T08:00:30Z")
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
                                "creator": {"login": "coderabbitai[bot]", "type": "Bot"}}],
            "review_threads": []}


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

    def test_repeated_check_runs_use_latest_authentic_attempt(self):
        """A newer green policy may supersede older failures, never vice versa."""
        from copy import deepcopy

        def retry(sample, status="completed", conclusion="success"):
            previous = sample["checks"]["check_runs"][-1]
            previous["conclusion"] = "failure"
            newer = deepcopy(previous)
            newer.update(id=106, started_at="2026-10-14T08:01:00Z",
                         completed_at="2026-10-14T08:01:30Z",
                         status=status, conclusion=conclusion)
            sample["checks"]["check_runs"].insert(0, newer)
            sample["checks"]["total_count"] += 1
            return newer, previous

        case = fixture()
        retry(case)
        guard.verify(case, H)

        for status, conclusion in (("completed", "failure"),
                                   ("in_progress", None), ("completed", "skipped")):
            with self.subTest(status=status, conclusion=conclusion):
                case = fixture()
                newer, _ = retry(case, status, conclusion)
                if status != "completed":
                    newer["completed_at"] = None
                self.denied(case)

        for mutation in (
            lambda n, o: n["app"].update(slug="attacker"),
            lambda n, o: o["app"].update(id=99999),
            lambda n, o: n.update(id=o["id"]),
            lambda n, o: n.update(id=True),
            lambda n, o: n.update(started_at="not-a-date"),
            lambda n, o: n.update(completed_at="2026-10-14T07:00:00Z"),
        ):
            case = fixture()
            new, old = retry(case)
            mutation(new, old)
            self.denied(case)

        case = fixture()
        retry(case)
        case["checks"]["check_runs"].reverse()
        guard.verify(case, H)

        # Repeated nonrequired jobs do not falsely veto independent green gates.
        case = fixture()
        for job_id, outcome in ((107, "failure"), (106, "success")):
            case["checks"]["check_runs"].insert(0, {
                "id": job_id, "name": "informational", "head_sha": H,
                "status": "completed", "conclusion": outcome,
                "started_at": "2026-10-14T08:01:00Z",
                "completed_at": "2026-10-14T08:01:30Z",
                "app": {"slug": "github-actions", "id": 15368},
            })
        # Ordering of unrelated job results is not guaranteed by GitHub.
        case["checks"]["check_runs"][:2] = reversed(case["checks"]["check_runs"][:2])
        case["checks"]["total_count"] += 2
        guard.verify(case, H)

    def test_unsorted_checks_latest_failure_and_id_collision(self):
        from copy import deepcopy
        sample = fixture()
        newest = deepcopy(sample["checks"]["check_runs"][-1])
        newest.update(id=106, conclusion="failure")
        sample["checks"]["check_runs"].append(newest)
        sample["checks"]["total_count"] += 1
        self.denied(sample)
        newest["conclusion"] = "success"
        guard.verify(sample, H)
        sample["checks"]["check_runs"][0]["id"] = 106
        self.denied(sample)

    def test_coderabbit_findings_and_threads_veto(self):
        sample = fixture()
        sample["reviews"][0]["body"] = "Actionable comments posted: 2"
        self.denied(sample)
        sample["reviews"][0]["body"] = "Actionable comments posted: 0"
        guard.verify(sample, H)
        sample["review_threads"] = [{"isResolved": False, "author_login": "coderabbitai"}]
        self.denied(sample)
        sample["review_threads"][0]["isResolved"] = True
        guard.verify(sample, H)
        sample["review_threads"][0]["author_login"] = ""
        self.denied(sample)
        del sample["review_threads"]
        self.denied(sample)

    def test_coderabbit_real_markdown_findings_fail_closed(self):
        """Read real Markdown counts; never interpret malformed labels as clean."""
        denied_bodies = (
            "**Actionable comments posted: 2**",
            "> **Actionable comments posted: 1**\n\nAdditional detail",
            "Review summary\n**Actionable comments posted: 3**\n\nNext steps",
            "Actionable comments posted: **2**",
            "Actionable comments posted: 0\nActionable comments posted: 1",
            "Actionable comments posted: 1\nActionable comments posted: 0",
            "Resumen\nActionable comments posted:",
            "Actionable comments posted: unknown",
            "**Actionable comments posted:**",
            "Actionable comments posted: 0\nActionable comments posted: ???",
            "Actionable comments posted: 0.5",
            "Actionable comments posted: 2abc",
        )
        for body in denied_bodies:
            with self.subTest(denied=body):
                case = fixture()
                case["reviews"][0]["body"] = body
                self.denied(case)
        for body in (
            "**Actionable comments posted: 0**",
            "Summary\n> **Actionable comments posted: 0**\n\nNo issues",
            "Actionable comments posted: **0**",
            "Actionable comments posted: 0\nActionable comments posted: 0",
        ):
            with self.subTest(allowed=body):
                case = fixture()
                case["reviews"][0]["body"] = body
                guard.verify(case, H)

    def test_incomplete_review_thread_graphql_denied(self):
        def payload(nodes=None, more=False):
            if nodes is None:
                nodes = [{"isResolved": False, "comments": {
                    "nodes": [{"author": {"login": "coderabbitai"}}]}}]
            return {"data": {"repository": {"pullRequest": {"reviewThreads": {
                "nodes": nodes, "pageInfo": {"hasNextPage": more}}}}}}
        self.assertEqual(guard.parse_review_threads(payload()),
                         [{"isResolved": False, "author_login": "coderabbitai"}])
        for invalid in (payload(more=True), payload(nodes=[{"isResolved": False}]),
                        payload(nodes=[{"isResolved": "false", "comments": {
                            "nodes": [{"author": {"login": "coderabbitai"}}]}}]),
                        {"errors": [{"message": "rate limited"}]}, {}):
            with self.subTest(payload=repr(invalid)):
                with self.assertRaises(guard.GuardError):
                    guard.parse_review_threads(invalid)

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
            "review_thread_open": lambda s: s["review_threads"].append(
                {"isResolved": False, "author_login": "coderabbitai"}),
        }
        env = {"GH_TOKEN": "offline-placeholder", "PR_SHA": H,
               "PR_NUMBER": "485", "GITHUB_REPOSITORY": guard.REPO}
        for name, mutation in races.items():
            with self.subTest(race=name):
                before, after = fixture(), fixture()
                mutation(after)
                with patch.dict(guard.os.environ, env, clear=True):
                    with (patch.object(guard, "get", side_effect=responses(before, after)) as get_mock,
                          patch.object(guard, "get_threads", side_effect=[before["review_threads"], after["review_threads"]])):
                        self.assertEqual(guard.main(), 1)
                        self.assertEqual(get_mock.call_count, 12)
        with patch.dict(guard.os.environ, env, clear=True):
            with (patch.object(guard, "get", side_effect=responses(fixture(), fixture())) as get_mock,
                  patch.object(guard, "get_threads", side_effect=[[], []])):
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
        self.assertIn("    concurrency:\n      group: automerge-${{ github.event.pull_request.head.sha || github.event.workflow_run.head_sha || github.event.sha || github.sha }}\n      cancel-in-progress: false", yml)
        self.assertNotIn("\nconcurrency:\n  group: automerge-", yml)
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

        malformed = (
            None, [], {}, {"commit": None}, {"commit": []}, {"commit": {}},
            {"commit": {"sha": None}}, {"commit": {"sha": "invalid"}},
        )
        for branch in malformed:
            with self.subTest(branch=repr(branch)):
                sample = fixture()
                responses = [
                    sample["pr"], sample["switch"], sample["checks"],
                    sample["reviews"], sample["status_history"], branch,
                ]
                with (patch.object(guard, "get", side_effect=responses),
                      patch.object(guard, "get_threads", return_value=[])):
                    snapshot = guard.read_snapshot(
                        "offline-token", "/repos/pl0n3r/GrindFlow", 485, H
                    )
                self.assertIsNone(snapshot["main_sha"])
                with self.assertRaises(guard.GuardError):
                    guard.verify(snapshot, H)

        sample = fixture()
        responses = [
            sample["pr"], sample["switch"], sample["checks"],
            sample["reviews"], sample["status_history"], {"commit": {"sha": B}},
        ]
        with (patch.object(guard, "get", side_effect=responses),
              patch.object(guard, "get_threads", return_value=[])):
            snapshot = guard.read_snapshot(
                "offline-token", "/repos/pl0n3r/GrindFlow", 485, H
            )
        self.assertEqual(snapshot["main_sha"], B)
        guard.verify(snapshot, H)


    def test_exact_main_ci_requires_matching_terminal_green(self):
        """A queued/missing/failed exact-main run is never proof of validation."""
        run = {"id": 42, "name": "GrindFlow CI", "head_sha": H,
               "head_branch": "main", "event": "workflow_dispatch",
               "status": "completed", "conclusion": "success"}
        good = {"total_count": 1, "workflow_runs": [run]}
        self.assertEqual(guard.verify_exact_main_ci_runs(good, H), "validated")
        self.assertEqual(guard.verify_exact_main_ci_runs(
            {"total_count": 0, "workflow_runs": []}, H), "missing")
        for mutation in (
            lambda row: row.update(head_sha=B),
            lambda row: row.update(head_branch="trabajo/issue-482"),
            lambda row: row.update(event="pull_request"),
            lambda row: row.update(name="untrusted workflow"),
        ):
            sample = dict(run)
            mutation(sample)
            self.assertEqual(guard.verify_exact_main_ci_runs(
                {"total_count": 1, "workflow_runs": [sample]}, H), "missing")
        for status in ("queued", "in_progress", "requested"):
            sample = dict(run, status=status, conclusion=None)
            self.assertEqual(guard.verify_exact_main_ci_runs(
                {"total_count": 1, "workflow_runs": [sample]}, H), "pending")
        for conclusion in ("failure", "cancelled", "skipped", None):
            sample = dict(run, conclusion=conclusion)
            with self.assertRaises(guard.GuardError):
                guard.verify_exact_main_ci_runs(
                    {"total_count": 1, "workflow_runs": [sample]}, H)
        with self.assertRaises(guard.GuardError):
            guard.verify_exact_main_ci_runs({"total_count": 2, "workflow_runs": [run]}, H)
        with self.assertRaises(guard.GuardError):
            guard.verify_exact_main_ci_runs(
                {"total_count": 2, "workflow_runs": [run, dict(run)]}, H)
        older = dict(run, id=41)
        newer_failed = dict(run, id=43, conclusion="failure")
        with self.assertRaises(guard.GuardError):
            guard.verify_exact_main_ci_runs(
                {"total_count": 2, "workflow_runs": [older, newer_failed]}, H)
        self.assertEqual(guard.verify_exact_main_ci_runs(
            {"total_count": 2, "workflow_runs": [
                dict(older, conclusion="failure"), run]}, H), "validated")

    def test_scheduled_postmerge_exact_main_audit_is_independent(self):
        yml = (ROOT / ".github/workflows/automerge.yml").read_text(encoding="utf-8")
        self.assertIn("schedule:", yml)
        self.assertIn("github.event_name == 'schedule'", yml)
        self.assertIn("actions: write", yml)
        self.assertIn("python3 scripts/automerge_pr_guard.py --audit-main", yml)
        self.assertIn("gh workflow run grindflow-ci.yml --ref main", yml)
        self.assertIn("EXACT_MAIN_CI_NOT_VALIDATED", (ROOT / "scripts/automerge_pr_guard.py").read_text(encoding="utf-8"))
        self.assertIn("exit 1", yml)
        self.assertNotIn("secrets.AUTOMERGE_TOKEN", yml)
        self.assertNotIn("--admin", yml)

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
