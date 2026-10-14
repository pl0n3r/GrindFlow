"""Offline acceptance for Studio scheduling rules; executes real PHP code."""

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "symfony/tests/php/SchedulingStudioTest.php"


class SchedulingStudioContractTests(unittest.TestCase):
    def run_php(self, case: str) -> None:
        result = subprocess.run(
            ["php", str(SCENARIOS), case],
            cwd=ROOT, text=True, capture_output=True, timeout=15, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn(f"PASS {case}", result.stdout)

    def test_explicit_creator_override_and_studio_inheritance(self) -> None:
        self.run_php("inheritance")

    def test_studio_calendar_is_tenant_scoped(self) -> None:
        self.run_php("calendar")

    def test_team_bottlenecks_are_explained(self) -> None:
        self.run_php("capacity")

    def test_independent_requires_no_team(self) -> None:
        self.run_php("independent")


if __name__ == "__main__":
    unittest.main()
