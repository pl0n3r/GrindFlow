<?php

namespace App\Services\Distribution;

use App\Models\ScheduledPublication;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
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

        $publications = ScheduledPublication::query()
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
            ->where(function (Builder $query): void {
                $query
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
            })
            ->orderBy('scheduled_publications.scheduled_for_utc')
            ->limit(20)
            ->get();

        $dispatched = 0;

        foreach ($publications as $publication) {
            $actorId = $publication->scheduled_by_user_id;

            if (is_string($actorId) === false || $actorId === '') {
                continue;
            }

            $actor = User::query()->find($actorId);

            if (
                $actor === null
                || $actor->canScheduleOrganization(
                    (string) $publication->organization_id,
                ) === false
            ) {
                continue;
            }

            try {
                $queued = $this->tenantContext->runWithinOrganization(
                    $actor,
                    (string) $publication->organization_id,
                    fn (): bool => $this->deliveries->queue(
                        ScheduledPublication::query()
                            ->findOrFail($publication->getKey()),
                        $actor,
                    ),
                );
            } catch (AuthorizationException) {
                continue;
            }

            if ($queued) {
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
