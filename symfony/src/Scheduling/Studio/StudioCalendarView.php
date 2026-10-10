<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Studio;

/** Produces a read-only calendar model from already-authorized evidence. */
final class StudioCalendarView
{
    public function aggregate(string $tenantId, array $creatorIds, array $slots): array
    {
        if (!self::identifier($tenantId) || count($creatorIds) > 64 || count($slots) > 200
            || !array_is_list($creatorIds) || !array_is_list($slots)) {
            return self::blocked();
        }
        $visible = [];
        foreach ($creatorIds as $creatorId) {
            if (!self::identifier($creatorId) || isset($visible[$creatorId])) {
                return self::blocked();
            }
            $visible[$creatorId] = ['preparing' => 0, 'awaiting_approval' => 0, 'approved' => 0];
        }
        if ($visible === []) {
            return self::blocked();
        }
        $seen = [];
        $items = [];
        foreach ($slots as $slot) {
            if (!is_array($slot) || !self::hasExactKeys($slot,
                ['tenant_id', 'creator_id', 'slot_id', 'day', 'state'])
                || $slot['tenant_id'] !== $tenantId
                || !self::identifier($slot['creator_id'])
                || !array_key_exists($slot['creator_id'], $visible)
                || !self::identifier($slot['slot_id'])
                || !self::validDay($slot['day'])
                || !is_string($slot['state'])
                || !in_array($slot['state'], ['preparing', 'awaiting_approval', 'approved'], true)
                || isset($seen[$slot['slot_id']])) {
                return self::blocked();
            }
            $seen[$slot['slot_id']] = true;
            $visible[$slot['creator_id']][$slot['state']]++;
            $items[] = ['creator_id' => $slot['creator_id'], 'slot_id' => $slot['slot_id'],
                'day' => $slot['day'], 'state' => $slot['state']];
        }
        return ['status' => 'ok', 'total' => count($items), 'by_creator' => $visible,
            'slots' => $items, 'execution_performed' => false];
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
        return ['status' => 'blocked', 'reason' => 'invalid_or_unauthorized_evidence',
            'execution_performed' => false];
    }
}
