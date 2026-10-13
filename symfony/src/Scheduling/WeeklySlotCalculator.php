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
    public function upcoming(array|false $rule, ?\DateTimeImmutable $clock = null): array
    {
        if ($rule === false) {
            return [];
        }

        $timezoneName = (string) $rule['timezone'];
        $timezone = new \DateTimeZone($timezoneName);
        $now = $clock === null
            ? new \DateTimeImmutable('now', $timezone)
            : $clock->setTimezone($timezone);
        $dayStart = $now->setTime(0, 0);
        $weekdays = $rule['weekdays'] === '' ? [] : explode(',', (string) $rule['weekdays']);
        $times = $rule['local_times'] ?? [(string) $rule['local_time']];
        if (!is_array($times) || $times === [] || count($times) > 12) {
            return [];
        }
        $seen = [];
        $slots = [];

        for ($offset = 0; $offset <= 7; ++$offset) {
            $date = $dayStart->modify(sprintf('+%d days', $offset));
            $weekday = strtolower($date->format('D'));
            if (!in_array($weekday, $weekdays, true)) {
                continue;
            }

            if (isset($seen[$weekday])) {
                continue;
            }
            $hasUpcoming = false;
            foreach ($times as $time) {
                if (!is_string($time)
                    || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) {
                    return [];
                }
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $candidate = $date->setTime($hour, $minute);
                // On spring-forward, local times can be nonexistent. Skip,
                // rather than silently changing a requested user time.
                if ($candidate->format('H:i') !== $time || $candidate <= $now) {
                    continue;
                }
                $hasUpcoming = true;
                $slots[] = [
                    'local_date' => $candidate->format('Y-m-d'),
                    'weekday' => $weekday,
                    'local_time' => $time,
                    'timezone' => $timezoneName,
                    'capacity' => (int) $rule['max_per_day'],
                    'scheduled_at_utc' => $candidate
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d\TH:i:s\Z'),
                ];
            }
            if ($hasUpcoming) {
                $seen[$weekday] = true;
            }
        }

        usort($slots, static fn (array $a, array $b): int => strcmp($a['scheduled_at_utc'], $b['scheduled_at_utc']));
        return array_slice($slots, 0, 84);
    }
}
