<?php

namespace App\Jobs;

use App\Contracts\OrganizationAwareJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Media\Processing\MediaAssetIntegrityVerifier;
use App\Services\Media\Processing\MediaProcessingCoordinator;
use App\Services\Media\Processing\MediaProcessingException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class VerifyMediaAssetIntegrity implements OrganizationAwareJob, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $assetId,
        private readonly string $organization,
        private readonly string $actor,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [app(UseOrganizationContext::class)];
    }

    public function uniqueId(): string
    {
        return $this->organization.':'.$this->idempotencyKey();
    }

    public function actorId(): string
    {
        return $this->actor;
    }

    public function organizationId(): string
    {
        return $this->organization;
    }

    public function idempotencyKey(): string
    {
        return 'media-processing:'.
            MediaProcessingCoordinator::INTEGRITY_PROCESSOR.
            ':'.$this->assetId;
    }

    public function handle(MediaAssetIntegrityVerifier $verifier): void
    {
        $actor = User::query()->findOrFail($this->actor);

        if ($actor->canManageOrganization($this->organization) === false) {
            throw new AuthorizationException(
                'The user can no longer manage media processing for this organization.',
            );
        }

        $asset = MediaAsset::query()->findOrFail($this->assetId);
        $state = $this->state($asset);

        if (($state['status'] ?? null) === 'completed') {
            return;
        }

        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        $this->writeState($asset, [
            'status' => 'processing',
            'attempts' => $attempts,
            'last_error' => null,
            'requested_by_user_id' => (string) $actor->getKey(),
            'started_at' => now()->utc()->toIso8601String(),
        ]);

        try {
            $result = $verifier->verify($asset);
        } catch (MediaProcessingException $exception) {
            $this->markFailed(
                $asset,
                $attempts,
                $exception->getMessage(),
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed(
                $asset,
                $attempts,
                'unexpected_error:'.class_basename($exception),
            );

            throw $exception;
        }

        $this->writeState($asset, [
            'status' => 'completed',
            'attempts' => $attempts,
            'last_error' => null,
            'requested_by_user_id' => (string) $actor->getKey(),
            'completed_at' => now()->utc()->toIso8601String(),
            'sha256' => $result['sha256'],
            'byte_size' => $result['byte_size'],
            'mime_type' => $result['mime_type'],
        ]);
    }

    private function markFailed(
        MediaAsset $asset,
        int $attempts,
        string $safeError,
    ): void {
        $this->writeState($asset, [
            'status' => 'failed',
            'attempts' => $attempts,
            'last_error' => mb_substr($safeError, 0, 191),
            'failed_at' => now()->utc()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(MediaAsset $asset): array
    {
        $metadata = $asset->getAttribute('metadata');

        if (is_array($metadata) === false) {
            return [];
        }

        $processing = $metadata['processing'] ?? null;

        if (is_array($processing) === false) {
            return [];
        }

        $state = $processing[
            MediaProcessingCoordinator::INTEGRITY_PROCESSOR
        ] ?? null;

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(MediaAsset $asset, array $state): void
    {
        $metadata = $asset->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        $processing = $metadata['processing'] ?? null;
        $processing = is_array($processing) ? $processing : [];

        $existing = $processing[
            MediaProcessingCoordinator::INTEGRITY_PROCESSOR
        ] ?? null;
        $existing = is_array($existing) ? $existing : [];

        $processing[
            MediaProcessingCoordinator::INTEGRITY_PROCESSOR
        ] = array_merge($existing, $state);

        $metadata['processing'] = $processing;

        $asset->forceFill([
            'metadata' => $metadata,
        ])->save();
    }
}
