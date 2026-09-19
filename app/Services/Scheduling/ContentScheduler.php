<?php

namespace App\Services\Scheduling;

use App\Models\MediaAsset;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\ScheduledPublicationLink;
use App\Models\TrackedLink;
use App\Models\User;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || (string) $asset->organization_id !== $organizationId
            || (string) $destination->organization_id !== $organizationId
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

        if ($destination->status !== PublishingDestination::STATUS_ACTIVE) {
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
            $destination,
            $actor,
            $scheduledForUtc,
            $timezone,
            $trackedLinkId,
            $organizationId,
        ): ScheduledPublication {
            $lockedAsset = MediaAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isAssetEligible($lockedAsset) === false) {
                throw ValidationException::withMessages([
                    'asset_id' => 'The selected media is not ready for scheduling.',
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

            $publication = ScheduledPublication::query()->create([
                'media_asset_id' => $lockedAsset->getKey(),
                'publishing_destination_id' => $destination->getKey(),
                'scheduled_by_user_id' => $actor->getKey(),
                'status' => ScheduledPublication::STATUS_SCHEDULED,
                'scheduled_for_utc' => $scheduledForUtc,
                'timezone' => $timezone,
            ]);

            if ($trackedLink !== null) {
                ScheduledPublicationLink::query()->create([
                    'scheduled_publication_id' => $publication->getKey(),
                    'tracked_link_id' => $trackedLink->getKey(),
                ]);
            }

            return $publication;
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
