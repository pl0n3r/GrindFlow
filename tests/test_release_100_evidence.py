"""Regresiones sintéticas fail-closed del gate GF356; sin red ni producción."""
from __future__ import annotations

from copy import deepcopy
from datetime import datetime, timedelta, timezone
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/check_release_100_evidence.py"
SPEC = importlib.util.spec_from_file_location("check_release_100_evidence", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC is not None and SPEC.loader is not None
SPEC.loader.exec_module(MODULE)

SHA = "a" * 40
NOW = datetime(2026, 10, 27, 12, 0, tzinfo=timezone.utc)


def iso(value: datetime) -> str:
    return value.isoformat().replace("+00:00", "Z")


def fixture() -> dict:
    return {
        "contract": MODULE.CONTRACT,
        "as_of": iso(NOW),
        "candidate_sha": SHA,
        "milestones": {
            name: {
                "status": "PASS", "ref": f"ci/gf356/{name}#12345",
                "head_sha": SHA,
                "observed_at": iso(NOW - timedelta(hours=2)),
                "expires_at": iso(NOW + timedelta(days=5)),
            }
            for name in MODULE.MILESTONES
        },
    }


class Release100EvidenceTests(unittest.TestCase):
    def test_all_six_current_pass_is_only_review_ready_not_publication(self):
        outcome = MODULE.evaluate(fixture())
        self.assertEqual(outcome["go_no_go"], "GO")
        self.assertEqual(outcome["interpretation"], "READY_FOR_OWNER_REVIEW")
        self.assertEqual(set(outcome["milestones"]), set(MODULE.MILESTONES))
        self.assertIs(outcome["publication_allowed"], False)
        self.assertIs(outcome["production_verified"], False)
        self.assertIs(outcome["owner_decision_required"], True)

    def test_missing_milestone_is_no_go(self):
        data = fixture()
        del data["milestones"]["pilot"]
        outcome = MODULE.evaluate(data)
        self.assertEqual(outcome["go_no_go"], "NO_GO")
        self.assertEqual(outcome["milestones"]["pilot"]["status"], "UNKNOWN")

    def test_expired_evidence_is_stale_no_go(self):
        data = fixture()
        data["milestones"]["recovery"]["expires_at"] = iso(NOW)
        outcome = MODULE.evaluate(data)
        self.assertEqual(outcome["go_no_go"], "NO_GO")
        self.assertEqual(outcome["milestones"]["recovery"]["freshness"], "STALE")

    def test_evidence_older_than_30_days_is_stale_even_with_future_expiry(self):
        data = fixture()
        data["milestones"]["security"]["observed_at"] = iso(NOW - timedelta(days=31))
        self.assertEqual(MODULE.evaluate(data)["milestones"]["security"]["freshness"], "STALE")

    def test_failed_or_unknown_milestone_blocks_go(self):
        for status in ("FAIL", "UNKNOWN"):
            with self.subTest(status=status):
                data = fixture()
                data["milestones"]["mvp"]["status"] = status
                self.assertEqual(MODULE.evaluate(data)["go_no_go"], "NO_GO")

    def test_future_observation_fails_closed(self):
        data = fixture()
        data["milestones"]["pilot"]["observed_at"] = iso(NOW + timedelta(minutes=1))
        self.assertEqual(MODULE.evaluate(data)["milestones"]["pilot"]["reason"], "future_observation")

    def test_candidate_sha_drift_fails_closed(self):
        data = fixture()
        data["milestones"]["observability"]["head_sha"] = "b" * 40
        outcome = MODULE.evaluate(data)
        self.assertEqual(outcome["go_no_go"], "NO_GO")
        self.assertEqual(outcome["milestones"]["observability"]["reason"], "different_candidate_sha")

    def test_reusing_reference_between_two_milestones_fails_closed(self):
        data = fixture()
        data["milestones"]["pilot"]["ref"] = data["milestones"]["mvp"]["ref"]
        report = MODULE.evaluate(data)
        self.assertEqual(report["go_no_go"], "NO_GO")
        self.assertEqual(report["milestones"]["mvp"]["reason"], "shared_evidence_reference")
        self.assertEqual(report["milestones"]["pilot"]["reason"], "shared_evidence_reference")

    def test_invalid_time_zone_timestamp_and_reference_are_no_go(self):
        for field, invalid in (("observed_at", "2026-10-27T10:00:00"),
                               ("expires_at", "not-a-time"), ("ref", "https://ok\nmalicious"),
                               ("status", True), ("head_sha", "b")):
            with self.subTest(field=field):
                data = fixture()
                data["milestones"]["readiness"][field] = invalid
                self.assertEqual(MODULE.evaluate(data)["go_no_go"], "NO_GO")

    def test_wrong_root_contract_or_unknown_milestone_rejected(self):
        for mutation in (lambda d: d.update(contract="unknown"),
                         lambda d: d["milestones"].update(unreviewed={}),
                         lambda d: d.update(as_of="2026-10-27"),
                         lambda d: d.update(candidate_sha="unknown")):
            data = fixture()
            mutation(data)
            with self.assertRaises(ValueError):
                MODULE.evaluate(data)

    def test_cli_reads_stdin_and_outputs_safe_json(self):
        proc = subprocess.run([sys.executable, str(SCRIPT)],
                              input=json.dumps(fixture()), text=True,
                              capture_output=True, check=False)
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertEqual(json.loads(proc.stdout)["go_no_go"], "GO")

    def test_cli_rejects_duplicate_json_keys(self):
        proc = subprocess.run([sys.executable, str(SCRIPT)],
                              input='{"contract":"a","contract":"b"}', text=True,
                              capture_output=True, check=False)
        self.assertEqual(proc.returncode, 2)
        self.assertNotIn("{", proc.stdout)

    def test_missing_evidence_does_not_mutate_source(self):
        data = fixture()
        old = deepcopy(data)
        MODULE.evaluate(data)
        self.assertEqual(data, old)


if __name__ == "__main__":
    unittest.main()
