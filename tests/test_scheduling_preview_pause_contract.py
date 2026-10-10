"""Factory acceptance for the isolated Symfony scheduling preview/pause slice."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "symfony" / "tests" / "php" / "SchedulingPreviewPauseTest.php"
PREVIEW = ROOT / "symfony" / "src" / "Scheduling" / "Preview" / "BulkSchedulePreview.php"
PAUSE = ROOT / "symfony" / "src" / "Scheduling" / "Preview" / "GlobalPauseState.php"


class SchedulingPreviewPauseContractTests(unittest.TestCase):
    def scenario(self, name):
        self.assertTrue(PREVIEW.is_file())
        self.assertTrue(PAUSE.is_file())
        self.assertTrue(SCENARIOS.is_file())
        self.assertIsNotNone(shutil.which("php"), "PHP CLI is required for this contract")
        completed = subprocess.run(
            ["php", str(SCENARIOS), name], capture_output=True, text=True,
            cwd=ROOT, timeout=15, check=False,
        )
        self.assertEqual(completed.returncode, 0, "PHP scenario failed: " + name)
        result = json.loads(completed.stdout)
        self.assertEqual(result, {"case": name, "status": "pass"} if name != "preview"
                         else {"case": name, "status": "pass", "conflicts": 1})

    def test_preview_create_move_conflicts_without_mutation(self):
        self.scenario("preview")

    def test_stale_or_foreign_preview_is_rejected(self):
        self.scenario("mismatch")

    def test_pause_resume_preserves_original_schedule(self):
        self.scenario("pause")

    def test_pause_audit_is_bounded_and_contains_no_pii(self):
        self.scenario("audit")


if __name__ == "__main__":
    unittest.main()
