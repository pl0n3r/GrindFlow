<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Diagnostics;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure, offline-only summary of already classified job failures.
 * Never pass raw job payloads, exception messages, identifiers or log context.
 */
final class JobFailureDiagnostics
{
    private const TYPES = ['distribution', 'media', 'publication', 'scheduler', 'other'];
    private const CAUSES = ['dependency_unavailable', 'retry_exhausted', 'timeout', 'validation_failed', 'unknown'];
    private const INPUT_KEYS = ['type', 'attempts', 'cause', 'failed_at'];
    private const MAX_FAILURES = 1000;

    /**
     * @param list<array<string, mixed>> $failures Trusted, classified metadata only.
     * @return array{schema_version: int, as_of: string, total_failures: int, jobs: list<array{type: string, failures: int, attempts: int, last_cause: string, age_seconds: int}>}
     */
    public static function summarize(array $failures, DateTimeImmutable $now): array
    {
        if (!array_is_list($failures) || count($failures) > self::MAX_FAILURES) {
            throw new InvalidArgumentException('INVALID_JOB_FAILURE_METADATA');
        }

        $nowEpoch = $now->getTimestamp();
        $groups = [];
        foreach ($failures as $entry) {
            if (!is_array($entry) || array_keys($entry) !== self::INPUT_KEYS
                || !is_string($entry['type']) || !in_array($entry['type'], self::TYPES, true)
                || !is_int($entry['attempts']) || $entry['attempts'] < 1 || $entry['attempts'] > 1000000
                || !is_string($entry['cause']) || !in_array($entry['cause'], self::CAUSES, true)
                || !is_int($entry['failed_at']) || $entry['failed_at'] < 0 || $entry['failed_at'] > $nowEpoch) {
                throw new InvalidArgumentException('INVALID_JOB_FAILURE_METADATA');
            }

            $type = $entry['type'];
            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'type' => $type,
                    'failures' => 0,
                    'attempts' => 0,
                    'last_cause' => 'unknown',
                    'age_seconds' => 0,
                    '_last_at' => -1,
                ];
            }

            ++$groups[$type]['failures'];
            $groups[$type]['attempts'] = max($groups[$type]['attempts'], $entry['attempts']);
            $lastAt = $groups[$type]['_last_at'];
            // A tie is resolved by classified cause, not the input iteration order.
            if ($entry['failed_at'] > $lastAt || ($entry['failed_at'] === $lastAt
                && strcmp($entry['cause'], $groups[$type]['last_cause']) < 0)) {
                $groups[$type]['last_cause'] = $entry['cause'];
                $groups[$type]['age_seconds'] = $nowEpoch - $entry['failed_at'];
                $groups[$type]['_last_at'] = $entry['failed_at'];
            }
        }

        ksort($groups, SORT_STRING);
        $jobs = [];
        foreach ($groups as $group) {
            unset($group['_last_at']);
            $jobs[] = $group;
        }

        return [
            'schema_version' => 1,
            'as_of' => gmdate('Y-m-d\TH:i:s\Z', $nowEpoch),
            'total_failures' => count($failures),
            'jobs' => $jobs,
        ];
    }
}
