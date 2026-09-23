"""Deterministic offline contracts for the exact-head CodeRabbit merge gate."""

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
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

    def test_cli_reads_fixed_local_files_only(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "status.json").write_text(json.dumps(evidence()), encoding="utf-8")
            (root / "reviews.json").write_text("[]", encoding="utf-8")
            (root / "threads.json").write_text('{"review_threads":[]}', encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=completed_for_exact_head")

    def test_cli_blocks_symlink_to_outside_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            outside = root.parent / (root.name + "-external.json")
            try:
                outside.write_text('{"review_threads":[]}', encoding="utf-8")
                (root / "status.json").write_text(json.dumps(evidence()), encoding="utf-8")
                (root / "reviews.json").write_text("[]", encoding="utf-8")
                (root / "threads.json").symlink_to(outside)
                result = subprocess.run(
                    [sys.executable, str(SCRIPT), "--head", HEAD],
                    cwd=root, text=True, capture_output=True, check=False,
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")
            finally:
                outside.unlink(missing_ok=True)

    def test_malformed_status_record_fails_closed(self):
        invalid = evidence()
        invalid["statuses"].append("not a GitHub status record")
        self.assertIn("invalid_statuses", module.verify(HEAD, invalid, [], []))

    def test_explicit_completion_accepts_terminal_punctuation_only(self):
        self.assertEqual(module.verify(HEAD, evidence(description="Review completed."), [], []), [])
        self.assertIn(
            "coderabbit_not_completed",
            module.verify(HEAD, evidence(description="Review completed but incremental review skipped"), [], []),
        )

    def test_cli_rejects_symlink_to_another_file_inside_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "status.json").write_text(json.dumps(evidence()), encoding="utf-8")
            (root / "reviews.json").write_text("[]", encoding="utf-8")
            (root / "threads-real.json").write_text('{"review_threads":[]}', encoding="utf-8")
            (root / "threads.json").symlink_to(root / "threads-real.json")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")

    def test_cli_rejects_invalid_json_without_echoing_source(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "status.json").write_text('{"secret":"do-not-echo",', encoding="utf-8")
            (root / "reviews.json").write_text("[]", encoding="utf-8")
            (root / "threads.json").write_text('{"review_threads":[]}', encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")
            self.assertNotIn("do-not-echo", result.stdout + result.stderr)

    def test_cli_rejects_oversized_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "status.json").write_text(json.dumps(evidence()), encoding="utf-8")
            (root / "reviews.json").write_text(" " * (module.MAX_EVIDENCE_BYTES + 1), encoding="utf-8")
            (root / "threads.json").write_text('{"review_threads":[]}', encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")

    def test_cli_rejects_directory_instead_of_json_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "status.json").mkdir()
            (root / "reviews.json").write_text("[]", encoding="utf-8")
            (root / "threads.json").write_text('{"review_threads":[]}', encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")

    @unittest.skipUnless(hasattr(os, "mkfifo"), "FIFO tests require POSIX")
    def test_cli_rejects_fifo_without_hanging(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            os.mkfifo(root / "status.json")
            (root / "reviews.json").write_text("[]", encoding="utf-8")
            (root / "threads.json").write_text('{"review_threads":[]}', encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False, timeout=5,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")

    def test_malformed_review_record_fails_closed(self):
        self.assertIn("missing_reviews", module.verify(HEAD, evidence(), [None], []))
        self.assertIn("missing_reviews", module.verify(HEAD, evidence(), ["opaque"], []))

    def test_cli_rejects_invalid_utf8_without_echoing_source(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "status.json").write_bytes(b"\xff\xfe")
            (root / "reviews.json").write_text("[]", encoding="utf-8")
            (root / "threads.json").write_text('{"review_threads":[]}', encoding="utf-8")
            result = subprocess.run(
                [sys.executable, str(SCRIPT), "--head", HEAD],
                cwd=root, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout.strip(), "CODERABBIT_GATE=invalid_evidence")

    def test_review_bodies_are_not_used_as_terminal_evidence(self):
        reviews = [{"commit_id": HEAD, "state": "COMMENTED", "body": "Review complete"}]
        self.assertIn("coderabbit_not_completed", module.verify(HEAD, evidence(description="Review skipped: incremental reviews are disabled"), reviews, []))


if __name__ == "__main__":
    unittest.main()
