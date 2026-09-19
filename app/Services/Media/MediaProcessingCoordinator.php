<?php

namespace App\Services\Media;

use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class MediaProcessingCoordinator
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MediaAssetProcessor $processor,
    ) {}

    public function queue(MediaAsset $asset, User $actor): MediaAsset
    {
        $organizationId = $this->tenantContext->organizationId();

        if (
            $organizationId === null
            || hash_equals(
                (string) $asset->organization_id,
                $organizationId,
            ) === false
        ) {
            throw new AuthorizationException(
                'The active tenant does not match this media asset.',
            );
        }

        if ($actor->canManageOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot process media for this organization.',
            );
        }

        if ($asset->duplicate_of !== null) {
            return $asset;
        }

        $processing = $this->processingMetadata($asset);
        $processorVersion = $this->processor->currentVersion();
        $attempts = ($processing['version'] ?? null) === $processorVersion
            ? (int) ($processing['attempts'] ?? 0)
            : 0;

        if (
            ($processing['version'] ?? null) === $processorVersion
            && in_array(
                $processing['status'] ?? null,
                ['queued', 'processing', 'completed'],
                true,
            )
        ) {
            return $asset;
        }

        $metadata = $this->metadata($asset);
        $metadata['processing'] = [
            'version' => $processorVersion,
            'status' => 'queued',
            'attempts' => $attempts,
            'last_error' => null,
        ];

        $asset->forceFill(['metadata' => $metadata])->save();

        try {
            ProcessMediaAsset::dispatch(
                (string) $asset->getKey(),
                $organizationId,
                (string) $actor->getKey(),
                $processorVersion,
            );
        } catch (Throwable $exception) {
            $metadata = $this->metadata($asset);
            $metadata['processing'] = [
                'version' => $processorVersion,
                'status' => 'dispatch_failed',
                'attempts' => $attempts,
                'last_error' => 'processing_dispatch_failed',
            ];

            $asset->forceFill(['metadata' => $metadata])->save();

            throw $exception;
        }

        return $asset->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(MediaAsset $asset): array
    {
        $metadata = $asset->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function processingMetadata(MediaAsset $asset): array
    {
        $processing = $this->metadata($asset)['processing'] ?? null;

        return is_array($processing) ? $processing : [];
    }
}
