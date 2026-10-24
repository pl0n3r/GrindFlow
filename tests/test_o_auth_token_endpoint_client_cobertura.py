#!/usr/bin/env python3
"""AC-01: executable coverage inventory for OAuthTokenEndpointClient."""
from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class OAuthTokenEndpointClientCoberturaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        client = (ROOT / "app/Services/Media/Connections/OAuthTokenEndpointClient.php").read_text(encoding="utf-8")
        tests = (ROOT / "tests/Unit/OAuthTokenEndpointClientTest.php").read_text(encoding="utf-8")
        self.assertIn("class OAuthTokenEndpointClientTest extends TestCase", tests)
        self.assertIn("Http::preventStrayRequests()", tests)
        self.assertGreaterEqual(tests.count("Http::fake("), 8)
        for name in (
            "test_authorization_exchange_posts_once_and_normalizes_payload",
            "test_authorization_uses_default_scopes_and_ignores_invalid_optional_account",
            "test_missing_authorization_parameters_fail_before_network",
            "test_authorization_rejects_provider_errors_and_malformed_responses_without_leaking_secrets",
            "test_authorization_rate_limit_clamps_retry_after_without_retrying",
            "test_authorization_connection_timeout_is_safe_and_is_not_retried",
            "test_refresh_posts_once_with_bounded_expiry_and_parsed_scopes",
            "test_refresh_rejects_invalid_grant_and_other_http_errors_using_only_safe_codes",
            "test_refresh_rejects_invalid_payload_and_bounds_short_expiry",
            "test_refresh_rate_limit_has_safe_bounded_retry_after_and_one_request",
            "test_refresh_connection_timeout_is_safe_and_is_not_retried",
        ):
            with self.subTest(name=name):
                self.assertIn(f"function {name}(", tests)

        self.assertIn("Http::assertSentCount(1)", tests)
        self.assertIn("Http::assertNothingSent()", tests)
        self.assertIn("$request->options()['timeout']", tests)
        self.assertIn("$this->assertSafeError(", tests)
        self.assertIn("->timeout(20)", client)
        self.assertNotIn("->retry(", client)
        self.assertNotIn("Log::", client)
        self.assertNotIn("logger(", client)
        self.assertNotIn("file_get_contents(", tests)
        self.assertNotIn("curl_exec(", tests)


if __name__ == "__main__":
    unittest.main()
