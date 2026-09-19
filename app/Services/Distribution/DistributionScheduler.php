<?php

namespace App\Services\Distribution;

use App\Models\ScheduledPublication;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
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
            ->where('status', ScheduledPublication::STATUS_SCHEDULED)
            ->where('scheduled_for_utc', '<=', now('UTC'))
            ->orderBy('scheduled_for_utc')
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

            $queued = $this->tenantContext->runWithinOrganization(
                $actor,
                (string) $publication->organization_id,
                fn (): bool => $this->deliveries->queue(
                    ScheduledPublication::query()
                        ->findOrFail($publication->getKey()),
                    $actor,
                ),
            );

            if ($queued) {
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
