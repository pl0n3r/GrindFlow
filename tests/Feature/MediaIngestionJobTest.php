<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\IngestMediaObject;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MediaIngestion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Media\FilesystemMediaIngestor;
use App\Services\Media\MediaIngestionCoordinator;
use App\Services\Media\MediaIngestionException;
use App\Services\Media\MediaProcessingCoordinator;
use App\Services\Media\StagedMediaSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MediaIngestionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_source_is_queued_only_once(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user): void {
                $coordinator = app(MediaIngestionCoordinator::class);

                $first = $coordinator->queue(
                    $user,
                    'connector',
                    'remote:file:42:v1',
                    'source',
                    'incoming/file-42.png',
                    'file-42.png',
                    'image/png',
                    68,
                    true,
                    ['external_etag' => 'v1'],
                );

                $second = $coordinator->queue(
                    $user,
                    'connector',
                    'remote:file:42:v1',
                    'source',
                    'incoming/file-42.png',
                    'file-42.png',
                    'image/png',
                    68,
                    true,
                    ['external_etag' => 'v1'],
                );

                $this->assertSame($first->getKey(), $second->getKey());
                $this->assertSame(MediaIngestion::STATUS_QUEUED, $first->status);
            },
        );

        Queue::assertPushed(IngestMediaObject::class, 1);
    }

    public function test_staged_source_handoff_is_the_shared_connector_boundary(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $source = new StagedMediaSource(
            sourceType: 'cloud_connector',
            sourceRef: 'drive:file:abc123:v7',
            sourceDisk: 'source',
            sourceKey: 'staging/abc123',
            originalFilename: 'abc123.png',
            mimeType: 'image/png',
            byteSize: 68,
            deleteAfterIngest: true,
            metadata: [
                'connector' => 'drive',
                'external_version' => 'v7',
            ],
        );

        [$first, $second] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user, $source): array {
                $coordinator = app(MediaIngestionCoordinator::class);

                return [
                    $coordinator->queueSource($user, $source),
                    $coordinator->queueSource($user, $source),
                ];
            },
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('cloud_connector', $first->source_type);
        $this->assertSame('drive:file:abc123:v7', $first->source_ref);
        $this->assertSame('drive', $first->metadata['connector']);
        $this->assertSame('v7', $first->metadata['external_version']);
        $this->assertTrue($first->delete_source_after_ingest);

        Queue::assertPushed(IngestMediaObject::class, 1);
    }

    public function test_staged_source_rejects_invalid_connector_input_before_queueing(): void
    {
        Queue::fake();

        $this->expectException(InvalidArgumentException::class);

        new StagedMediaSource(
            sourceType: 'connector',
            sourceRef: '',
            sourceDisk: 'source',
            sourceKey: 'staging/file',
            originalFilename: 'file.png',
        );
    }

    public function test_non_manager_cannot_queue_media_ingestion(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);

        $this->expectException(AuthorizationException::class);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaIngestionCoordinator::class)->queue(
                $user,
                'connector',
                'remote:file:blocked',
                'source',
                'incoming/blocked.png',
                'blocked.png',
                'image/png',
                68,
            ),
        );
    }

    public function test_job_ingests_staged_media_and_preserves_source_metadata(): void
    {
        Queue::fake();
        Storage::fake('source');
        Storage::fake('local');
        config(['grindflow.media.disk' => 'local']);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, UserRole::Studio);

        $bytes = $this->pngBytes();
        Storage::disk('source')->put('incoming/first.png', $bytes);

        $ingestion = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaIngestion => app(MediaIngestionCoordinator::class)->queue(
                $user,
                'connector',
                'remote:first:v1',
                'source',
                'incoming/first.png',
                'first.png',
                'image/png',
                strlen($bytes),
                true,
                ['external_etag' => 'etag-1'],
            ),
        );

        $this->runJob($ingestion, $user, $organization);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($ingestion): void {
                $fresh = MediaIngestion::query()->findOrFail($ingestion->getKey());

                $this->assertSame(MediaIngestion::STATUS_COMPLETED, $fresh->status);
                $this->assertSame(1, $fresh->attempts);
                $this->assertNotNull($fresh->media_asset_id);

                $asset = MediaAsset::query()->findOrFail($fresh->media_asset_id);

                $this->assertSame(MediaAsset::STATUS_READY, $asset->status);
                $this->assertSame('connector', $asset->source_type);
                $this->assertSame('remote:first:v1', $asset->source_ref);
                $this->assertSame('etag-1', $asset->metadata['external_etag']);
                $this->assertSame((string) $fresh->getKey(), $asset->metadata['ingestion_id']);

                $this->assertSame(1, MediaBlob::query()->count());
            },
        );

        Storage::disk('source')->assertMissing('incoming/first.png');
    }

    public function test_different_sources_with_same_bytes_share_one_blob(): void
    {
        Queue::fake();
        Storage::fake('source');
        Storage::fake('local');
        config(['grindflow.media.disk' => 'local']);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, UserRole::Studio);

        $bytes = $this->pngBytes();

        Storage::disk('source')->put('incoming/a.png', $bytes);
        Storage::disk('source')->put('incoming/b.png', $bytes);

        [$first, $second] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user, $bytes): array {
                $coordinator = app(MediaIngestionCoordinator::class);

                return [
                    $coordinator->queue(
                        $user,
                        'connector',
                        'remote:a:v1',
                        'source',
                        'incoming/a.png',
                        'a.png',
                        'image/png',
                        strlen($bytes),
                        true,
                    ),
                    $coordinator->queue(
                        $user,
                        'connector',
                        'remote:b:v1',
                        'source',
                        'incoming/b.png',
                        'b.png',
                        'image/png',
                        strlen($bytes),
                        true,
                    ),
                ];
            },
        );

        $this->runJob($first, $user, $organization);
        $this->runJob($second, $user, $organization);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): void {
                $this->assertSame(1, MediaBlob::query()->count());
                $this->assertSame(2, MediaAsset::query()->count());

                $duplicate = MediaAsset::query()
                    ->where('status', MediaAsset::STATUS_DUPLICATE)
                    ->firstOrFail();

                $this->assertNotNull($duplicate->duplicate_of);
            },
        );
    }

    public function test_missing_source_records_safe_failure_state(): void
    {
        Queue::fake();
        Storage::fake('source');
        Storage::fake('local');
        config(['grindflow.media.disk' => 'local']);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $this->membership($user, $organization, UserRole::Studio);

        $ingestion = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaIngestion => app(MediaIngestionCoordinator::class)->queue(
                $user,
                'connector',
                'remote:missing:v1',
                'source',
                'incoming/missing.png',
                'missing.png',
                'image/png',
                68,
            ),
        );

        try {
            $this->runJob($ingestion, $user, $organization);
            $this->fail('Expected media ingestion to fail.');
        } catch (MediaIngestionException $exception) {
            $this->assertSame('source_missing', $exception->getMessage());
        }

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($ingestion): void {
                $fresh = MediaIngestion::query()->findOrFail($ingestion->getKey());

                $this->assertSame(MediaIngestion::STATUS_FAILED, $fresh->status);
                $this->assertSame('source_missing', $fresh->last_error);
                $this->assertSame(1, $fresh->attempts);
            },
        );
    }

    private function runJob(
        MediaIngestion $ingestion,
        User $user,
        Organization $organization,
    ): void {
        $job = new IngestMediaObject(
            (string) $ingestion->getKey(),
            (string) $organization->getKey(),
            (string) $user->getKey(),
            $ingestion->idempotency_key,
        );

        app(UseOrganizationContext::class)->handle(
            $job,
            fn () => $job->handle(
                app(FilesystemMediaIngestor::class),
                app(MediaProcessingCoordinator::class),
            ),
        );
    }

    private function membership(
        User $user,
        Organization $organization,
        UserRole $role,
    ): Membership {
        return Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z0N8AAAAASUVORK5CYII=',
            true,
        );

        $this->assertIsString($bytes);

        return $bytes;
    }
}
