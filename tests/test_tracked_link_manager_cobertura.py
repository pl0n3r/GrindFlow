#!/usr/bin/env python3
"""AC-01: executable PHPUnit coverage plus a dependency-free contract guard.

Reusable acceptance uses a read-only checkout without Composer installation.
When PHPUnit dependencies exist, this test also executes the PHP suite using
Laravel's disposable in-memory SQLite connection.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SUITE = ROOT / "tests/Unit/TrackedLinkManagerTest.php"
SERVICE = ROOT / "app/Services/Traffic/TrackedLinkManager.php"


class TrackedLinkManagerCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SUITE.is_file(), "Missing manager PHPUnit suite")
        self.assertTrue(SERVICE.is_file(), "Missing production TrackedLinkManager")
        suite = SUITE.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")

        for invariant in (
            "class TrackedLinkManagerTest extends TestCase",
            "use RefreshDatabase;",
            "app(TrackedLinkManager::class)",
            "TenantContext::class",
            "Organization::factory()->create()",
            "Membership::query()->create(",
        ):
            self.assertIn(invariant, suite, invariant)

        required_cases = (
            "test_create_normalizes_fields_and_allocates_a_public_token",
            "test_create_requires_tenant_context_and_manager_role",
            "test_create_retries_global_token_collisions_across_tenants",
            "test_create_fails_closed_after_eight_token_collisions",
            "test_set_status_is_reversible_idempotent_and_preserves_click_history",
            "test_set_status_rejects_invalid_values_and_foreign_ids_without_writes",
            "test_update_details_normalizes_nullable_fields_without_rotating_token_or_status",
            "test_update_details_rejects_invalid_metadata_atomically",
            "test_update_details_denies_foreign_scope_and_unprivileged_actor",
            "test_update_details_rolls_back_on_database_failure",
        )
        for case in required_cases:
            self.assertEqual(
                1,
                len(re.findall(r"public function " + re.escape(case) + r"\s*\(", suite)),
                f"Missing or duplicate PHPUnit test: {case}",
            )

        for invariant in (
            "->create(",
            "->setStatus(",
            "->updateDetails(",
            "Str::createRandomStringsUsing(",
            "Str::createRandomStringsNormally()",
            "TrackedLinkDailyMetric::query()->create(",
            "assertDatabaseCount('tracked_links', 1)",
            "ModelNotFoundException",
            "AuthorizationException",
            "CREATE TRIGGER reject_link_update",
            "DROP TRIGGER reject_link_update",
        ):
            self.assertIn(invariant, suite, invariant)
        for invariant in (
            "DB::transaction(",
            "->lockForUpdate()",
            "->withoutGlobalScopes()",
            "private const int TOKEN_ATTEMPTS = 8",
        ):
            self.assertIn(invariant, service, invariant)
        self.assertNotIn("Http::", suite)
        self.assertNotIn("curl_", suite)

        php = shutil.which("php")
        if php:
            syntax = subprocess.run(
                [php, "-l", str(SUITE)],
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=20,
                check=False,
            )
            self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)

        executable = ROOT / "vendor/bin/phpunit"
        if php and executable.is_file():
            run = subprocess.run(
                [php, str(executable), "--no-coverage", str(SUITE)],
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=180,
                check=False,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()
