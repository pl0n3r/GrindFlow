from __future__ import annotations

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class FacebookPageProviderContractTests(unittest.TestCase):
    def text(self, relative: str) -> str:
        return (ROOT / relative).read_text(encoding="utf-8")

    def test_port_contract_is_framework_independent(self):
        command = self.text("symfony/src/Distribution/DistributionCommand.php")
        provider = self.text("symfony/src/Distribution/DistributionProvider.php")
        outcome = self.text("symfony/src/Distribution/DistributionOutcome.php")
        combined = command + provider + outcome

        self.assertIn("interface DistributionProvider", provider)
        self.assertIn("DistributionCommand $command", provider)
        self.assertIn("DistributionOutcome", provider)
        self.assertNotIn("Illuminate\\", combined)
        self.assertNotIn("HttpFoundation", combined)

    def test_provider_pins_official_host_and_hides_token(self):
        transport = self.text("symfony/src/Distribution/StreamFacebookPageTransport.php")
        provider = self.text("symfony/src/Distribution/FacebookPageProvider.php")
        service = self.text("symfony/src/Distribution/FacebookPagePublicationService.php")
        migration = self.text("symfony/migrations/Version20261003173000.php")

        self.assertIn("https://graph.facebook.com/%s/%s/feed", transport)
        self.assertIn("rawurlencode($graphVersion)", transport)
        self.assertIn("rawurlencode($pageId)", transport)
        self.assertNotIn("FACEBOOK_GRAPH_HOST", transport)
        self.assertIn("catch (Throwable)", provider)
        self.assertIn("DistributionProviderException::ambiguous()", provider)
        self.assertNotIn("access_token", migration.lower())
        self.assertNotIn("accessToken", service)

    def test_configuration_fails_closed(self):
        config = self.text("symfony/src/Distribution/FacebookPageConfiguration.php")
        transport = self.text("symfony/src/Distribution/StreamFacebookPageTransport.php")

        self.assertIn("hash_equals", config)
        self.assertGreaterEqual(config.count("preg_match("), 3)
        self.assertIn("$this->pageId", config)
        self.assertIn("$this->graphVersion", config)
        self.assertIn("DistributionProviderException::configuration()", config)
        self.assertGreaterEqual(transport.count("preg_match("), 2)
        self.assertIn("$graphVersion", transport)
        self.assertIn("$pageId", transport)

    def test_failure_kinds_are_safe_and_rate_limit_only_retryable(self):
        exception = self.text("symfony/src/Distribution/DistributionProviderException.php")
        provider = self.text("symfony/src/Distribution/FacebookPageProvider.php")
        compact = " ".join(exception.split())

        for kind in ("configuration", "authentication", "rate_limit", "rejected", "ambiguous"):
            self.assertIn(f"'{kind}'", exception)
        self.assertIn("self::KIND_RATE_LIMIT, true", compact)
        self.assertIn("self::KIND_AUTHENTICATION, false", compact)
        self.assertIn("self::KIND_REJECTED, false", compact)
        self.assertIn("self::KIND_AMBIGUOUS, false", compact)
        self.assertIn("if ($status === 429)", provider)
        self.assertIn("if ($status >= 500)", provider)

    def test_ledger_contract_prevents_blind_duplicate_delivery(self):
        service = self.text("symfony/src/Distribution/FacebookPagePublicationService.php")
        migration = self.text("symfony/migrations/Version20261003173000.php")

        self.assertIn("UNIQUE KEY uq_gf_external_attempt_key", migration)
        self.assertIn("request_fingerprint", migration)
        self.assertIn("hash_equals", service)
        self.assertIn("'published' => $this->storedOutcome", service)
        self.assertIn("'ambiguous', 'in_flight' => throw DistributionProviderException::ambiguous()", service)
        self.assertIn("'status' => 'in_flight'", service)
        self.assertIn("getTransactionNestingLevel() > 0", service)
        self.assertIn("'page_id' => $this->provider->destinationPageId()", service)
        self.assertIn("external publication attempts cannot be deleted", migration)

    def test_rate_limit_contract_is_persisted_and_bounded(self):
        service = self.text("symfony/src/Distribution/FacebookPagePublicationService.php")
        migration = self.text("symfony/migrations/Version20261003173000.php")

        self.assertIn("retry_after_seconds", migration)
        self.assertIn("retry_not_before", migration)
        self.assertIn("'rate_limited' => $this->resumeRateLimited", service)
        self.assertIn(
            "DistributionProviderException::KIND_RATE_LIMIT => 'rate_limited'",
            service,
        )
        self.assertIn("'status' => 'in_flight'", service)
        self.assertIn("max(60, min", service)
        self.assertIn("private ?Closure $clock = null", service)

    def test_phpunit_regression_suite_exists_without_real_network(self):
        test = self.text("symfony/tests/php/FacebookPageProviderTest.php")

        self.assertIn("testIdempotencyKeyRejectsDifferentFingerprintWithoutSecondCall", test)
        self.assertIn("testRateLimitLedgerBlocksEarlyRetryAndAllowsOneRetryAfterWindow", test)
        self.assertIn("testIdempotencyLedgerPreventsDuplicateProviderCalls", test)
        self.assertIn("testPublicationRejectsExternalTransactionBeforeProviderIo", test)
        self.assertIn("testPageChangeRejectsPublishedAndRateLimitedReplayWithoutSecondCall", test)
        self.assertIn("FakeFacebookPageTransport", test)
        self.assertNotIn("new StreamFacebookPageTransport", test)


if __name__ == "__main__":
    unittest.main()
