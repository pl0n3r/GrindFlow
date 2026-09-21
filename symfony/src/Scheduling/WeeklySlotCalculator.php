<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling;

/**
 * Shared source of truth for S3 preview and S4 internal draft slots.
 *
 * The returned instants are informational until an authenticated scheduler
 * validates a specific slot and persists its own tenant-scoped draft.
 */
final class WeeklySlotCalculator
{
    /**
     * @param array<string, mixed>|false $rule
     * @return list<array{local_date: string, weekday: string, local_time: string, timezone: string, capacity: int, scheduled_at_utc: string}>
     */
    public function upcoming(array|false $rule): array
    {
        if ($rule === false) {
            return [];
        }

        $timezoneName = (string) $rule['timezone'];
        $timezone = new \DateTimeZone($timezoneName);
        $now = new \DateTimeImmutable('now', $timezone);
        $dayStart = $now->setTime(0, 0);
        $weekdays = $rule['weekdays'] === '' ? [] : explode(',', (string) $rule['weekdays']);
        [$hour, $minute] = array_map('intval', explode(':', (string) $rule['local_time']));
        $seen = [];
        $slots = [];

        for ($offset = 0; $offset <= 7 && count($seen) < count($weekdays); ++$offset) {
            $date = $dayStart->modify(sprintf('+%d days', $offset));
            $weekday = strtolower($date->format('D'));
            if (!in_array($weekday, $weekdays, true) || isset($seen[$weekday])) {
                continue;
            }

            $candidate = $date->setTime($hour, $minute);
            if ($candidate <= $now) {
                continue;
            }

            $seen[$weekday] = true;
            $slots[] = [
                'local_date' => $candidate->format('Y-m-d'),
                'weekday' => $weekday,
                'local_time' => (string) $rule['local_time'],
                'timezone' => $timezoneName,
                'capacity' => (int) $rule['max_per_day'],
                'scheduled_at_utc' => $candidate
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\\TH:i:s\\Z'),
            ];
        }

        return $slots;
    }
}
