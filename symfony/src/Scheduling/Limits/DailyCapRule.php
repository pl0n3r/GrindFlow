<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Limits;

/** All campaigns contribute to the same tenant/network/account daily ceiling. */
final class DailyCapRule
{
    /** @param array<string, mixed> $candidate @param list<array<string, mixed>> $scheduled */
    public function evaluate(array $candidate, array $scheduled, int $maximumPerDay): LimitDecision
    {
        $at = LimitDecision::instant($candidate['scheduled_at_utc'] ?? null);
        $tz = LimitDecision::timezone($candidate['timezone'] ?? null);
        $scope = LimitDecision::scope($candidate);
        if ($at === null || $tz === null || $scope === null || $maximumPerDay < 1 || $maximumPerDay > 10000 || count($scheduled) > 10000) {
            return LimitDecision::deny('invalid_input');
        }
        $day = $at->setTimezone($tz)->format('Y-m-d');
        $total = 0;
        foreach ($scheduled as $row) {
            if (!is_array($row)) {
                return LimitDecision::deny('invalid_input');
            }
            $otherScope = LimitDecision::scope($row);
            $other = LimitDecision::instant($row['scheduled_at_utc'] ?? null);
            $status = $row['status'] ?? 'scheduled';
            if ($otherScope === null || $other === null || !in_array($status, ['scheduled', 'queued', 'draft', 'cancelled'], true)) {
                return LimitDecision::deny('invalid_input');
            }
            if ($scope === $otherScope && $status !== 'cancelled' && $other->setTimezone($tz)->format('Y-m-d') === $day) {
                ++$total;
                if ($total >= $maximumPerDay) {
                    return LimitDecision::deny('daily_cap');
                }
            }
        }
        return LimitDecision::allow();
    }
}
