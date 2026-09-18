<?php

namespace App\Services\Media\Connectors;

use App\Models\MediaIngestion;
use App\Models\User;
use App\Services\Media\MediaIngestionCoordinator;
use App\Services\Media\StagedMediaSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ConnectorMediaStager
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MediaIngestionCoordinator $coordinator,
    ) {}

    /**
     * @param  callable(): resource  $download
     */
    public function stageAndQueue(
        User $actor,
        string $provider,
        RemoteMediaFile $file,
        callable $download,
    ): MediaIngestion {
        $organizationId = $this->organizationId($actor);

        if ($file->sizeBytes > $this->maxBytes()) {
            throw MediaConnectorException::fileTooLarge();
        }

        $sourceRef = $this->sourceRef($provider, $file);
        $existing = $this->coordinator->findExistingSource(
            $actor,
            $provider,
            $sourceRef,
        );

        if ($existing instanceof MediaIngestion) {
            return $existing;
        }

        $disk = $this->stagingDisk();
        $storageKey = sprintf(
            'organizations/%s/staging/connectors/%s/%s',
            $organizationId,
            $provider,
            Str::uuid(),
        );

        $stream = $download();

        if (is_resource($stream) === false) {
            throw MediaConnectorException::downloadFailed();
        }

        try {
            $limitedStream = $this->copyWithinLimit($stream);
        } finally {
            fclose($stream);
        }

        try {
            try {
                $stored = Storage::disk($disk)->put($storageKey, $limitedStream);
            } catch (Throwable) {
                throw MediaConnectorException::stagingFailed();
            }
        } finally {
            fclose($limitedStream);
        }

        if ($stored === false) {
            $this->deleteStaged($disk, $storageKey);

            throw MediaConnectorException::stagingFailed();
        }

        try {
            try {
                $stagedSize = Storage::disk($disk)->size($storageKey);
            } catch (Throwable) {
                throw MediaConnectorException::stagingFailed();
            }

            if ($stagedSize !== $file->sizeBytes) {
                throw MediaConnectorException::stagingFailed();
            }

            $source = new StagedMediaSource(
                sourceType: $provider,
                sourceRef: $sourceRef,
                sourceDisk: $disk,
                sourceKey: $storageKey,
                originalFilename: $file->name,
                mimeType: null,
                byteSize: $file->sizeBytes,
                deleteAfterIngest: true,
                metadata: [
                    'provider' => $provider,
                    'remote_id' => $file->id,
                    'remote_path' => $file->path,
                    'remote_modified_at' => $file->modifiedAt,
                    'provider_checksum' => $file->checksum,
                ],
            );

            $ingestion = $this->coordinator->queueSource($actor, $source);

            if ($ingestion->source_key !== $storageKey) {
                $this->deleteStaged($disk, $storageKey);
            }

            return $ingestion;
        } catch (Throwable $exception) {
            $this->deleteStaged($disk, $storageKey);

            throw $exception;
        }
    }

    private function organizationId(User $actor): string
    {
        $organizationId = $this->tenantContext->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException(
                'Tenant context is required to stage connector media.',
            );
        }

        if ($actor->canManageOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot manage connector media for this organization.',
            );
        }

        return $organizationId;
    }

    /**
     * @param  resource  $stream
     * @return resource
     */
    private function copyWithinLimit($stream)
    {
        $limitedStream = fopen('php://temp', 'w+b');

        if ($limitedStream === false) {
            throw MediaConnectorException::stagingFailed();
        }

        try {
            $copied = stream_copy_to_stream(
                $stream,
                $limitedStream,
                $this->maxBytes() + 1,
            );

            if ($copied === false) {
                throw MediaConnectorException::downloadFailed();
            }

            if ($copied > $this->maxBytes()) {
                throw MediaConnectorException::fileTooLarge();
            }

            rewind($limitedStream);

            return $limitedStream;
        } catch (Throwable $exception) {
            fclose($limitedStream);

            throw $exception;
        }
    }

    private function deleteStaged(string $disk, string $storageKey): void
    {
        try {
            Storage::disk($disk)->delete($storageKey);
        } catch (Throwable) {
            // Cleanup is best-effort. The original safe connector error wins.
        }
    }

    private function sourceRef(string $provider, RemoteMediaFile $file): string
    {
        return sprintf(
            '%s:%s:%s',
            $provider,
            $file->id,
            substr(hash('sha256', $file->versionFingerprint()), 0, 32),
        );
    }

    private function stagingDisk(): string
    {
        return (string) config(
            'grindflow.media.staging_disk',
            'media',
        );
    }

    private function maxBytes(): int
    {
        $configured = (int) config(
            'grindflow.media.connector_max_bytes',
            2_147_483_648,
        );

        return max(1, min($configured, 2_147_483_648));
    }
}
