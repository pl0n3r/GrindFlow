"""Factory acceptance bridge to deterministic PHP Scheduling Limit rules."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP_SCRIPT = ROOT / "symfony/tests/php/SchedulingLimitRulesTest.php"


class SchedulingLimitRulesContractTests(unittest.TestCase):
    def run_case(self, case: str) -> None:
        completed = subprocess.run(
            ["php", str(PHP_SCRIPT), case],
            cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertIn(f"PASS {case}", completed.stdout)

    def test_minimum_separation_scoped_to_network_and_account(self):
        self.run_case("separation")

    def test_blocked_window_rejects_and_proposes_safe_relocation(self):
        self.run_case("windows")

    def test_daily_cap_aggregates_all_campaigns(self):
        self.run_case("daily")

    def test_iana_timezone_dst_gap_and_fold(self):
        self.run_case("timezone")


if __name__ == "__main__":
    unittest.main()
