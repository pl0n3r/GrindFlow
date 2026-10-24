"""#575: deterministic acceptance adapter for TrafficController HTTP regressions.

Factory's reusable acceptance runner does not install Composer. This adapter
checks the actual controller, the Laravel HTTP cases, and PHP syntax. Where
Composer dependencies are installed, it also runs the PHPUnit cases.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "app/Http/Controllers/Traffic/TrafficController.php"
TEST = ROOT / "tests/Unit/TrafficControllerTest.php"


class TrafficControllerCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SOURCE.is_file(), "Missing actual TrafficController")
        self.assertTrue(TEST.is_file(), "Missing HTTP PHPUnit regression")
        source = SOURCE.read_text(encoding="utf-8")
        cases = TEST.read_text(encoding="utf-8")

        self.assertRegex(source, r"class\s+TrafficController\s+extends\s+Controller")
        for method in ("index", "export", "store", "updateLinkDetails", "updateLinkStatus"):
            self.assertRegex(source, rf"public function {method}\s*\(")
        for guard in (
            "canManageTrafficOrganization", "StoreTrackedLinkRequest",
            "tenantOrganization", "TrackedLinkDailyMetric",
        ):
            self.assertIn(guard, source)

        self.assertIn("use RefreshDatabase;", cases)
        self.assertIn("extends TestCase", cases)
        for name in (
            "test_owner_dashboard_and_csv_never_leak_foreign_tenant_links",
            "test_editor_creates_tracked_link_but_rejects_unsafe_destination",
            "test_model_and_foreign_tenant_cannot_manage_links",
            "test_disabled_link_keeps_token_and_clicks_and_never_redirects",
            "test_invalid_status_and_filter_inputs_do_not_mutate_links",
        ):
            self.assertRegex(cases, rf"public function {name}\s*\(")

        for evidence in (
            "runWithinOrganization(", "assertDontSee(", "streamedContent()",
            "assertForbidden()", "assertNotFound()", "assertSessionHasErrors(",
            "TrackedLink::STATUS_DISABLED", "Queue::assertNothingPushed()",
            "route('traffic.redirect'", "javascript:alert(1)",
            "?from=2026-09-01&to=2026-09-30",
        ):
            self.assertIn(evidence, cases, f"Missing coverage: {evidence}")

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required; never silently skip syntax")
        lint = subprocess.run(
            [php, "-l", str(TEST)], capture_output=True, text=True,
            timeout=15, check=False,
        )
        self.assertEqual(lint.returncode, 0, lint.stderr[-700:])
        self.assertIn("No syntax errors detected", lint.stdout)

        if (ROOT / "vendor/autoload.php").is_file():
            actual = subprocess.run(
                [php, "artisan", "test", "tests/Unit/TrafficControllerTest.php", "--no-ansi"],
                cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
            )
            self.assertEqual(
                actual.returncode, 0, (actual.stdout + actual.stderr)[-2400:],
            )


if __name__ == "__main__":
    unittest.main()
