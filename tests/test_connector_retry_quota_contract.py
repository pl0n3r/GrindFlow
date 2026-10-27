#!/usr/bin/env python3
"""Offline acceptance guard for real distribution retry, quota and idempotency.

This test does NOT call any provider or assert production readiness.
It binds GF-FR-005 to the already executable Laravel Feature tests and
their implementations; CI's PHP suite executes those tests separately.
"""
from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FEATURE = ROOT / "tests/Feature/DistributionTest.php"
CONTROLLER = ROOT / "tests/Unit/DistributionControllerTest.php"
MANAGER = ROOT / "app/Services/Distribution/PublicationDeliveryManager.php"
JOB = ROOT / "app/Jobs/DispatchScheduledPublication.php"
REQUIREMENTS = ROOT / "docs/REQUIREMENTS.md"


def need(source: str, token: str, context: str) -> None:
    if token not in source:
        raise AssertionError(f"{context}: missing {token!r}")


def php_method(source: str, name: str) -> str:
    match = re.search(
        r"(?m)^    (?:public|private|protected) function "
        + re.escape(name) + r"\s*\(",
        source,
    )
    if match is None:
        raise AssertionError(f"Real PHP method missing: {name}")
    following = re.search(
        r"(?m)^    (?:public|private|protected) function ",
        source[match.end():],
    )
    end = match.end() + following.start() if following else len(source)
    return source[match.start():end]


PHP_BACKOFF_PROBE = r"""
require $argv[1];
$reflection = new ReflectionClass('App\\Services\\Distribution\\PublicationDeliveryManager');
$manager = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('transientDelay');
foreach ([1, 2, 3, 4] as $attempt) {
    echo $method->invoke($manager, $attempt), "\n";
}
"""


def assert_transient_backoff_behavior(manager_source: str) -> None:
    """Execute the real PHP retry method without creating a manager or provider."""
    method = php_method(manager_source, "transientDelay")
    need(method, "return self::TRANSIENT_BACKOFF_SECONDS[", "Backoff lookup")
    need(method, "min(max($attempts, 1), 3)", "Attempt clamping")
    try:
        result = subprocess.run(
            ["php", "-r", PHP_BACKOFF_PROBE, str(MANAGER)],
            check=True,
            text=True,
            capture_output=True,
            timeout=15,
        )
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        raise AssertionError("Cannot verify PHP backoff behavior") from exc
    try:
        intervals = [int(line) for line in result.stdout.splitlines()]
    except ValueError as exc:
        raise AssertionError("Backoff probe emitted nonnumeric results") from exc
    if intervals != [60, 300, 900, 900]:
        raise AssertionError(f"Expected 60/300/900/900 second delays; got {intervals}")


def audit_manager(source: str, job: str) -> None:
    need(source, "public const MAX_ATTEMPTS = 4;", "Retry budget")
    for pair in ("1 => 60", "2 => 300", "3 => 900"):
        need(source, pair, "Bounded backoff")
    assert_transient_backoff_behavior(source)
    failure = php_method(source, "handleProviderFailure")
    for item in (
        "KIND_AUTHENTICATION",
        "STATUS_AUTHENTICATION_FAILED",
        "KIND_RATE_LIMIT",
        "'distribution_rate_limited'",
        "decrementAttempt: true",
        "'distribution_transient_failure'",
        "$this->transientDelay($delivery->attempts)",
    ):
        need(failure, item, "Provider failure classification")
    if not re.search(
        r"max\(\s*60,\s*min\(\s*\$exception->retryAfterSeconds"
        r"\s*\?\?\s*300,\s*3600\s*\)\s*,?\s*\)",
        failure,
    ):
        raise AssertionError("Rate limit must clamp to 60..3600 seconds")
    retry = php_method(source, "scheduleRetry")
    for item in (
        "? max(0, $delivery->attempts - 1)",
        "if ($attempts >= self::MAX_ATTEMPTS)",
        "STATUS_FAILED",
        "STATUS_RETRY_SCHEDULED",
        "'next_attempt_at' => now('UTC')->addSeconds($delaySeconds)",
        "'attempts' => $attempts",
    ):
        need(retry, item, "Retry persistence and exhaustion")
    dispatch = php_method(source, "dispatch")
    for item in (
        "if ($locked->attempts >= self::MAX_ATTEMPTS)",
        "STATUS_PROCESSING",
        "$locked->attempts + 1",
        "$this->providers->resolve(",
        "$provider->publish(",
        "$claimed->idempotency_key",
        "$this->handleProviderFailure(",
        "STATUS_PUBLISHED",
    ):
        need(dispatch, item, "Claimed provider dispatch")
    for item in (
        "STATUS_PUBLISHED",
        "STATUS_AUTHENTICATION_FAILED",
        "STATUS_FAILED",
        "STATUS_RETRY_SCHEDULED",
        "idempotency_key",
        "lockForUpdate()",
    ):
        need(php_method(source, "queue"), item, "Single logical delivery")
    for item in ("->where('status', PublicationDelivery::STATUS_PROCESSING)",
                 "->where('attempts', $delivery->attempts)"):
        need(php_method(source, "claimedDeliveryQuery"), item, "Stale worker fence")
    identity = php_method(source, "idempotencyKey")
    need(identity, "$organizationId.':'.$publicationId", "Tenant-bound idempotency")
    tenancy = php_method(source, "requireTenant")
    for token in ("hash_equals(", "$model->organization_id",
                  "canScheduleOrganization(",
                  "throw new AuthorizationException("):
        need(tenancy, token, "Tenant/actor guard")
    for token in ("ShouldBeUnique", "function uniqueId()",
                  "return $this->organization.':'.$this->deliveryId;"):
        need(job, token, "Unique tenant-bound queue job")


