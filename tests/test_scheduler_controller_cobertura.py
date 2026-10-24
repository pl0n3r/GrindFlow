"""#574: acceptance adapter for legacy Laravel SchedulerController regression.

The Factory reusable acceptance runner does not install Composer. Here we
verify the PHP regression definitions and syntax; when vendor dependencies
exist, we additionally run the real HTTP/SQLite PHPUnit suite.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "app/Http/Controllers/Scheduling/SchedulerController.php"
TEST = ROOT / "tests/Unit/SchedulerControllerTest.php"


class SchedulerControllerCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SOURCE.is_file(), "Missing real SchedulerController")
        self.assertTrue(TEST.is_file(), "Missing PHP regression")
        source = SOURCE.read_text(encoding="utf-8")
        php_test = TEST.read_text(encoding="utf-8")

        self.assertRegex(source, r"class\s+SchedulerController\s+extends\s+Controller")
        for method in ("index", "store", "update", "updateTrackedLink", "cancel"):
            self.assertRegex(source, rf"public function {method}\s*\(")
        for guard in ("StoreScheduledPublicationRequest", "TenantScope::class",
                      "canScheduleOrganization"):
            self.assertIn(guard, source)

        cases = (
            "test_editor_schedules_for_own_tenant_with_explicit_timezone",
            "test_model_cannot_schedule_and_foreign_tenant_is_not_visible",
            "test_invalid_calendar_inputs_and_timezone_do_not_create_schedules",
        )
        self.assertIn("use RefreshDatabase;", php_test)
        self.assertIn("extends TestCase", php_test)
        for case in cases:
            self.assertRegex(php_test, rf"public function {case}\s*\(\)")
        for guard in (
            "assertForbidden()", "assertNotFound()", "assertDatabaseCount(",
            "assertSessionHasErrors('timezone')",
            "assertSessionHasErrors('scheduled_for_local')",
            "scheduled_for_utc", "America/Bogota", "runWithinOrganization(",
        ):
            self.assertIn(guard, php_test, f"Missing regression: {guard}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required, no silent skip")
        lint = subprocess.run(
            [php, "-l", str(TEST)], capture_output=True, text=True,
            timeout=15, check=False,
        )
        self.assertEqual(lint.returncode, 0, lint.stderr[:700])
        self.assertIn("No syntax errors detected", lint.stdout)

        if (ROOT / "vendor/autoload.php").is_file():
            result = subprocess.run(
                [php, "artisan", "test", "tests/Unit/SchedulerControllerTest.php", "--no-ansi"],
                cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
            )
            self.assertEqual(result.returncode, 0, (result.stdout + result.stderr)[-1800:])


if __name__ == "__main__":
    unittest.main()
