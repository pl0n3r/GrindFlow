<?php

namespace App\Services\Media;

use App\Jobs\IngestMediaObject;
use App\Models\MediaIngestion;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

class MediaIngestionCoordinator
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function queue(
        User $actor,
        string $sourceType,
        string $sourceRef,
        string $sourceDisk,
        string $sourceKey,
        string $originalFilename,
        ?string $mimeType = null,
        ?int $byteSize = null,
        bool $deleteSourceAfterIngest = false,
        array $metadata = [],
    ): MediaIngestion {
        return $this->queueSource(
            $actor,
            new StagedMediaSource(
                sourceType: $sourceType,
                sourceRef: $sourceRef,
                sourceDisk: $sourceDisk,
                sourceKey: $sourceKey,
                originalFilename: $originalFilename,
                mimeType: $mimeType,
                byteSize: $byteSize,
                deleteAfterIngest: $deleteSourceAfterIngest,
                metadata: $metadata,
            ),
        );
    }

    public function queueSource(
        User $actor,
        StagedMediaSource $source,
    ): MediaIngestion {
        $organizationId = $this->authorizedOrganizationId($actor);

        $idempotencyKey = $source->idempotencyKey();

        $ingestion = MediaIngestion::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'requested_by_user_id' => $actor->getKey(),
                'source_type' => $source->sourceType,
                'source_ref' => $source->sourceRef,
                'source_disk' => $source->sourceDisk,
                'source_key' => $source->sourceKey,
                'original_filename' => $source->originalFilename,
                'mime_type' => $source->mimeType,
                'byte_size' => $source->byteSize,
                'status' => MediaIngestion::STATUS_QUEUED,
                'delete_source_after_ingest' => $source->deleteAfterIngest,
                'metadata' => $source->metadata,
            ],
        );

        if ($ingestion->wasRecentlyCreated) {
            IngestMediaObject::dispatch(
                (string) $ingestion->getKey(),
                (string) $organizationId,
                (string) $actor->getKey(),
                $idempotencyKey,
            );
        }

        return $ingestion;
    }


    public function findExistingSource(
        User $actor,
        string $sourceType,
        string $sourceRef,
    ): ?MediaIngestion {
        $organizationId = $this->authorizedOrganizationId($actor);
        $idempotencyKey = StagedMediaSource::idempotencyKeyFor(
            $sourceType,
            $sourceRef,
        );

        return MediaIngestion::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function retry(MediaIngestion $ingestion, User $actor): MediaIngestion
    {
        $organizationId = $this->tenantContext->organizationId();
        $ingestionOrganizationId = (string) $ingestion->organization_id;

        if (
            $organizationId === null
            || hash_equals($ingestionOrganizationId, $organizationId) === false
        ) {
            throw new AuthorizationException(
                'The active tenant does not match this media ingestion.',
            );
        }

        if ($actor->canManageOrganization($ingestionOrganizationId) === false) {
            throw new AuthorizationException(
                'The user cannot retry media ingestion for this organization.',
            );
        }

        if ($ingestion->status === MediaIngestion::STATUS_COMPLETED) {
            return $ingestion;
        }

        $ingestion->forceFill([
            'requested_by_user_id' => $actor->getKey(),
            'status' => MediaIngestion::STATUS_QUEUED,
            'last_error' => null,
        ])->save();

        IngestMediaObject::dispatch(
            (string) $ingestion->getKey(),
            $ingestionOrganizationId,
            (string) $actor->getKey(),
            $ingestion->idempotency_key,
        );

        return $ingestion->refresh();
    }

    private function authorizedOrganizationId(User $actor): string
    {
        $organizationId = $this->tenantContext->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException(
                'Tenant context is required to queue media ingestion.',
            );
        }

        if ($actor->canManageOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot manage media ingestion for this organization.',
            );
        }

        return $organizationId;
    }
}
