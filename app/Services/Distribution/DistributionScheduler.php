<?php

namespace App\Services\Distribution;

use App\Models\ScheduledPublication;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DistributionScheduler
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PublicationDeliveryManager $deliveries,
    ) {}

    public function dispatchDue(): int
    {
        if (
            Schema::hasTable('scheduled_publications') === false
            || Schema::hasTable('publication_deliveries') === false
        ) {
            return 0;
        }

        $dispatched = 0;
        $afterDue = null;
        $afterId = null;

        while ($dispatched < 20) {
            $publications = $this->candidatePage($afterDue, $afterId);

            if ($publications->isEmpty()) {
                break;
            }

            foreach ($publications as $publication) {
                if ($dispatched >= 20) {
                    break;
                }

                if ($this->dispatchCandidate($publication)) {
                    $dispatched++;
                }
            }

            $last = $publications->last();
            $rawDue = $last?->getRawOriginal('scheduled_for_utc');

            if (
                $last === null
                || is_string($rawDue) === false
                || $rawDue === ''
                || $publications->count() < 20
            ) {
                break;
            }

            $afterDue = $rawDue;
            $afterId = (string) $last->getKey();
        }

        return $dispatched;
    }

    /**
     * @return Collection<int, ScheduledPublication>
     */
    private function candidatePage(
        ?string $afterDue,
        ?string $afterId,
    ): Collection {
        $query = ScheduledPublication::query()
            ->withoutGlobalScope(TenantScope::class)
            ->leftJoin(
                'publication_deliveries as delivery',
                function (JoinClause $join): void {
                    $join
                        ->on(
                            'delivery.scheduled_publication_id',
                            '=',
                            'scheduled_publications.id',
                        )
                        ->on(
                            'delivery.organization_id',
                            '=',
                            'scheduled_publications.organization_id',
                        );
                },
            )
            ->select('scheduled_publications.*')
            ->where(
                'scheduled_publications.status',
                ScheduledPublication::STATUS_SCHEDULED,
            )
            ->where(
                'scheduled_publications.scheduled_for_utc',
                '<=',
                now('UTC'),
            )
            ->where(function (Builder $candidate): void {
                $candidate
                    ->whereNull('delivery.id')
                    ->orWhere(function (Builder $retry): void {
                        $retry
                            ->where(
                                'delivery.status',
                                'retry_scheduled',
                            )
                            ->where(function (Builder $due): void {
                                $due
                                    ->whereNull('delivery.next_attempt_at')
                                    ->orWhere(
                                        'delivery.next_attempt_at',
                                        '<=',
                                        now('UTC'),
                                    );
                            });
                    })
                    ->orWhere(function (Builder $abandoned): void {
                        $abandoned
                            ->whereIn(
                                'delivery.status',
                                ['queued', 'processing'],
                            )
                            ->where(function (Builder $expired): void {
                                $expired
                                    ->whereNull('delivery.claimed_until')
                                    ->orWhere(
                                        'delivery.claimed_until',
                                        '<=',
                                        now('UTC'),
                                    );
                            });
                    });
            });

        if ($afterDue !== null && $afterId !== null) {
            $query->where(function (Builder $cursor) use (
                $afterDue,
                $afterId,
            ): void {
                $cursor
                    ->where(
                        'scheduled_publications.scheduled_for_utc',
                        '>',
                        $afterDue,
                    )
                    ->orWhere(function (Builder $sameDue) use (
                        $afterDue,
                        $afterId,
                    ): void {
                        $sameDue
                            ->where(
                                'scheduled_publications.scheduled_for_utc',
                                $afterDue,
                            )
                            ->where(
                                'scheduled_publications.id',
                                '>',
                                $afterId,
                            );
                    });
            });
        }

        return $query
            ->orderBy('scheduled_publications.scheduled_for_utc')
            ->orderBy('scheduled_publications.id')
            ->limit(20)
            ->get();
    }

    private function dispatchCandidate(
        ScheduledPublication $publication,
    ): bool {
        $actorId = $publication->scheduled_by_user_id;

        if (is_string($actorId) === false || $actorId === '') {
            return false;
        }

        $actor = User::query()->find($actorId);

        if (
            $actor === null
            || $actor->canScheduleOrganization(
                (string) $publication->organization_id,
            ) === false
        ) {
            return false;
        }

        try {
            return $this->tenantContext->runWithinOrganization(
                $actor,
                (string) $publication->organization_id,
                fn (): bool => $this->deliveries->queue(
                    ScheduledPublication::query()
                        ->findOrFail($publication->getKey()),
                    $actor,
                ),
            );
        } catch (AuthorizationException) {
            return false;
        }
    }
}
