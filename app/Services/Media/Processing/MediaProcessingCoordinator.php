<?php

namespace App\Services\Media\Processing;

use App\Jobs\VerifyMediaAssetIntegrity;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

class MediaProcessingCoordinator
{
    public const INTEGRITY_PROCESSOR = 'integrity_v1';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function queueIntegrity(
        MediaAsset $asset,
        User $actor,
    ): MediaAsset {
        $organizationId = $this->authorizedOrganizationId($asset, $actor);
        $target = $this->canonicalAsset($asset);

        $metadata = $this->metadata($target);
        $processing = $this->processing($metadata);
        $state = $processing[self::INTEGRITY_PROCESSOR] ?? null;

        if (
            is_array($state)
            && in_array(
                $state['status'] ?? null,
                ['queued', 'processing', 'completed'],
                true,
            )
        ) {
            return $target;
        }

        $processing[self::INTEGRITY_PROCESSOR] = [
            'status' => 'queued',
            'attempts' => is_array($state)
                ? (int) ($state['attempts'] ?? 0)
                : 0,
            'last_error' => null,
            'requested_by_user_id' => (string) $actor->getKey(),
            'queued_at' => now()->utc()->toIso8601String(),
        ];

        $metadata['processing'] = $processing;

        $target->forceFill([
            'metadata' => $metadata,
        ])->save();

        VerifyMediaAssetIntegrity::dispatch(
            (string) $target->getKey(),
            $organizationId,
            (string) $actor->getKey(),
        );

        return $target->refresh();
    }

    private function canonicalAsset(MediaAsset $asset): MediaAsset
    {
        if ($asset->duplicate_of === null) {
            return $asset;
        }

        return MediaAsset::query()->findOrFail($asset->duplicate_of);
    }

    private function authorizedOrganizationId(
        MediaAsset $asset,
        User $actor,
    ): string {
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
                'The user cannot queue media processing for this organization.',
            );
        }

        return $organizationId;
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
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function processing(array $metadata): array
    {
        $processing = $metadata['processing'] ?? null;

        return is_array($processing) ? $processing : [];
    }
}
