<?php

namespace App\Services\Scheduling;

use App\Models\MediaAsset;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\ScheduledPublicationLink;
use App\Models\Scopes\TenantScope;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContentScheduler
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MediaAssetProcessor $processor,
    ) {}

    public function schedule(
        MediaAsset $asset,
        PublishingDestination $destination,
        User $actor,
        string $localDateTime,
        string $timezone,
        ?string $trackedLinkId = null,
    ): ScheduledPublication {
        return $this->scheduleMany(
            $asset,
            collect([$destination]),
            $actor,
            $localDateTime,
            $timezone,
            $trackedLinkId,
            null,
        )->firstOrFail();
    }

    /**
     * @param  Collection<int, PublishingDestination>  $destinations
     * @return Collection<int, ScheduledPublication>
     */
    public function scheduleMany(
        MediaAsset $asset,
        Collection $destinations,
        User $actor,
        string $localDateTime,
        string $timezone,
        ?string $trackedLinkId,
        ?string $requestKey,
    ): Collection {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || (string) $asset->organization_id !== $organizationId
            || $destinations->isEmpty()
            || $destinations->contains(
                fn (PublishingDestination $destination): bool => (string) $destination->organization_id !== $organizationId,
            )
        ) {
            throw new AuthorizationException(
                'The active tenant does not match this scheduling request.',
            );
        }

        if ($actor->canScheduleOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot schedule content for this organization.',
            );
        }

        if ($destinations->contains(
            fn (PublishingDestination $destination): bool => $destination->status !== PublishingDestination::STATUS_ACTIVE,
        )) {
            throw ValidationException::withMessages([
                'destination_id' => 'The selected destination is not active.',
            ]);
        }

        if (
            $trackedLinkId !== null
            && (
                Schema::hasTable('tracked_links') === false
                || Schema::hasTable('scheduled_publication_links') === false
            )
        ) {
            abort(503, 'Tracked-link scheduling requires its database migration.');
        }

        $scheduledForUtc = $this->scheduledForUtc(
            $localDateTime,
            $timezone,
        );

        return DB::transaction(function () use (
            $asset,
            $destinations,
            $actor,
            $scheduledForUtc,
            $timezone,
            $trackedLinkId,
            $organizationId,
            $requestKey,
        ): Collection {
            $lockedAsset = MediaAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isAssetEligible($lockedAsset) === false) {
                throw ValidationException::withMessages([
                    'asset_id' => 'The selected media is not ready for scheduling.',
                ]);
            }

            $lockedDestinations = PublishingDestination::query()
                ->whereIn('id', $destinations->pluck('id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (
                $lockedDestinations->count() !== $destinations->count()
                || $lockedDestinations->contains(
                    fn (PublishingDestination $destination): bool => $destination->status !== PublishingDestination::STATUS_ACTIVE,
                )
            ) {
                throw ValidationException::withMessages([
                    'destination_ids' => 'Every selected destination must be active.',
                ]);
            }

            if ($scheduledForUtc->isFuture() === false) {
                throw ValidationException::withMessages([
                    'scheduled_for_local' => 'The scheduled time must be in the future.',
                ]);
            }

            $trackedLink = null;

            if ($trackedLinkId !== null) {
                $trackedLink = TrackedLink::query()
                    ->whereKey($trackedLinkId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $trackedLink->organization_id !== $organizationId) {
                    throw new AuthorizationException(
                        'The tracked link belongs to another organization.',
                    );
                }

                if ($trackedLink->status !== TrackedLink::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'tracked_link_id' => 'The selected tracked link is not active.',
                    ]);
                }
            }

            $hasRequestKeyColumn = Schema::hasColumn('scheduled_publications', 'request_key');

            return $lockedDestinations->map(function (PublishingDestination $destination) use (
                $lockedAsset,
                $actor,
                $scheduledForUtc,
                $timezone,
                $trackedLink,
                $requestKey,
                $hasRequestKeyColumn,
            ): ScheduledPublication {
                $attributes = [
                    'media_asset_id' => $lockedAsset->getKey(),
                    'publishing_destination_id' => $destination->getKey(),
                    'scheduled_by_user_id' => $actor->getKey(),
                    'status' => ScheduledPublication::STATUS_SCHEDULED,
                    'scheduled_for_utc' => $scheduledForUtc,
                    'timezone' => $timezone,
                ];

                if ($hasRequestKeyColumn) {
                    $attributes['request_key'] = $requestKey;
                }

                $publication = $requestKey === null || $hasRequestKeyColumn === false
                    ? ScheduledPublication::query()->create($attributes)
                    : ScheduledPublication::query()->firstOrCreate(
                        [
                            'request_key' => $requestKey,
                            'publishing_destination_id' => $destination->getKey(),
                        ],
                        $attributes,
                    );

                if ($trackedLink !== null) {
                    ScheduledPublicationLink::query()->firstOrCreate([
                        'scheduled_publication_id' => $publication->getKey(),
                    ], ['tracked_link_id' => $trackedLink->getKey()]);
                }

                return $publication;
            });
        });
    }

    public function reschedule(
        ScheduledPublication $publication,
        User $actor,
        string $localDateTime,
        string $timezone,
    ): void {
        $organizationId = $this->tenantContext->organizationId();
        if (
            $organizationId === null
            || (string) $publication->organization_id !== $organizationId
            || $actor->canScheduleOrganization($organizationId) === false
        ) {
            throw new AuthorizationException('The user cannot edit this schedule.');
        }

        $due = $this->scheduledForUtc($localDateTime, $timezone);
        DB::transaction(function () use ($publication, $due, $timezone): void {
            $locked = ScheduledPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            if (
                $locked->status !== ScheduledPublication::STATUS_SCHEDULED
                || $locked->scheduled_for_utc?->isFuture() !== true
                || $locked->delivery()->exists()
            ) {
                throw ValidationException::withMessages([
                    'schedule' => 'Only future, undelivered schedules can be edited.',
                ]);
            }

            $locked->forceFill(['scheduled_for_utc' => $due, 'timezone' => $timezone])->save();
        });
    }

    /**
     * Replace or detach the tracked link while the schedule is still editable.
     * This operation changes only the assignment: neither the public link
     * nor its historical click aggregates are ever removed or rotated.
     */
    public function updateTrackedLink(
        ScheduledPublication $publication,
        User $actor,
        ?string $trackedLinkId,
    ): void {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || (string) $publication->organization_id !== $organizationId
            || $actor->canScheduleOrganization($organizationId) === false
        ) {
            throw new AuthorizationException('The user cannot edit this schedule.');
        }

        if (
            Schema::hasTable('tracked_links') === false
            || Schema::hasTable('scheduled_publication_links') === false
        ) {
            abort(503, 'Tracked-link scheduling requires its database migration.');
        }

        DB::transaction(function () use ($publication, $trackedLinkId, $organizationId): void {
            $locked = ScheduledPublication::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('organization_id', $organizationId)
                ->whereKey($publication->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->status !== ScheduledPublication::STATUS_SCHEDULED
                || $locked->scheduled_for_utc?->isFuture() !== true
                || $locked->delivery()->exists()
            ) {
                throw ValidationException::withMessages([
                    'tracked_link_id' => 'Only future, undelivered schedules can change their tracked link.',
                ]);
            }

            $trackedLink = null;

            if ($trackedLinkId !== null) {
                $trackedLink = TrackedLink::query()
                    ->whereKey($trackedLinkId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (string) $trackedLink->organization_id !== $organizationId
                ) {
                    throw new AuthorizationException(
                        'The tracked link belongs to another organization.',
                    );
                }

                if ($trackedLink->status !== TrackedLink::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'tracked_link_id' => 'The selected tracked link is not active.',
                    ]);
                }
            }

            $assignment = ScheduledPublicationLink::query()
                ->where('scheduled_publication_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($trackedLink === null) {
                $assignment?->delete();

                return;
            }

            if ($assignment === null) {
                ScheduledPublicationLink::query()->create([
                    'scheduled_publication_id' => $locked->getKey(),
                    'tracked_link_id' => $trackedLink->getKey(),
                ]);

                return;
            }

            if ((string) $assignment->tracked_link_id !== (string) $trackedLink->getKey()) {
                $assignment->forceFill([
                    'tracked_link_id' => $trackedLink->getKey(),
                ])->save();
            }
        });
    }

    public function cancel(ScheduledPublication $publication, User $actor): void
    {
        $organizationId = $this->tenantContext->organizationId();
        if (
            $organizationId === null
            || (string) $publication->organization_id !== $organizationId
            || $actor->canScheduleOrganization($organizationId) === false
        ) {
            throw new AuthorizationException('The user cannot cancel this schedule.');
        }

        DB::transaction(function () use ($publication): void {
            $locked = ScheduledPublication::query()->lockForUpdate()->findOrFail($publication->getKey());
            if (
                $locked->status !== ScheduledPublication::STATUS_SCHEDULED
                || $locked->delivery()->exists()
            ) {
                throw ValidationException::withMessages([
                    'schedule' => 'This schedule can no longer be cancelled.',
                ]);
            }

            $locked->forceFill(['status' => ScheduledPublication::STATUS_CANCELLED])->save();
        });
    }

    /**
     * @return Builder<MediaAsset>
     */
    public function eligibleAssetsQuery(): Builder
    {
        return MediaAsset::query()
            ->where('status', MediaAsset::STATUS_READY)
            ->whereNull('duplicate_of')
            ->where('metadata->processing->status', 'completed')
            ->where(
                'metadata->processing->version',
                $this->processor->currentVersion(),
            );
    }

    public function isAssetEligible(MediaAsset $asset): bool
    {
        if (
            $asset->status !== MediaAsset::STATUS_READY
            || $asset->duplicate_of !== null
        ) {
            return false;
        }

        $metadata = $asset->getAttribute('metadata');

        if (is_array($metadata) === false) {
            return false;
        }

        $processing = $metadata['processing'] ?? null;

        return is_array($processing)
            && ($processing['status'] ?? null) === 'completed'
            && (int) ($processing['version'] ?? 0) === $this->processor->currentVersion();
    }

    private function scheduledForUtc(
        string $localDateTime,
        string $timezone,
    ): CarbonImmutable {
        if (
            in_array(
                $timezone,
                DateTimeZone::listIdentifiers(),
                true,
            ) === false
        ) {
            throw ValidationException::withMessages([
                'timezone' => 'Select a valid IANA timezone.',
            ]);
        }

        try {
            $scheduledFor = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                $localDateTime,
                new DateTimeZone($timezone),
            );
        } catch (Throwable) {
            $scheduledFor = false;
        }

        if (
            $scheduledFor instanceof CarbonImmutable === false
            || $scheduledFor->format('Y-m-d\TH:i') !== $localDateTime
        ) {
            throw ValidationException::withMessages([
                'scheduled_for_local' => 'Enter a valid local date and time.',
            ]);
        }

        $scheduledForUtc = $scheduledFor->setTimezone('UTC');

        if ($scheduledForUtc->isFuture() === false) {
            throw ValidationException::withMessages([
                'scheduled_for_local' => 'The scheduled time must be in the future.',
            ]);
        }

        return $scheduledForUtc;
    }
}
