"""Executable Factory acceptance bridge for pure Symfony scheduling rules."""

from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP_RUNNER = ROOT / "symfony/tests/php/SchedulingTemplatesRetryTest.php"


class SchedulingTemplatesRetryContractTests(unittest.TestCase):
    def run_case(self, name: str) -> None:
        completed = subprocess.run(
            ["php", str(PHP_RUNNER), name],
            cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertIn(f"PASS {name}", completed.stdout)

    def test_template_scopes_account_campaign_period(self):
        self.run_case("template")

    def test_campaign_calendar_is_independent_and_tenant_scoped(self):
        self.run_case("campaign")

    def test_ambiguous_external_result_never_retries(self):
        self.run_case("ambiguous")

    def test_retry_requires_idempotent_retriable_failure(self):
        self.run_case("idempotent")


if __name__ == "__main__":
    unittest.main()
