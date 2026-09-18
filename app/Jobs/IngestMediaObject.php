<?php

namespace App\Jobs;

use App\Contracts\OrganizationAwareJob;
use App\Models\MediaIngestion;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Media\FilesystemMediaIngestor;
use App\Services\Media\MediaIngestionException;
use App\Services\Media\MediaProcessingCoordinator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IngestMediaObject implements OrganizationAwareJob, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public readonly string $ingestionId;

    private readonly string $organization;

    private readonly string $actor;

    private readonly string $idempotency;

    public function __construct(
        string $ingestionId,
        string $organization,
        string $actor,
        string $idempotency,
    ) {
        $this->ingestionId = $ingestionId;
        $this->organization = $organization;
        $this->actor = $actor;
        $this->idempotency = $idempotency;
    }

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
        return $this->organization.':'.$this->idempotency;
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
        return $this->idempotency;
    }

    public function handle(
        FilesystemMediaIngestor $ingestor,
        MediaProcessingCoordinator $processing,
    ): void
    {
        $actor = User::query()->findOrFail($this->actor);

        if ($actor->canManageOrganization($this->organization) === false) {
            throw new AuthorizationException(
                'The user can no longer manage media ingestion for this organization.',
            );
        }

        $ingestion = MediaIngestion::query()->findOrFail($this->ingestionId);

        if ($ingestion->status === MediaIngestion::STATUS_COMPLETED) {
            if ($ingestion->media_asset_id !== null) {
                $asset = $ingestion->asset;

                if ($asset !== null) {
                    $processing->queue($asset, $actor);
                }
            }

            return;
        }

        $ingestion->forceFill([
            'status' => MediaIngestion::STATUS_PROCESSING,
            'attempts' => $ingestion->attempts + 1,
            'last_error' => null,
        ])->save();

        try {
            $asset = $ingestor->ingest($ingestion);
        } catch (MediaIngestionException $exception) {
            $this->markFailed($ingestion, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed(
                $ingestion,
                'unexpected_error:'.class_basename($exception),
            );

            throw $exception;
        }

        $ingestion->forceFill([
            'media_asset_id' => $asset->getKey(),
            'status' => MediaIngestion::STATUS_COMPLETED,
            'last_error' => null,
        ])->save();

        $processing->queue($asset, $actor);
    }

    private function markFailed(MediaIngestion $ingestion, string $safeError): void
    {
        $ingestion->forceFill([
            'status' => MediaIngestion::STATUS_FAILED,
            'last_error' => mb_substr($safeError, 0, 191),
        ])->save();
    }
}
