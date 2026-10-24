"""Contract gate for tenant-safe HTTP regressions of DistributionController.

Uses only synthetic actors and database fixtures. When Composer dependencies
are present, it also executes the actual Laravel PHPUnit cases.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT / "tests/Unit/DistributionControllerTest.php"
CONTROLLER = ROOT / "app/Http/Controllers/Distribution/DistributionController.php"


class DistributionControllerCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        source = PHP_TEST.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        for case in (
            "test_studio_can_create_only_sandbox_destinations",
            "test_studio_can_update_own_destination",
            "test_retry_budget_exhaustion_is_not_queued",
            "test_model_cannot_create_or_update_destinations",
            "test_foreign_destination_is_not_mutated_even_with_a_valid_local_studio",
            "test_destination_update_rejects_invalid_status_without_mutating_row",
            "test_index_rejects_invalid_status_dates_and_pages",
            "test_retry_rejects_foreign_delivery_and_non_retryable_states",
        ):
            with self.subTest(case=case):
                self.assertIn("public function " + case + "(): void", source)
        for marker in (
            "use RefreshDatabase;",
            "Queue::fake([DispatchScheduledPublication::class]);",
            "organizations.distribution.destinations.store",
            "organizations.distribution.destinations.update",
            "organizations.distribution.deliveries.retry",
            "organizations.distribution.index",
            "assertForbidden()",
            "assertNotFound()",
            "assertStatus(409)",
            "assertSessionHasErrors('status')",
            "assertSessionHasErrors('page')",
            "assertSessionHasErrors('provider')",
            "TenantContext::class",
            "Queue::assertNothingPushed()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, source)
        self.assertIn("class DistributionController extends Controller", controller)
        self.assertIn("->where('organization_id', $organization->getKey())", controller)
        for call in ("curl_exec(", "Http::post(", "Http::get("):
            self.assertNotIn(call, source)

        if shutil.which("php"):
            lint = subprocess.run(
                ["php", "-l", str(PHP_TEST)], cwd=ROOT,
                capture_output=True, text=True, timeout=20, check=False,
            )
            self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)

        phpunit = ROOT / "vendor/bin/phpunit"
        if phpunit.is_file() and shutil.which("php"):
            result = subprocess.run(
                ["php", str(phpunit), "-c", "phpunit.xml", str(PHP_TEST)],
                cwd=ROOT, capture_output=True, text=True, timeout=180,
                check=False,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
