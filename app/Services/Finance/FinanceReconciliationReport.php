<?php

namespace App\Services\Finance;

use App\Models\RevenueAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only, event-date reporting over the immutable tenant-owned ledger.
 *
 * A reversal belongs to its own occurred_on date. A filtered period is a
 * period of recorded events, not a claim that all older revenue was settled.
 */
class FinanceReconciliationReport
{
    /**
     * @param  array<string, string>  $filters
     * @return Builder<RevenueAllocation>
     */
    public function filtered(array $filters): Builder
    {
        return RevenueAllocation::query()
            ->when(
                $filters['currency'] ?? null,
                fn (Builder $query, string $currency): Builder => $query->where('currency', $currency),
            )
            ->when(
                ($filters['beneficiary'] ?? null) === 'unassigned',
                fn (Builder $query): Builder => $query->whereNull('beneficiary_user_id'),
            )
            ->when(
                isset($filters['beneficiary']) && $filters['beneficiary'] !== 'unassigned'
                    ? $filters['beneficiary']
                    : null,
                fn (Builder $query, string $beneficiary): Builder => $query->where('beneficiary_user_id', $beneficiary),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $query, string $from): Builder => $query->whereDate('occurred_on', '>=', $from),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $query, string $to): Builder => $query->whereDate('occurred_on', '<=', $to),
            );
    }

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, array{currency: string, allocated_minor: int, reversed_minor: int, net_minor: int}>
     */
    public function currencySummaries(array $filters): Collection
    {
        return $this->filtered($filters)
            ->selectRaw(
                'currency, '
                .'SUM(CASE WHEN reversal_of_id IS NULL THEN amount_minor ELSE 0 END) AS allocated_minor, '
                .'SUM(CASE WHEN reversal_of_id IS NOT NULL THEN amount_minor ELSE 0 END) AS reversed_minor',
            )
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(static function (RevenueAllocation $summary): array {
                $allocated = (int) $summary->getAttribute('allocated_minor');
                $reversed = (int) $summary->getAttribute('reversed_minor');

                return [
                    'currency' => (string) $summary->currency,
                    'allocated_minor' => $allocated,
                    'reversed_minor' => $reversed,
                    'net_minor' => $allocated - $reversed,
                ];
            });
    }

    /**
     * Return ALL matching beneficiary/currency groups, not just the first
     * page of ledger entries, so UI and CSV reconcile to currency totals.
     *
     * @param  array<string, string>  $filters
     * @return Collection<int, array{beneficiary_user_id: ?string, currency: string, events: int, allocated_minor: int, reversed_minor: int, net_minor: int}>
     */
    public function beneficiarySummaries(array $filters): Collection
    {
        return $this->filtered($filters)
            ->selectRaw(
                'beneficiary_user_id, currency, COUNT(*) AS event_count, '
                .'SUM(CASE WHEN reversal_of_id IS NULL THEN amount_minor ELSE 0 END) AS allocated_minor, '
                .'SUM(CASE WHEN reversal_of_id IS NOT NULL THEN amount_minor ELSE 0 END) AS reversed_minor',
            )
            ->groupBy('beneficiary_user_id', 'currency')
            ->orderBy('currency')
            ->orderBy('beneficiary_user_id')
            ->get()
            ->map(static function (RevenueAllocation $row): array {
                $allocated = (int) $row->getAttribute('allocated_minor');
                $reversed = (int) $row->getAttribute('reversed_minor');

                return [
                    'beneficiary_user_id' => $row->beneficiary_user_id === null
                        ? null
                        : (string) $row->beneficiary_user_id,
                    'currency' => (string) $row->currency,
                    'events' => (int) $row->getAttribute('event_count'),
                    'allocated_minor' => $allocated,
                    'reversed_minor' => $reversed,
                    'net_minor' => $allocated - $reversed,
                ];
            });
    }
}
