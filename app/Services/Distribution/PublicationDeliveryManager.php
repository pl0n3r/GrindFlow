<?php

namespace App\Services\Distribution;

use App\Jobs\DispatchScheduledPublication;
use App\Models\MediaAsset;
use App\Models\PublicationDelivery;
use App\Models\PublishingDestination;
use App\Models\ScheduledPublication;
use App\Models\User;
use App\Services\Media\MediaAssetProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublicationDeliveryManager
{
    public const MAX_ATTEMPTS = 4;

    private const CLAIM_SECONDS = 300;

    /**
     * @var array<int, int>
     */
    private const TRANSIENT_BACKOFF_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 900,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DistributionProviderRegistry $providers,
        private readonly MediaAssetProcessor $processor,
    ) {}

    public function queue(
        ScheduledPublication $publication,
        User $actor,
    ): bool {
        $organizationId = $this->requireTenant($publication, $actor);

        $delivery = DB::transaction(function () use (
            $publication,
            $organizationId,
        ): ?PublicationDelivery {
            $lockedPublication = ScheduledPublication::query()
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            if (
                $lockedPublication->status !== ScheduledPublication::STATUS_SCHEDULED
                || $lockedPublication->scheduled_for_utc === null
                || $lockedPublication->scheduled_for_utc->isFuture()
            ) {
                return null;
            }

            $delivery = PublicationDelivery::query()
                ->where(
                    'scheduled_publication_id',
                    $lockedPublication->getKey(),
                )
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return PublicationDelivery::query()->create([
                    'scheduled_publication_id' => $lockedPublication->getKey(),
                    'idempotency_key' => $this->idempotencyKey(
                        $organizationId,
                        (string) $lockedPublication->getKey(),
                    ),
                    'status' => PublicationDelivery::STATUS_QUEUED,
                    'claimed_until' => now('UTC')->addSeconds(
                        self::CLAIM_SECONDS,
                    ),
                ]);
            }

            if (
                in_array(
                    $delivery->status,
                    [
                        PublicationDelivery::STATUS_PUBLISHED,
                        PublicationDelivery::STATUS_AUTHENTICATION_FAILED,
                        PublicationDelivery::STATUS_FAILED,
                    ],
                    true,
                )
            ) {
                return null;
            }

            if (
                in_array(
                    $delivery->status,
                    [
                        PublicationDelivery::STATUS_QUEUED,
                        PublicationDelivery::STATUS_PROCESSING,
                    ],
                    true,
                )
                && $delivery->claimed_until !== null
                && $delivery->claimed_until->isFuture()
            ) {
                return null;
            }

            if (
                $delivery->status === PublicationDelivery::STATUS_RETRY_SCHEDULED
                && $delivery->next_attempt_at !== null
                && $delivery->next_attempt_at->isFuture()
            ) {
                return null;
            }

            if ($delivery->attempts >= self::MAX_ATTEMPTS) {
                $delivery->forceFill([
                    'status' => PublicationDelivery::STATUS_FAILED,
                    'next_attempt_at' => null,
                    'claimed_until' => null,
                    'last_error_code' => 'distribution_retry_exhausted',
                ])->save();

                return null;
            }

            $delivery->forceFill([
                'status' => PublicationDelivery::STATUS_QUEUED,
                'next_attempt_at' => null,
                'claimed_until' => now('UTC')->addSeconds(
                    self::CLAIM_SECONDS,
                ),
            ])->save();

            return $delivery;
        });

        if ($delivery === null) {
            return false;
        }

        try {
            app(BusDispatcher::class)->dispatch(
                new DispatchScheduledPublication(
                    (string) $delivery->getKey(),
                    $organizationId,
                    (string) $actor->getKey(),
                ),
            );
        } catch (Throwable $exception) {
            PublicationDelivery::query()
                ->whereKey($delivery->getKey())
                ->where('status', PublicationDelivery::STATUS_QUEUED)
                ->where('attempts', $delivery->attempts)
                ->update([
                    'status' => PublicationDelivery::STATUS_RETRY_SCHEDULED,
                    'next_attempt_at' => now('UTC')->addSeconds(60),
                    'claimed_until' => null,
                    'last_error_code' => 'distribution_dispatch_failed',
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        return true;
    }

    public function dispatch(
        PublicationDelivery $delivery,
        User $actor,
    ): void {
        $this->requireTenant($delivery, $actor);

        $claimed = DB::transaction(function () use (
            $delivery,
        ): ?PublicationDelivery {
            $locked = PublicationDelivery::query()
                ->lockForUpdate()
                ->findOrFail($delivery->getKey());

            if (
                in_array(
                    $locked->status,
                    [
                        PublicationDelivery::STATUS_PUBLISHED,
                        PublicationDelivery::STATUS_AUTHENTICATION_FAILED,
                        PublicationDelivery::STATUS_FAILED,
                    ],
                    true,
                )
            ) {
                return null;
            }

            if (
                $locked->status === PublicationDelivery::STATUS_PROCESSING
                && $locked->claimed_until !== null
                && $locked->claimed_until->isFuture()
            ) {
                return null;
            }

            if (
                $locked->status === PublicationDelivery::STATUS_RETRY_SCHEDULED
                && $locked->next_attempt_at !== null
                && $locked->next_attempt_at->isFuture()
            ) {
                return null;
            }

            if ($locked->attempts >= self::MAX_ATTEMPTS) {
                $locked->forceFill([
                    'status' => PublicationDelivery::STATUS_FAILED,
                    'next_attempt_at' => null,
                    'claimed_until' => null,
                    'last_error_code' => 'distribution_retry_exhausted',
                ])->save();

                return null;
            }

            $locked->forceFill([
                'status' => PublicationDelivery::STATUS_PROCESSING,
                'attempts' => $locked->attempts + 1,
                'next_attempt_at' => null,
                'claimed_until' => now('UTC')->addSeconds(
                    self::CLAIM_SECONDS,
                ),
                'last_error_code' => null,
            ])->save();

            return $locked->refresh();
        });

        if ($claimed === null) {
            return;
        }

        $publication = ScheduledPublication::query()
            ->with([
                'mediaAsset.blob',
                'destination',
            ])
            ->findOrFail($claimed->scheduled_publication_id);

        if ($this->isEligible($publication) === false) {
            $this->markTerminalFailure(
                $claimed,
                'distribution_schedule_ineligible',
            );

            return;
        }

        $provider = $this->providers->resolve(
            (string) $publication->destination?->provider,
        );

        if ($provider === null) {
            $this->markTerminalFailure(
                $claimed,
                'distribution_provider_unsupported',
            );

            return;
        }

        try {
            $result = $provider->publish(
                $publication,
                $claimed->idempotency_key,
            );
        } catch (DistributionProviderException $exception) {
            $this->handleProviderFailure($claimed, $exception);

            return;
        } catch (Throwable) {
            $this->scheduleRetry(
                $claimed,
                'distribution_unexpected_error',
                $this->transientDelay($claimed->attempts),
            );

            return;
        }

        $this->claimedDeliveryQuery($claimed)
            ->update([
                'status' => PublicationDelivery::STATUS_PUBLISHED,
                'next_attempt_at' => null,
                'claimed_until' => null,
                'published_at' => now('UTC'),
                'external_publication_id' => $result->externalPublicationId,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
    }

    private function handleProviderFailure(
        PublicationDelivery $delivery,
        DistributionProviderException $exception,
    ): void {
        if (
            $exception->kind
            === DistributionProviderException::KIND_AUTHENTICATION
        ) {
            $this->claimedDeliveryQuery($delivery)
                ->update([
                    'status' => PublicationDelivery::STATUS_AUTHENTICATION_FAILED,
                    'next_attempt_at' => null,
                    'claimed_until' => null,
                    'last_error_code' => 'distribution_authentication_failed',
                    'updated_at' => now(),
                ]);

            return;
        }

        if (
            $exception->kind
            === DistributionProviderException::KIND_RATE_LIMIT
        ) {
            $delay = max(
                60,
                min($exception->retryAfterSeconds ?? 300, 3600),
            );

            $this->scheduleRetry(
                $delivery,
                'distribution_rate_limited',
                $delay,
                decrementAttempt: true,
            );

            return;
        }

        $this->scheduleRetry(
            $delivery,
            'distribution_transient_failure',
            $this->transientDelay($delivery->attempts),
        );
    }

    private function scheduleRetry(
        PublicationDelivery $delivery,
        string $safeError,
        int $delaySeconds,
        bool $decrementAttempt = false,
    ): void {
        $attempts = $decrementAttempt
            ? max(0, $delivery->attempts - 1)
            : $delivery->attempts;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->claimedDeliveryQuery($delivery)
                ->update([
                    'status' => PublicationDelivery::STATUS_FAILED,
                    'attempts' => $attempts,
                    'next_attempt_at' => null,
                    'claimed_until' => null,
                    'last_error_code' => $safeError.'_exhausted',
                    'updated_at' => now(),
                ]);

            return;
        }

        $this->claimedDeliveryQuery($delivery)
            ->update([
                'status' => PublicationDelivery::STATUS_RETRY_SCHEDULED,
                'attempts' => $attempts,
                'next_attempt_at' => now('UTC')->addSeconds($delaySeconds),
                'claimed_until' => null,
                'last_error_code' => $safeError,
                'updated_at' => now(),
            ]);
    }

    private function markTerminalFailure(
        PublicationDelivery $delivery,
        string $safeError,
    ): void {
        $this->claimedDeliveryQuery($delivery)
            ->update([
                'status' => PublicationDelivery::STATUS_FAILED,
                'next_attempt_at' => null,
                'claimed_until' => null,
                'last_error_code' => $safeError,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return Builder<PublicationDelivery>
     */
    private function claimedDeliveryQuery(
        PublicationDelivery $delivery,
    ): Builder {
        return PublicationDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', PublicationDelivery::STATUS_PROCESSING)
            ->where('attempts', $delivery->attempts);
    }

    private function transientDelay(int $attempts): int
    {
        return self::TRANSIENT_BACKOFF_SECONDS[
            min(max($attempts, 1), 3)
        ];
    }

    private function isEligible(
        ScheduledPublication $publication,
    ): bool {
        $destination = $publication->destination;
        $asset = $publication->mediaAsset;

        if (
            $publication->status !== ScheduledPublication::STATUS_SCHEDULED
            || $publication->scheduled_for_utc === null
            || $publication->scheduled_for_utc->isFuture()
            || $destination instanceof PublishingDestination === false
            || $destination->status !== PublishingDestination::STATUS_ACTIVE
            || $asset instanceof MediaAsset === false
            || $asset->duplicate_of !== null
            || $asset->status !== MediaAsset::STATUS_READY
        ) {
            return false;
        }

        $metadata = $asset->getAttribute('metadata');
        $processing = is_array($metadata)
            ? ($metadata['processing'] ?? null)
            : null;

        return is_array($processing)
            && ($processing['status'] ?? null) === 'completed'
            && ($processing['version'] ?? null)
                === $this->processor->currentVersion();
    }

    private function idempotencyKey(
        string $organizationId,
        string $publicationId,
    ): string {
        return 'grindflow:publication:'.$organizationId.':'.$publicationId;
    }

    private function requireTenant(
        PublicationDelivery|ScheduledPublication $model,
        User $actor,
    ): string {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || hash_equals(
                (string) $model->organization_id,
                $organizationId,
            ) === false
        ) {
            throw new AuthorizationException(
                'The active tenant does not match this publication.',
            );
        }

        if ($actor->canScheduleOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot distribute publications for this organization.',
            );
        }

        return $organizationId;
    }
}
