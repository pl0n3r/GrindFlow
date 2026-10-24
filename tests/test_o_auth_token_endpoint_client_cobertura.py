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
            "testAuthorizationExchangePostsOnceAndNormalizesPayload",
            "testAuthorizationUsesDefaultScopesAndIgnoresInvalidOptionalAccount",
            "testMissingAuthorizationParametersFailBeforeNetwork",
            "testAuthorizationRejectsProviderErrorsAndMalformedResponsesWithoutLeakingSecrets",
            "testAuthorizationRateLimitClampsRetryAfterWithoutRetrying",
            "testAuthorizationConnectionTimeoutIsSafeAndIsNotRetried",
            "testRefreshPostsOnceWithBoundedExpiryAndParsedScopes",
            "testRefreshRejectsInvalidGrantAndOtherHttpErrorsUsingOnlySafeCodes",
            "testRefreshRejectsInvalidPayloadAndBoundsShortExpiry",
            "testRefreshRateLimitHasSafeBoundedRetryAfterAndOneRequest",
            "testRefreshConnectionTimeoutIsSafeAndIsNotRetried",
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
