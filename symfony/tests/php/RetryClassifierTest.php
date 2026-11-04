<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Scheduling\Recovery\RetryClassifier;
use PHPUnit\Framework\TestCase;

/** Offline decision-table coverage: synthetic records; no provider, DB or HTTP. */
final class RetryClassifierTest extends TestCase
{
    public function testIdempotentRateLimitAndTransientFailuresAreRetrySafe(): void
    {
        foreach (['rate_limited', 'transient_before_send'] as $code) {
            foreach ([0, 1, 2] as $attempt) {
                $this->assertClassifies(
                    ['decision' => 'retry_safe', 'reason' => 'idempotent_transient_failure'],
                    self::failure(['failure_code' => $code, 'attempt' => $attempt])
                );
            }
        }
    }

    public function testAmbiguousStateAndTimeoutAfterSendNeverRetry(): void
    {
        foreach (['rate_limited', 'transient_before_send', 'timeout_after_send'] as $code) {
            $this->assertClassifies(
                ['decision' => 'retry_never', 'reason' => 'external_state_ambiguous'],
                self::failure(['delivery_state' => 'ambiguous', 'failure_code' => $code])
            );
        }
        foreach (['failed', 'in_flight', 'succeeded'] as $state) {
            $this->assertClassifies(
                ['decision' => 'retry_never', 'reason' => 'external_state_ambiguous'],
                self::failure(['delivery_state' => $state, 'failure_code' => 'timeout_after_send'])
            );
        }
        foreach (['in_flight', 'succeeded'] as $state) {
            $this->assertClassifies(
                ['decision' => 'retry_never', 'reason' => 'not_a_confirmed_failure'],
                self::failure(['delivery_state' => $state])
            );
        }
    }

    public function testAuthenticationAndPermissionFailuresNeedAHuman(): void
    {
        foreach (['authentication_failed', 'permission_denied'] as $code) {
            foreach ([true, false] as $idempotency) {
                $this->assertClassifies(
                    ['decision' => 'needs_human', 'reason' => 'authorization_required'],
                    self::failure(['failure_code' => $code, 'idempotency_proven' => $idempotency])
                );
            }
        }
        foreach (['invalid_content', 'provider_rejected', 'unknown'] as $code) {
            $this->assertClassifies(
                ['decision' => 'retry_never', 'reason' => 'not_retriable'],
                self::failure(['failure_code' => $code])
            );
        }
    }

    public function testUnprovenIdempotencyOrExhaustedBudgetNeverRetry(): void
    {
        foreach (['rate_limited', 'transient_before_send'] as $code) {
            foreach ([
                ['idempotency_proven' => false],
                ['idempotency_key' => ''],
                ['idempotency_key' => 'not a safe identifier'],
            ] as $changes) {
                $this->assertClassifies(
                    ['decision' => 'retry_never', 'reason' => 'idempotency_unverified'],
                    self::failure(array_replace(['failure_code' => $code], $changes))
                );
            }
            foreach ([['attempt' => 1, 'max_attempts' => 1],
                ['attempt' => 3, 'max_attempts' => 3],
                ['attempt' => 4, 'max_attempts' => 3]] as $changes) {
                $this->assertClassifies(
                    ['decision' => 'retry_never', 'reason' => 'attempt_budget_exhausted'],
                    self::failure(array_replace(['failure_code' => $code], $changes))
                );
            }
        }
    }

    public function testMalformedInputFailsClosed(): void
    {
        $base = self::failure();
        $missing = $base;
        unset($missing['delivery_id']);
        $badInputs = [
            'missing field' => $missing,
            'unknown field' => array_replace($base, ['payload' => 'synthetic-disallowed']),
            'invalid tenant' => array_replace($base, ['tenant_id' => 'invalid tenant']),
            'empty delivery' => array_replace($base, ['delivery_id' => '']),
            'invalid state' => array_replace($base, ['delivery_state' => 'queued']),
            'non-string state' => array_replace($base, ['delivery_state' => null]),
            'unknown code' => array_replace($base, ['failure_code' => 'server_error']),
            'non-string code' => array_replace($base, ['failure_code' => ['rate_limited']]),
            'unproven string flag' => array_replace($base, ['idempotency_proven' => 'true']),
            'attempt as string' => array_replace($base, ['attempt' => '1']),
            'negative attempt' => array_replace($base, ['attempt' => -1]),
            'budget zero' => array_replace($base, ['max_attempts' => 0]),
            'budget beyond limit' => array_replace($base, ['max_attempts' => 6]),
            'budget as string' => array_replace($base, ['max_attempts' => '3']),
        ];
        foreach ($badInputs as $case => $input) {
            self::assertSame(
                ['decision' => 'retry_never', 'reason' => 'invalid_input'],
                (new RetryClassifier())->classify($input),
                $case
            );
        }
        foreach ([null, ['nested'], 'invalid key'] as $unsafeKey) {
            self::assertSame(
                ['decision' => 'retry_never', 'reason' => 'idempotency_unverified'],
                (new RetryClassifier())->classify(self::failure(['idempotency_key' => $unsafeKey]))
            );
        }
    }

    /** @return array<string, mixed> */
    private static function failure(array $overrides = []): array
    {
        return array_replace([
            'tenant_id' => 'tenant_synthetic',
            'delivery_id' => 'delivery_synthetic',
            'delivery_state' => 'failed',
            'failure_code' => 'rate_limited',
            'idempotency_key' => 'key_synthetic_123',
            'idempotency_proven' => true,
            'attempt' => 1,
            'max_attempts' => 3,
        ], $overrides);
    }

    /** @param array{decision: string, reason: string} $expected */
    private function assertClassifies(array $expected, array $failure): void
    {
        self::assertSame($expected, (new RetryClassifier())->classify($failure));
    }
}
