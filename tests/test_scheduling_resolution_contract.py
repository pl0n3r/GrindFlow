"""AC-01..04 del módulo puro Scheduling/Resolution (PHP real, sin red ni DB)."""

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_SCENARIOS = ROOT / "symfony" / "tests" / "php" / "SchedulingResolutionTest.php"
SOURCE_PATHS = (
    ROOT / "symfony" / "src" / "Scheduling" / "Resolution" / "FlexibleWindow.php",
    ROOT / "symfony" / "src" / "Scheduling" / "Resolution" / "PriorityConflictResolver.php",
    ROOT / "symfony" / "src" / "Scheduling" / "Resolution" / "MinimalReschedulePlanner.php",
)


class SchedulingResolutionContractTests(unittest.TestCase):
    def run_scenario(self, criterion: str) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required for executable acceptance")
        for source in SOURCE_PATHS:
            self.assertTrue(source.is_file(), f"missing source: {source.name}")
        self.assertTrue(PHP_SCENARIOS.is_file())
        result = subprocess.run(
            [php, str(PHP_SCENARIOS), criterion],
            capture_output=True, text=True, timeout=10, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr[:600])
        self.assertEqual(result.stdout, f"{criterion} PASS\n")
        self.assertNotIn("private_token", result.stdout + result.stderr)

    def test_higher_priority_wins_collision(self):
        self.run_scenario("AC-01")

    def test_bounded_window_picks_utc_slot(self):
        self.run_scenario("AC-02")

    def test_planner_moves_only_colliding_items(self):
        self.run_scenario("AC-03")

    def test_same_input_is_deterministic_and_invalid_fails_closed(self):
        self.run_scenario("AC-04")


if __name__ == "__main__":
    unittest.main()
