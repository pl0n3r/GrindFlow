<?php
declare(strict_types=1);

namespace GrindFlow\Scheduling\Preview;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BulkSchedulePreview
{
    /** Preview only: never reads or writes database or calls a scheduler. */
    public static function build(string $tenantId, array $existing, array $operations): array
    {
        self::identifier($tenantId);
        if (count($existing) > 1000 || count($operations) > 200) {
            throw new InvalidArgumentException('schedule_input_too_large');
        }
        $rows = [];
        foreach ($existing as $entry) {
            self::entry($entry, ['id', 'tenant_id', 'starts_at_utc'], $tenantId);
            if (isset($rows[$entry['id']])) {
                throw new InvalidArgumentException('duplicate_schedule_id');
            }
            $rows[$entry['id']] = $entry['starts_at_utc'];
        }
        ksort($rows);
        $desired = $rows;
        $created = [];
        $moved = [];
        $normalized = [];
        $touched = [];
        foreach ($operations as $operation) {
            self::entry($operation, ['kind', 'id', 'tenant_id', 'starts_at_utc'], $tenantId);
            $kind = $operation['kind'];
            $id = $operation['id'];
            if (!in_array($kind, ['create', 'move'], true) || isset($touched[$id])) {
                throw new InvalidArgumentException('invalid_schedule_operation');
            }
            $touched[$id] = true;
            if (($kind === 'move') !== isset($rows[$id])) {
                throw new InvalidArgumentException('schedule_target_mismatch');
            }
            $to = $operation['starts_at_utc'];
            $normalized[] = ['kind' => $kind, 'id' => $id, 'starts_at_utc' => $to];
            if ($kind === 'create') {
                $created[] = ['id' => $id, 'starts_at_utc' => $to];
            } elseif ($rows[$id] !== $to) {
                $moved[] = ['id' => $id, 'from' => $rows[$id], 'to' => $to];
            }
            $desired[$id] = $to;
        }
        usort($normalized, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        usort($created, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        usort($moved, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        $slots = [];
        foreach ($desired as $id => $instant) {
            $slots[$instant][] = $id;
        }
        $conflicts = [];
        foreach (array_keys($touched) as $id) {
            if (count($slots[$desired[$id]]) > 1) {
                $conflicts[] = ['id' => $id, 'reason' => 'slot_occupied'];
            }
        }
        usort($conflicts, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        $digest = hash('sha256', json_encode([$tenantId, $rows, $normalized], JSON_THROW_ON_ERROR));
        return [
            'version' => 1, 'status' => $conflicts === [] ? 'ready' : 'conflict',
            'input_sha256' => $digest, 'creates' => $created, 'moves' => $moved,
            'conflicts' => $conflicts, 'execution' => false,
        ];
    }

    /** Continuity guard, NOT an authorization or an apply operation. */
    public static function matches(string $tenantId, array $existing, array $operations, array $previous): bool
    {
        try {
            $current = self::build($tenantId, $existing, $operations);
            return $current['status'] === 'ready'
                && isset($previous['input_sha256']) && is_string($previous['input_sha256'])
                && hash_equals($current['input_sha256'], $previous['input_sha256'])
                && $current === $previous;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function entry(mixed $entry, array $fields, string $tenantId): void
    {
        if (!is_array($entry) || array_is_list($entry)
            || count($entry) !== count($fields)
            || array_diff($fields, array_keys($entry)) !== []
            || array_diff(array_keys($entry), $fields) !== []
            || !is_string($entry['tenant_id']) || $entry['tenant_id'] !== $tenantId) {
            throw new InvalidArgumentException('invalid_or_foreign_schedule');
        }
        self::identifier($entry['id']);
        $instant = $entry['starts_at_utc'];
        if (!is_string($instant)) {
            throw new InvalidArgumentException('invalid_schedule_time');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $instant, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $instant) {
            throw new InvalidArgumentException('invalid_schedule_time');
        }
    }

    private static function identifier(string $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_schedule_identifier');
        }
    }
}
