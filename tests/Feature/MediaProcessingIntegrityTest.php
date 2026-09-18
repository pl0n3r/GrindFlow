<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\VerifyMediaAssetIntegrity;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Processing\MediaAssetIntegrityVerifier;
use App\Services\Media\Processing\MediaProcessingCoordinator;
use App\Services\Media\Processing\MediaProcessingException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaProcessingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake([VerifyMediaAssetIntegrity::class]);
    }

    public function test_duplicate_assets_share_one_canonical_integrity_job(): void
    {
        [$user, $organization] = $this->manager();
        [$canonical, $duplicate] = $this->assetPair(
            $user,
            $organization,
            'shared-processing-bytes',
        );

        $target = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaAsset => app(MediaProcessingCoordinator::class)
                ->queueIntegrity($duplicate, $user),
        );

        $this->assertSame($canonical->getKey(), $target->getKey());

        Queue::assertPushed(
            VerifyMediaAssetIntegrity::class,
            fn (VerifyMediaAssetIntegrity $job): bool =>
                $job->assetId === (string) $canonical->getKey(),
        );
        Queue::assertPushed(VerifyMediaAssetIntegrity::class, 1);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)
                ->queueIntegrity(
                    MediaAsset::query()->findOrFail($canonical->getKey()),
                    $user,
                ),
        );

        Queue::assertPushed(VerifyMediaAssetIntegrity::class, 1);

        $freshCanonical = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaAsset => MediaAsset::query()
                ->findOrFail($canonical->getKey()),
        );

        $this->assertSame(
            'queued',
            $freshCanonical->metadata['processing']['integrity_v1']['status'],
        );
    }

    public function test_integrity_job_verifies_stored_bytes_and_is_retry_safe(): void
    {
        [$user, $organization] = $this->manager();
        [$asset] = $this->assetPair(
            $user,
            $organization,
            'verified-processing-bytes',
            false,
        );

        $job = $this->queuedJob($user, $organization, $asset);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $job->handle(
                app(MediaAssetIntegrityVerifier::class),
            ),
        );

        $fresh = $this->fresh($user, $organization, $asset);
        $state = $fresh->metadata['processing']['integrity_v1'];

        $this->assertSame('completed', $state['status']);
        $this->assertSame(1, $state['attempts']);
        $this->assertNull($state['last_error']);
        $this->assertSame(
            hash('sha256', 'verified-processing-bytes'),
            $state['sha256'],
        );
        $this->assertSame(
            strlen('verified-processing-bytes'),
            $state['byte_size'],
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => $job->handle(
                app(MediaAssetIntegrityVerifier::class),
            ),
        );

        $afterRetry = $this->fresh($user, $organization, $asset);

        $this->assertSame(
            1,
            $afterRetry->metadata['processing']['integrity_v1']['attempts'],
        );
    }

    public function test_integrity_mismatch_persists_safe_failure_state(): void
    {
        [$user, $organization] = $this->manager();
        [$asset] = $this->assetPair(
            $user,
            $organization,
            'expected-processing-bytes',
            false,
        );

        $job = $this->queuedJob($user, $organization, $asset);

        $fresh = $this->fresh($user, $organization, $asset);
        $blob = $fresh->blob()->firstOrFail();

        Storage::disk('local')->put(
            $blob->storage_key,
            'tampered-processing-bytes',
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn () => $job->handle(
                    app(MediaAssetIntegrityVerifier::class),
                ),
            );

            $this->fail('Expected integrity processing to fail.');
        } catch (MediaProcessingException $exception) {
            $this->assertSame(
                'processing_integrity_mismatch',
                $exception->getMessage(),
            );
        }

        $failed = $this->fresh($user, $organization, $asset);
        $state = $failed->metadata['processing']['integrity_v1'];

        $this->assertSame('failed', $state['status']);
        $this->assertSame(1, $state['attempts']);
        $this->assertSame(
            'processing_integrity_mismatch',
            $state['last_error'],
        );
    }

    public function test_non_manager_cannot_queue_integrity_processing(): void
    {
        $manager = User::factory()->create();
        $model = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($manager, $organization, UserRole::Studio);
        $this->membership($model, $organization, UserRole::Model);

        [$asset] = $this->assetPair(
            $manager,
            $organization,
            'blocked-processing-bytes',
            false,
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $model,
                (string) $organization->getKey(),
                fn () => app(MediaProcessingCoordinator::class)
                    ->queueIntegrity($asset, $model),
            );

            $this->fail('Expected media processing authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        Queue::assertNothingPushed();
    }

    private function queuedJob(
        User $user,
        Organization $organization,
        MediaAsset $asset,
    ): VerifyMediaAssetIntegrity {
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)
                ->queueIntegrity($asset, $user),
        );

        $queued = null;

        Queue::assertPushed(
            VerifyMediaAssetIntegrity::class,
            function (VerifyMediaAssetIntegrity $job) use (&$queued): bool {
                $queued = $job;

                return true;
            },
        );

        $this->assertInstanceOf(
            VerifyMediaAssetIntegrity::class,
            $queued,
        );

        return $queued;
    }

    /**
     * @return array{User, Organization}
     */
    private function manager(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        return [$user, $organization];
    }

    /**
     * @return array{MediaAsset, MediaAsset|null}
     */
    private function assetPair(
        User $user,
        Organization $organization,
        string $payload,
        bool $withDuplicate = true,
    ): array {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use (
                $user,
                $organization,
                $payload,
                $withDuplicate,
            ): array {
                $sha256 = hash('sha256', $payload);
                $storageKey = sprintf(
                    'organizations/%s/blobs/%s/%s',
                    $organization->getKey(),
                    substr($sha256, 0, 2),
                    $sha256,
                );

                Storage::disk('local')->put($storageKey, $payload);

                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => $storageKey,
                    'sha256' => $sha256,
                    'byte_size' => strlen($payload),
                    'mime_type' => 'video/mp4',
                ]);

                $canonical = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'ingested_by_user_id' => $user->getKey(),
                    'original_filename' => 'canonical.mp4',
                    'source_type' => 'test',
                    'status' => MediaAsset::STATUS_READY,
                    'metadata' => [],
                ]);

                if ($withDuplicate === false) {
                    return [$canonical, null];
                }

                $duplicate = MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'duplicate_of' => $canonical->getKey(),
                    'ingested_by_user_id' => $user->getKey(),
                    'original_filename' => 'duplicate.mp4',
                    'source_type' => 'test',
                    'status' => MediaAsset::STATUS_DUPLICATE,
                    'metadata' => [],
                ]);

                return [$canonical, $duplicate];
            },
        );
    }

    private function fresh(
        User $user,
        Organization $organization,
        MediaAsset $asset,
    ): MediaAsset {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaAsset => MediaAsset::query()
                ->findOrFail($asset->getKey()),
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
}
