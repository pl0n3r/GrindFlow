<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Studio;

/** Computes daily preparation/approval bottlenecks, without writes. */
final class TeamCapacityAnalyzer
{
    public function analyze(string $tenantId, array $members, array $tasks): array
    {
        if (!self::identifier($tenantId) || !array_is_list($members) || !array_is_list($tasks)
            || count($members) > 64 || count($tasks) > 500) {
            return self::blocked();
        }
        $capacity = [];
        foreach ($members as $member) {
            if (!is_array($member) || !self::hasExactKeys($member, ['tenant_id', 'member_id', 'daily_capacity'])
                || $member['tenant_id'] !== $tenantId || !self::identifier($member['member_id'])
                || isset($capacity[$member['member_id']])
                || !is_int($member['daily_capacity'])
                || $member['daily_capacity'] < 0 || $member['daily_capacity'] > 100) {
                return self::blocked();
            }
            $capacity[$member['member_id']] = $member['daily_capacity'];
        }
        $daily = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || !self::hasExactKeys($task,
                ['tenant_id', 'creator_id', 'member_id', 'stage', 'day'])
                || $task['tenant_id'] !== $tenantId || !self::identifier($task['creator_id'])
                || !self::identifier($task['member_id'])
                || !array_key_exists($task['member_id'], $capacity)
                || !in_array($task['stage'], ['preparation', 'approval'], true)
                || !self::validDay($task['day'])) {
                return self::blocked();
            }
            $key = $task['member_id'] . ':' . $task['day'];
            if (!isset($daily[$key])) {
                $daily[$key] = ['member_id' => $task['member_id'], 'day' => $task['day'],
                    'capacity' => $capacity[$task['member_id']], 'preparation' => 0, 'approval' => 0];
            }
            $daily[$key][$task['stage']]++;
        }
        $bottlenecks = [];
        foreach ($daily as $load) {
            $total = $load['preparation'] + $load['approval'];
            if ($total > $load['capacity']) {
                $bottlenecks[] = $load + ['total' => $total, 'reason' => 'daily_capacity_exceeded'];
            }
        }
        return ['status' => 'ok', 'task_count' => count($tasks), 'bottlenecks' => $bottlenecks,
            'execution_performed' => false];
    }

    private static function validDay(mixed $day): bool
    {
        if (!is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $day;
    }

    /** Accept an associative record regardless of serialization field order. */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        return count($value) === count($expected)
            && array_diff_key($value, array_fill_keys($expected, true)) === [];
    }

    private static function identifier(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $v) === 1;
    }

    private static function blocked(): array
    {
        return ['status' => 'blocked', 'reason' => 'invalid_or_unauthorized_capacity',
            'execution_performed' => false];
    }
}
