<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Limits;

/** Uses absolute UTC instants; caller must authorize assets before evaluating. */
final class MinimumSeparationRule
{
    /**
     * @param array<string, mixed> $candidate
     * @param list<array<string, mixed>> $scheduled All campaigns, tenant-scoped by the rule itself.
     */
    public function evaluate(array $candidate, array $scheduled, int $minimumMinutes): LimitDecision
    {
        $at = LimitDecision::instant($candidate['scheduled_at_utc'] ?? null);
        $scope = LimitDecision::scope($candidate);
        if ($at === null || $scope === null || $minimumMinutes < 1 || $minimumMinutes > 10080 || count($scheduled) > 10000) {
            return LimitDecision::deny('invalid_input');
        }
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
            if ($scope === $otherScope && $status !== 'cancelled'
                && abs($at->getTimestamp() - $other->getTimestamp()) < $minimumMinutes * 60) {
                return LimitDecision::deny('min_separation');
            }
        }
        return LimitDecision::allow();
    }
}
