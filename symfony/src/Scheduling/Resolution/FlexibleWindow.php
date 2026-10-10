<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Resolution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure UTC slot selection for Scheduling build-ahead. Never publishes a slot.
 * Inputs are exact/fixed, a bounded range or a preferred suggestion.
 */
final class FlexibleWindow
{
    public static function instant(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_utc_instant');
        }
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, $utc);
        if ($date === false || $date->format('Y-m-d\\TH:i:s\\Z') !== $value) {
            throw new InvalidArgumentException('invalid_utc_instant');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $window
     * @return list<string>
     */
    public function candidates(array $window, string $preferredUtc): array
    {
        self::instant($preferredUtc);
        $kind = $window['kind'] ?? null;
        if ($kind === 'exact') {
            if ($this->keys($window) !== ['at_utc', 'kind']) {
                throw new InvalidArgumentException('invalid_window');
            }
            $at = self::instant($window['at_utc']);
            if ($at !== $preferredUtc) {
                throw new InvalidArgumentException('window_excludes_original');
            }

            return [$at];
        }
        if (!in_array($kind, ['range', 'suggested'], true) ||
            $this->keys($window) !== ['end_utc', 'kind', 'start_utc', 'step_minutes']) {
            throw new InvalidArgumentException('invalid_window');
        }

        $start = self::instant($window['start_utc']);
        $end = self::instant($window['end_utc']);
        $step = $window['step_minutes'];
        if (!is_int($step) || $step < 15 || $step > 1440) {
            throw new InvalidArgumentException('invalid_window');
        }
        $startSeconds = strtotime($start);
        $endSeconds = strtotime($end);
        if ($startSeconds === false || $endSeconds === false ||
            $endSeconds < $startSeconds || $endSeconds - $startSeconds > 86400) {
            throw new InvalidArgumentException('invalid_window');
        }
        $slots = [];
        for ($seconds = $startSeconds; $seconds <= $endSeconds; $seconds += $step * 60) {
            $slots[] = gmdate('Y-m-d\\TH:i:s\\Z', $seconds);
        }
        if (!in_array($preferredUtc, $slots, true)) {
            throw new InvalidArgumentException('window_excludes_original');
        }

        // Move as little as possible in time when the original instant is occupied.
        $preferredSeconds = strtotime($preferredUtc);
        usort($slots, static function (string $a, string $b) use ($preferredSeconds): int {
            $delta = abs(strtotime($a) - $preferredSeconds) <=> abs(strtotime($b) - $preferredSeconds);
            return $delta !== 0 ? $delta : strcmp($a, $b);
        });

        return $slots;
    }

    /**
     * @param array<string, mixed> $window
     * @param list<string> $occupied
     */
    public function choose(array $window, string $preferredUtc, array $occupied): ?string
    {
        if (!array_is_list($occupied) || count($occupied) > 100 ||
            count(array_unique($occupied)) !== count($occupied)) {
            throw new InvalidArgumentException('invalid_occupied_slots');
        }
        foreach ($occupied as $instant) {
            self::instant($instant);
        }
        foreach ($this->candidates($window, $preferredUtc) as $candidate) {
            if (!in_array($candidate, $occupied, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $value @return list<string> */
    private function keys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);
        return $keys;
    }
}
