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
        $organizationId = $this->tenantContext->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException(
                'Tenant context is required to queue media ingestion.',
            );
        }

        $idempotencyKey = hash(
            'sha256',
            $sourceType."\0".$sourceRef,
        );

        $ingestion = MediaIngestion::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'requested_by_user_id' => $actor->getKey(),
                'source_type' => $sourceType,
                'source_ref' => $sourceRef,
                'source_disk' => $sourceDisk,
                'source_key' => $sourceKey,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'byte_size' => $byteSize,
                'status' => MediaIngestion::STATUS_QUEUED,
                'delete_source_after_ingest' => $deleteSourceAfterIngest,
                'metadata' => $metadata,
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

    public function retry(MediaIngestion $ingestion, User $actor): MediaIngestion
    {
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
            (string) $ingestion->organization_id,
            (string) $actor->getKey(),
            $ingestion->idempotency_key,
        );

        return $ingestion->refresh();
    }
}
