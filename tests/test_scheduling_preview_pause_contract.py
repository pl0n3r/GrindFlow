"""Executable contract for offline scheduling preview and pause."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "symfony/tests/php/SchedulingPreviewPauseTest.php"


class SchedulingPreviewPauseContractTests(unittest.TestCase):
    def scenario(self, name):
        result = subprocess.run(["php", str(SCRIPT), name], cwd=ROOT, timeout=15,
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        expected = {"case": name, "status": "pass", **({"conflicts": 2} if name == "preview" else {})}
        self.assertEqual(json.loads(result.stdout), expected)

    def test_preview_create_move_conflicts_without_mutation(self): self.scenario("preview")

    def test_stale_or_foreign_preview_is_rejected(self): self.scenario("mismatch")

    def test_pause_resume_preserves_original_schedule(self): self.scenario("pause")

    def test_pause_audit_is_bounded_and_contains_no_pii(self): self.scenario("audit")
