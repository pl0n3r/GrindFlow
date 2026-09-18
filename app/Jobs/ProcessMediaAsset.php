<?php

namespace App\Jobs;

use App\Contracts\OrganizationAwareJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Media\MediaAssetProcessor;
use App\Services\Media\MediaProcessingException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMediaAsset implements OrganizationAwareJob, ShouldBeUnique, ShouldQueue
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
        private readonly int $processorVersion,
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
        return $this->organization.':'.$this->assetId.':v'.$this->processorVersion;
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
        return 'media-processing:'.$this->assetId.':v'.$this->processorVersion;
    }

    public function handle(MediaAssetProcessor $processor): void
    {
        $actor = User::query()->findOrFail($this->actor);

        if ($actor->canManageOrganization($this->organization) === false) {
            throw new AuthorizationException(
                'The user can no longer process media for this organization.',
            );
        }

        $asset = MediaAsset::query()
            ->with('blob')
            ->findOrFail($this->assetId);

        if ($asset->duplicate_of !== null) {
            return;
        }

        $processing = $this->processing($asset);

        if (
            ($processing['version'] ?? null) === $this->processorVersion
            && ($processing['status'] ?? null) === 'completed'
        ) {
            return;
        }

        $attempts = (int) ($processing['attempts'] ?? 0) + 1;

        $this->writeProcessing($asset, [
            'version' => $this->processorVersion,
            'status' => 'processing',
            'attempts' => $attempts,
            'last_error' => null,
        ]);

        try {
            $result = $processor->process($asset->refresh()->load('blob'));
        } catch (MediaProcessingException $exception) {
            $this->markFailed($asset, $attempts, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed(
                $asset,
                $attempts,
                'processing_unexpected_error:'.class_basename($exception),
            );

            throw $exception;
        }

        $this->writeProcessing($asset, array_merge($result, [
            'status' => 'completed',
            'attempts' => $attempts,
            'last_error' => null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function processing(MediaAsset $asset): array
    {
        $metadata = $asset->getAttribute('metadata');

        if (is_array($metadata) === false) {
            return [];
        }

        $processing = $metadata['processing'] ?? null;

        return is_array($processing) ? $processing : [];
    }

    /**
     * @param  array<string, mixed>  $processing
     */
    private function writeProcessing(
        MediaAsset $asset,
        array $processing,
    ): void {
        $metadata = $asset->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['processing'] = $processing;

        $asset->forceFill(['metadata' => $metadata])->save();
    }

    private function markFailed(
        MediaAsset $asset,
        int $attempts,
        string $safeError,
    ): void {
        $this->writeProcessing($asset, [
            'version' => $this->processorVersion,
            'status' => 'failed',
            'attempts' => $attempts,
            'last_error' => mb_substr($safeError, 0, 191),
        ]);
    }
}
