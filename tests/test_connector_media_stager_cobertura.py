#!/usr/bin/env python3
"""AC-01: real Laravel unit regressions for ConnectorMediaStager, offline only.

The acceptance guard is runnable without Composer; where vendor dependencies are
installed, it executes the targeted PHPUnit suite (phpunit.xml defaults to Feature).
"""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SUITE = ROOT / "tests/Unit/ConnectorMediaStagerTest.php"
SERVICE = ROOT / "app/Services/Media/Connectors/ConnectorMediaStager.php"
PHPUNIT_CONFIG = ROOT / "phpunit.xml"


class ConnectorMediaStagerCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(SUITE.is_file(), "Missing PHPUnit suite for connector staging")
        self.assertTrue(SERVICE.is_file(), "Missing ConnectorMediaStager service")
        self.assertTrue(PHPUNIT_CONFIG.is_file(), "Missing PHPUnit configuration")
        suite = SUITE.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")

        for required in (
            "class ConnectorMediaStagerTest extends TestCase",
            "use RefreshDatabase;",
            "Storage::fake('media')",
            "TenantContext::class",
            "Organization::factory()->create()",
            "Membership::query()->create(",
            "Mockery::mock(MediaIngestionCoordinator::class)",
            "stageAndQueue(",
            "Storage::disk('media')->allFiles()",
        ):
            self.assertIn(required, suite, required)

        cases = (
            "test_stages_exact_bytes_and_queues_tenant_scoped_metadata",
            "test_existing_source_skips_download_and_staging",
            "test_requires_tenant_context_and_manager_permissions_before_download",
            "test_rejects_invalid_remote_file_before_storage_or_download",
            "test_rejects_declared_oversize_without_downloading",
            "test_rejects_invalid_download_result_without_staging",
            "test_stream_is_bounded_even_when_metadata_understates_actual_bytes",
            "test_size_mismatch_cleans_staging_and_never_queues",
            "test_failed_queue_handoff_removes_staged_blob",
            "test_deduplicated_queue_handoff_removes_new_blob",
        )
        for case in cases:
            matches = re.findall(r"public function " + re.escape(case) + r"\s*\(", suite)
            self.assertEqual(len(matches), 1, "Missing/duplicated PHPUnit test: " + case)

        for mechanism in (
            "->findExistingSource(",
            "->queueSource(",
            "copyWithinLimit(",
            "deleteStaged(",
            "Storage::disk($disk)->put(",
            "Storage::disk($disk)->size(",
            "MediaConnectorException::fileTooLarge()",
            "MediaConnectorException::stagingFailed()",
        ):
            self.assertIn(mechanism, service, mechanism)
        for forbidden in ("Http::", "curl_", "https://", "file_get_contents('http"):
            self.assertNotIn(forbidden, suite, "Unexpected external I/O: " + forbidden)

        php = shutil.which("php")
        if php:
            lint = subprocess.run(
                [php, "-l", str(SUITE)],
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=25,
                check=False,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        runner = ROOT / "vendor/bin/phpunit"
        if php and runner.is_file():
            run = subprocess.run(
                [php, str(runner), "--no-coverage", str(SUITE)],
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=180,
                check=False,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()
