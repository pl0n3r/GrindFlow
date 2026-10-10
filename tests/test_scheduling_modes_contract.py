"""Executable Factory acceptance bridge for pure Symfony scheduling modes."""

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "symfony/tests/php/SchedulingModeApprovalTest.php"


class SchedulingModesContractTests(unittest.TestCase):
    def run_case(self, name: str) -> None:
        result = subprocess.run(
            ["php", str(PHP_TEST), name],
            cwd=ROOT, text=True, capture_output=True, timeout=15, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn(f"PASS {name}", result.stdout)

    def test_manual_assisted_pilot_enforce_same_hard_rules(self) -> None:
        self.run_case("hard_rules")

    def test_assisted_mode_requires_explicit_confirmation(self) -> None:
        self.run_case("assisted")

    def test_studio_approval_requires_all_configured_roles(self) -> None:
        self.run_case("studio")

    def test_insufficient_role_cannot_approve(self) -> None:
        self.run_case("insufficient_role")


if __name__ == "__main__":
    unittest.main()
