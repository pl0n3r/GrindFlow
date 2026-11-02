"""#826: execute the actual production-smoke closure gate, offline.

The gh command is a local recorder; no GitHub or B2 endpoint is contacted.
These synthetic cases never certify production MEDIA_STORAGE_READY.
"""
from __future__ import annotations

import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-smoke.yml"
STEP = "      - name: Report media storage readiness\n"
NEXT = "      - name: Reconcile media web-runtime readiness\n"

def step_script() -> str:
    content = WORKFLOW.read_text(encoding="utf-8")
    if content.count(STEP) != 1 or content.count(NEXT) != 1:
        raise AssertionError("Expected media closure step not unique")
    section = content.split(STEP, 1)[1].split(NEXT, 1)[0]
    body = section.split("        run: |\n", 1)[1]
    return "\n".join(
        line[10:] if line.startswith("          ") else line
        for line in body.splitlines()
    ) + "\n"

class ProductionMediaClosureGuardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.script = step_script()
        check = subprocess.run(
            ["bash", "-n"], input=cls.script, text=True, capture_output=True,
            check=False,
        )
        if check.returncode != 0:
            raise AssertionError(f"Workflow bash invalid: {check.stderr}")

    def run_gate(
        self, markers: str, *,
        outcome: str = "", verified: str = "",
        existing: bool = True,
    ) -> tuple[list[str], str, str]:
        with tempfile.TemporaryDirectory(prefix="gf826-guard-") as name:
            folder = Path(name)
            binary = folder / "bin"
            binary.mkdir()
            fake = binary / "gh"
            fake.write_text(
                "#!/usr/bin/env bash\n"
                'printf "%s\\n" "$*" >> "$GH_MOCK_RECORD"\n'
                'if [[ "$1" == issue && "$2" == list && "$GH_MOCK_EXISTS" == 1 ]]; then\n'
                '  printf "146\\n"\n'
                "fi\n", encoding="utf-8",
            )
            fake.chmod(0o700)
            (folder / "production-smoke.log").write_text(markers, encoding="utf-8")
            script = folder / "gate.sh"
            script.write_text(self.script, encoding="utf-8")
            record = folder / "record"
            summary = folder / "summary"
            env = {
                **os.environ,
                "PATH": str(binary) + os.pathsep + os.environ.get("PATH", ""),
                "GH_MOCK_RECORD": str(record),
                "GH_MOCK_EXISTS": "1" if existing else "0",
                "MEDIA_STORAGE_ISSUE_TITLE": "[AUTO] Media Storage Not Configured",
                "MEDIA_LIVE_PROBE_OUTCOME": outcome,
                "MEDIA_LIVE_PROBE_VERIFIED": verified,
                "GITHUB_STEP_SUMMARY": str(summary),
                "GITHUB_SHA": "a" * 40,
                "BASE_URL": "https://example.test",
            }
            result = subprocess.run(
                ["bash", str(script)], cwd=folder, env=env,
                capture_output=True, text=True, check=False, timeout=8,
            )
            self.assertEqual(result.returncode, 0, result.stderr[-1000:])
            self.assertEqual(result.stderr, "")
            commands = record.read_text().splitlines() if record.exists() else []
            return commands, summary.read_text() if summary.exists() else "", result.stdout

    @staticmethod
    def mutating(commands: list[str]) -> list[str]:
        return [c for c in commands if c.startswith(("issue close", "issue comment", "issue create"))]

    def test_config_alone_must_not_close(self):
        cmds, summary, _ = self.run_gate("MEDIA_STORAGE_READY=1\n")
        self.assertEqual(self.mutating(cmds), [])
        self.assertIn("independent live verification pending", summary)

    def test_authenticated_probe_required_to_close(self):
        cmds, summary, _ = self.run_gate(
            "MEDIA_STORAGE_READY=1\n", outcome="success", verified="true",
        )
        self.assertEqual(len([c for c in cmds if c.startswith("issue close")]), 1)
        self.assertEqual(len([c for c in cmds if c.startswith("issue comment")]), 1)
        self.assertIn("configured", summary)

    def test_empty_probe_output_fails_closed(self):
        self.assertFalse(self.mutating(self.run_gate("MEDIA_STORAGE_READY=1\n", outcome="success")[0]))

    def test_not_ready_preserves_quick_upload_notice(self):
        cmds, summary, _ = self.run_gate("MEDIA_STORAGE_READY=0\n")
        self.assertEqual(len([c for c in cmds if c.startswith("issue comment")]), 1)
        self.assertEqual(len([c for c in cmds if c.startswith("issue close")]), 0)
        self.assertIn("Quick upload remains available", summary)

    def test_not_ready_creates_notice_only_when_absent(self):
        cmds, summary, _ = self.run_gate("MEDIA_STORAGE_READY=0\n", existing=False)
        self.assertEqual(len([c for c in cmds if c.startswith("issue create")]), 1)
        self.assertIn("Quick upload remains available", summary)

    def test_verified_ready_without_open_notice_does_not_create(self):
        cmds, _, _ = self.run_gate(
            "MEDIA_STORAGE_READY=1\n", outcome="success", verified="true", existing=False,
        )
        self.assertEqual(self.mutating(cmds), [])

    def test_empty_log_is_ambiguous(self):
        cmds, summary, _ = self.run_gate("")
        self.assertFalse(self.mutating(cmds))
        self.assertIn("ambiguous", summary)

    def test_duplicate_ready_is_ambiguous(self):
        cmds, summary, _ = self.run_gate(
            "MEDIA_STORAGE_READY=1\nMEDIA_STORAGE_READY=1\n",
            outcome="success", verified="true",
        )
        self.assertFalse(self.mutating(cmds))
        self.assertIn("ambiguous", summary)

    def test_conflicting_zero_is_ambiguous(self):
        cmds, summary, _ = self.run_gate(
            "MEDIA_STORAGE_READY=1\nMEDIA_STORAGE_READY=0\n",
            outcome="success", verified="true",
        )
        self.assertFalse(self.mutating(cmds))
        self.assertIn("ambiguous", summary)

    def test_conflicting_unknown_is_ambiguous(self):
        cmds, _, _ = self.run_gate(
            "MEDIA_STORAGE_READY=1\nMEDIA_STORAGE_READY=UNKNOWN\n",
            outcome="success", verified="true",
        )
        self.assertFalse(self.mutating(cmds))

    def test_embedded_contradictory_marker_blocks_closure(self):
        cmds, summary, _ = self.run_gate(
            "MEDIA_STORAGE_READY=1\nWARNING MEDIA_STORAGE_READY=0\n",
            outcome="success", verified="true",
        )
        self.assertFalse(self.mutating(cmds))
        self.assertIn("ambiguous", summary)

    def test_duplicate_not_ready_is_ambiguous(self):
        cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=0\nMEDIA_STORAGE_READY=0\n")
        self.assertFalse(self.mutating(cmds))

    def test_unrecognized_marker_is_ambiguous(self):
        cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=UNKNOWN\n")
        self.assertFalse(self.mutating(cmds))

    def test_partial_prefix_ignored(self):
        cmds, _, _ = self.run_gate("prefix MEDIA_STORAGE_READY=1\n", outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))

    def test_suffix_cannot_close(self):
        cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=1 extra\n", outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))

    def test_leading_space_cannot_close(self):
        cmds, _, _ = self.run_gate(" MEDIA_STORAGE_READY=1\n", outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))

    def test_trailing_space_cannot_close(self):
        cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=1 \n", outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))

    def test_case_changed_cannot_close(self):
        cmds, _, _ = self.run_gate("media_storage_ready=1\n", outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))

    def test_crlf_cannot_close(self):
        cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=1\r\n", outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))

    def test_bad_outcome_cannot_close(self):
        for outcome in ["", "Success", "success ", "failure", "skipped"]:
            with self.subTest(outcome=outcome):
                cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=1\n", outcome=outcome, verified="true")
                self.assertFalse(self.mutating(cmds))

    def test_bad_verified_cannot_close(self):
        for verified in ["", "True", "true ", "false", "1", "UNKNOWN"]:
            with self.subTest(verified=verified):
                cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=1\n", outcome="success", verified=verified)
                self.assertFalse(self.mutating(cmds))

    def test_shell_injection_is_not_executed(self):
        with tempfile.TemporaryDirectory(prefix="gf826-injection-") as temp:
            sentinel = Path(temp) / "injected"
            injected = f'true"; touch "{sentinel}"; #'
            cmds, _, _ = self.run_gate("MEDIA_STORAGE_READY=1\n", outcome="success", verified=injected)
            self.assertFalse(self.mutating(cmds))
            self.assertFalse(sentinel.exists())

    def test_unrelated_log_lines_do_not_make_duplicate(self):
        cmds, summary, _ = self.run_gate(
            "some other smoke log\nMEDIA_STORAGE_READY=1\nend\n",
            outcome="success", verified="true",
        )
        self.assertEqual(len([c for c in cmds if c.startswith("issue close")]), 1)
        self.assertIn("configured", summary)

    def test_exactness_and_no_untrusted_expression_in_bash(self):
        self.assertNotIn("eval ", self.script)
        self.assertNotIn("grep -Fq", self.script)
        self.assertNotIn("$" + "{{", self.script)
        self.assertIn('"$MEDIA_LIVE_PROBE_OUTCOME" == "success"', self.script)
        self.assertIn('"$MEDIA_LIVE_PROBE_VERIFIED" == "true"', self.script)
        self.assertIn("grep -Fxc", self.script)

    def test_original_unguarded_grep_would_have_false_positive(self):
        fragment = "the string MEDIA_STORAGE_READY=1 appears in a note\n"
        self.assertIn("MEDIA_STORAGE_READY=1", fragment)
        cmds, _, _ = self.run_gate(fragment, outcome="success", verified="true")
        self.assertFalse(self.mutating(cmds))


if __name__ == "__main__":
    unittest.main()