def audit_real_feature_tests(source: str, controller: str) -> None:
    for token in ("extends TestCase", "use RefreshDatabase;",
                  "Queue::fake([DispatchScheduledPublication::class])",
                  "class SequenceDistributionProvider"):
        need(source, token, "Executable disposable Laravel feature fixtures")

    rate = php_method(
        source, "test_rate_limit_is_bounded_and_successful_retry_is_idempotent")
    for token in (
        "DistributionProviderException::rateLimited(5)",
        "new DistributionResult('external-publication-123')",
        "PublicationDelivery::STATUS_RETRY_SCHEDULED",
        "$this->assertSame(0, $retry->attempts)",
        "now('UTC')->addSeconds(50)",
        "now('UTC')->addSeconds(70)",
        "$this->makeRetryDue(",
        "PublicationDelivery::STATUS_PUBLISHED",
        "$this->assertSame(1, $published->attempts)",
        "$this->assertCount(2, $provider->idempotencyKeys)",
        "$provider->idempotencyKeys[0]",
        "$provider->idempotencyKeys[1]",
        "$this->assertSame(2, $provider->calls)",
        "PublicationDelivery::query()->count()",
    ):
        need(rate, token, "Rate-limit/replay/once-only publication")

    quota = php_method(source, "test_rate_limits_do_not_consume_attempt_budget")
    for token in (
        "for ($index = 0; $index < 5; $index++)",
        "DistributionProviderException::rateLimited(60)",
        "PublicationDelivery::STATUS_RETRY_SCHEDULED",
        "$this->assertSame(0, $delivery->attempts)",
        "$this->assertSame(5, $provider->calls)",
    ):
        need(quota, token, "Quota negative regression")

    transient = php_method(source, "test_transient_failures_stop_after_bounded_attempts")
    for token in (
        "DistributionProviderException::transient()",
        "for ($attempt = 1; $attempt <= 4; $attempt++)",
        "PublicationDelivery::STATUS_FAILED",
        "$this->assertSame(4, $delivery->attempts)",
        "'distribution_transient_failure_exhausted'",
        "$this->assertSame(4, $provider->calls)",
    ):
        need(transient, token, "Transient exhaustion regression")

    auth = php_method(source, "test_authentication_failure_is_terminal_and_distinct")
    for token in (
        "DistributionProviderException::authentication()",
        "PublicationDelivery::STATUS_AUTHENTICATION_FAILED",
        "$this->assertNull($fresh->next_attempt_at)",
        "$this->assertSame(1, $provider->calls)",
    ):
        need(auth, token, "Authentication must not retry")

    stale = php_method(source, "test_stale_worker_cannot_schedule_retry_over_newer_claim")
    for token in (
        "LeaseTakeoverDistributionProvider",
        "PublicationDelivery::STATUS_PROCESSING",
        "$this->assertSame(2, $fresh->attempts)",
        "$this->assertNull($fresh->next_attempt_at)",
        "$this->assertNull($fresh->last_error_code)",
    ):
        need(stale, token, "Expired claim may not write retry")

    cross = php_method(
        controller, "test_retry_rejects_foreign_delivery_and_non_retryable_states")
    for token in (
        "$foreignOrg", "$alien->getKey()",
        "->assertStatus(409)", "->assertNotFound()",
        "PublicationDelivery::STATUS_QUEUED",
        "PublicationDelivery::STATUS_FAILED",
        "Queue::assertNothingPushed()",
    ):
        need(cross, token, "Foreign-tenant retry and no-side-effect guard")
    foreign_retry = (
        r"\$this->actingAs\(\$actor\)->post\(route\(\s*"
        r"'organizations\.distribution\.deliveries\.retry',\s*"
        r"\[\s*'organizationId'\s*=>\s*\$org->getKey\(\),\s*"
        r"'deliveryId'\s*=>\s*\$alien->getKey\(\),?\s*"
        r"\]\s*,?\s*\)\s*\)\s*->assertNotFound\(\)"
    )
    if not re.search(foreign_retry, cross, re.DOTALL):
        raise AssertionError("Foreign delivery retry must return 404 from its own request")




