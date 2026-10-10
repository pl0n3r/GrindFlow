<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Recovery;

/** Provider-neutral, fail-closed classification. Does not send or retry requests. */
final class RetryClassifier
{
    /** @return array{decision: string, reason: string} */
    public function classify(array $failure): array
    {
        $expected = ['tenant_id', 'delivery_id', 'delivery_state', 'failure_code',
            'idempotency_key', 'idempotency_proven', 'attempt', 'max_attempts'];
        $keys = array_keys($failure);
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || !self::identifier($failure['tenant_id'])
            || !self::identifier($failure['delivery_id'])
            || !is_string($failure['delivery_state'])
            || !in_array($failure['delivery_state'],
                ['failed', 'ambiguous', 'succeeded', 'in_flight'], true)
            || !is_string($failure['failure_code'])
            || !in_array($failure['failure_code'],
                ['rate_limited', 'transient_before_send', 'timeout_after_send',
                 'authentication_failed', 'permission_denied', 'invalid_content',
                 'provider_rejected', 'unknown'], true)
            || !is_bool($failure['idempotency_proven'])
            || !is_int($failure['attempt']) || !is_int($failure['max_attempts'])
            || $failure['attempt'] < 0 || $failure['max_attempts'] < 1
            || $failure['max_attempts'] > 5) {
            return self::result('retry_never', 'invalid_input');
        }
        if ($failure['delivery_state'] === 'ambiguous'
            || $failure['failure_code'] === 'timeout_after_send') {
            return self::result('retry_never', 'external_state_ambiguous');
        }
        if ($failure['delivery_state'] !== 'failed') {
            return self::result('retry_never', 'not_a_confirmed_failure');
        }
        if (in_array($failure['failure_code'],
            ['authentication_failed', 'permission_denied'], true)) {
            return self::result('needs_human', 'authorization_required');
        }
        if (!in_array($failure['failure_code'],
            ['rate_limited', 'transient_before_send'], true)) {
            return self::result('retry_never', 'not_retriable');
        }
        if ($failure['attempt'] >= $failure['max_attempts']) {
            return self::result('retry_never', 'attempt_budget_exhausted');
        }
        if (!$failure['idempotency_proven']
            || !self::identifier($failure['idempotency_key'])) {
            return self::result('retry_never', 'idempotency_unverified');
        }
        return self::result('retry_safe', 'idempotent_transient_failure');
    }

    /** @return array{decision: string, reason: string} */
    private static function result(string $decision, string $reason): array
    {
        return ['decision' => $decision, 'reason' => $reason];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $value) === 1;
    }
}
