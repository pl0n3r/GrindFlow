<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Limits;

/** Evaluate blocked local windows by resolving each candidate from an exact UTC instant. */
final class BlockedWindowRule
{
    /**
     * @param array<string, mixed> $candidate
     * @param list<array{weekday:int,start:string,end:string}> $windows Weekdays ISO 1..7; [start,end) local.
     * @param list<string> $blockedDates Local YYYY-MM-DD dates.
     */
    public function evaluate(array $candidate, array $windows, array $blockedDates, int $lookaheadDays = 7): LimitDecision
    {
        $at = LimitDecision::instant($candidate['scheduled_at_utc'] ?? null);
        $tz = LimitDecision::timezone($candidate['timezone'] ?? null);
        if ($at === null || $tz === null || LimitDecision::scope($candidate) === null || $lookaheadDays < 1 || $lookaheadDays > 14
            || count($windows) > 100 || count($blockedDates) > 1000) {
            return LimitDecision::deny('invalid_input');
        }
        foreach ($windows as $window) {
            if (!is_array($window) || !isset($window['weekday'], $window['start'], $window['end'])
                || !is_int($window['weekday']) || $window['weekday'] < 1 || $window['weekday'] > 7
                || !$this->clock($window['start']) || !$this->clock($window['end']) || $window['start'] >= $window['end']) {
                return LimitDecision::deny('invalid_input');
            }
        }
        foreach ($blockedDates as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                return LimitDecision::deny('invalid_input');
            }
        }
        if (!$this->isBlocked($at, $tz, $windows, $blockedDates)) {
            return LimitDecision::allow();
        }
        // Finite, monotonically increasing UTC search: works through DST gaps/folds.
        for ($minutes = 1; $minutes <= $lookaheadDays * 1440; ++$minutes) {
            $next = $at->modify('+' . $minutes . ' minutes');
            if (!$this->isBlocked($next, $tz, $windows, $blockedDates)) {
                return LimitDecision::deny('blocked_window', $next->format('Y-m-d\TH:i:s\Z'));
            }
        }
        return LimitDecision::deny('blocked_no_safe_relocation');
    }

    private function clock(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value);
    }

    private function isBlocked(\DateTimeImmutable $utc, \DateTimeZone $tz, array $windows, array $dates): bool
    {
        $local = $utc->setTimezone($tz);
        if (in_array($local->format('Y-m-d'), $dates, true)) {
            return true;
        }
        $weekday = (int) $local->format('N');
        $clock = $local->format('H:i');
        foreach ($windows as $window) {
            if ($window['weekday'] === $weekday && $clock >= $window['start'] && $clock < $window['end']) {
                return true;
            }
        }
        return false;
    }
}