class ConnectorRetryQuotaTests(unittest.TestCase):
    def load_contract(self) -> tuple[str, str, str, str]:
        requirements = REQUIREMENTS.read_text(encoding="utf-8")
        for token in (
            "### GF-FR-005", "Retry/backoff behavior is bounded and observable.",
            "A successful retry cannot create duplicate publication"):
            need(requirements, token, "Source requirements")
        return (
            MANAGER.read_text(encoding="utf-8"),
            JOB.read_text(encoding="utf-8"),
            FEATURE.read_text(encoding="utf-8"),
            CONTROLLER.read_text(encoding="utf-8"),
        )

    def test_provider_error_retry_and_idempotency(self) -> None:
        """AC-01: genuine disposable PHP behaviors plus their production guards."""
        manager, job, feature, controller = self.load_contract()
        audit_manager(manager, job)
        audit_real_feature_tests(feature, controller)

    def test_negative_mutations_reject_weakened_retry_guards(self) -> None:
        manager, job, _, _ = self.load_contract()
        cases = (
            ("MAX_ATTEMPTS = 4", "MAX_ATTEMPTS = 9"),
            ("2 => 300", "2 => 0"),
            ("min(max($attempts, 1), 3)", "1"),
            ("decrementAttempt: true", "decrementAttempt: false"),
            ("? max(0, $delivery->attempts - 1)",
             "? max(1, $delivery->attempts - 1)"),
            ("$exception->retryAfterSeconds ?? 300",
             "$exception->retryAfterSeconds ?? 99999"),
            ("->where('attempts', $delivery->attempts)",
             "->where('attempts', 0)"),
            ("$organizationId.':'.$publicationId",
             "'unscoped-publication'"),
        )
        for original, weakened in cases:
            with self.subTest(guard=original):
                self.assertIn(original, manager)
                changed = manager.replace(original, weakened)
                with self.assertRaises(AssertionError):
                    audit_manager(changed, job)

    def test_negative_mutations_reject_missing_runtime_regressions(self) -> None:
        _, _, feature, controller = self.load_contract()
        for original, weakened in (
            ("$provider->idempotencyKeys[1]", "$provider->idempotencyKeys[0]"),
            ("$this->assertSame(0, $retry->attempts)",
             "$this->assertSame(1, $retry->attempts)"),
            ("for ($index = 0; $index < 5; $index++)",
             "for ($index = 0; $index < 1; $index++)"),
            ("'distribution_transient_failure_exhausted'",
             "'distribution_transient_failure'"),
            ("$this->assertNull($fresh->next_attempt_at)", ""),
        ):
            with self.subTest(regression=original):
                self.assertIn(original, feature)
                mutated_feature = feature.replace(original, weakened, 1)
                with self.assertRaises(AssertionError):
                    audit_real_feature_tests(mutated_feature, controller)
        for original, weakened in (
            ("->assertNotFound()", "->assertOk()"),
            ("\'deliveryId\' => $alien->getKey()",
             "\'deliveryId\' => $queued->getKey()"),
        ):
            self.assertIn(original, controller)
            mutated_controller = controller.replace(original, weakened, 1)
            with self.assertRaises(AssertionError):
                audit_real_feature_tests(feature, mutated_controller)


if __name__ == "__main__":
    unittest.main()
