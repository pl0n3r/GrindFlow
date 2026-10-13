"""Offline CI contract for Laravel diagnostic route-template minimization.

PHPUnit tests in tests/Feature/DiagnosticsTest.php exercise real Laravel Request and
JSONL behavior under the PHP quality job. This unittest checks the accepted
scope without needing a vendor install in the fast Python job.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
LOG = ROOT / "app/Support/Diagnostics/DiagnosticLog.php"
FEATURE = ROOT / "tests/Feature/DiagnosticsTest.php"


class DiagnosticsRoutePrivacyContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.code = LOG.read_text(encoding="utf-8")
        cls.php_tests = FEATURE.read_text(encoding="utf-8")

    def test_ac01_guest_token_path_is_template_only(self):
        self.assertIn("$route instanceof Route", self.code)
        self.assertIn("$route->uri()", self.code)
        self.assertIn("'path' => $safePath", self.code)
        self.assertNotIn("$request->path()", self.code)
        self.assertIn("function test_diagnostic_log_redacts_guest_upload_token_route", self.php_tests)
        self.assertIn("'/guest-upload/{token}'", self.php_tests)
        self.assertIn("SYNTHETIC-SECRET", self.php_tests)

    def test_ac02_tracking_token_and_query_are_absent(self):
        self.assertIn("function test_diagnostic_log_redacts_tracking_link_token_route", self.php_tests)
        self.assertIn("'/l/{token}'", self.php_tests)
        self.assertIn("SYNTHETIC-LINK", self.php_tests)
        self.assertIn("SYNTHETIC-QUERY", self.php_tests)
        self.assertIn("assertStringNotContainsString", self.php_tests)

    def test_ac03_unmatched_request_has_fixed_path(self):
        self.assertIn("'/[unmatched]'", self.code)
        self.assertIn("function test_diagnostic_log_unmatched_route_never_exposes_raw_path", self.php_tests)
        self.assertIn("assertNull($entry['request']['route'])", self.php_tests)
        self.assertIn("'/[unmatched]'", self.php_tests)

    def test_ac04_incident_identity_and_admin_access_are_preserved(self):
        self.assertIn("'incident_id' => $incidentId", self.code)
        self.assertIn("'method' => $request->method()", self.code)
        self.assertIn("function test_platform_admin_can_read_recent_diagnostics", self.php_tests)
        self.assertIn("function test_non_admin_cannot_read_diagnostics", self.php_tests)
        self.assertIn("->assertForbidden()", self.php_tests)


if __name__ == "__main__":
    unittest.main()
