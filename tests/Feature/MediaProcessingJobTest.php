<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Queue\Middleware\UseOrganizationContext;
use App\Services\Media\FfmpegCommandRunner;
use App\Services\Media\FfmpegMediaDerivativeGenerator;
use App\Services\Media\MediaAssetProcessor;
use App\Services\Media\MediaProcessingCoordinator;
use App\Services\Media\MediaProcessingException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaProcessingJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Process::preventStrayProcesses();
        config([
            'grindflow.media.disk' => 'local',
            'grindflow.media.ffprobe.enabled' => false,
            'grindflow.media.ffmpeg.enabled' => false,
        ]);
    }

    public function test_canonical_asset_is_queued_once_and_duplicate_is_skipped(): void
    {
        Queue::fake();

        [$user, $organization, $canonical] = $this->asset(
            payload: 'canonical-processing-bytes',
        );

        $duplicate = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($canonical): MediaAsset {
                return MediaAsset::query()->create([
                    'media_blob_id' => $canonical->media_blob_id,
                    'duplicate_of' => $canonical->getKey(),
                    'ingested_by_user_id' => $canonical->ingested_by_user_id,
                    'original_filename' => 'duplicate.mp4',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_DUPLICATE,
                    'metadata' => [],
                ]);
            },
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($canonical, $duplicate, $user): void {
                $coordinator = app(MediaProcessingCoordinator::class);

                $first = $coordinator->queue(
                    MediaAsset::query()->findOrFail($canonical->getKey()),
                    $user,
                );
                $second = $coordinator->queue(
                    MediaAsset::query()->findOrFail($canonical->getKey()),
                    $user,
                );
                $skipped = $coordinator->queue(
                    MediaAsset::query()->findOrFail($duplicate->getKey()),
                    $user,
                );

                $this->assertSame(
                    'queued',
                    $first->metadata['processing']['status'],
                );
                $this->assertSame(
                    MediaAssetProcessor::VERSION,
                    $second->metadata['processing']['version'],
                );
                $this->assertArrayNotHasKey(
                    'processing',
                    $skipped->metadata ?? [],
                );
            },
        );

        Queue::assertPushed(ProcessMediaAsset::class, 1);
    }

    public function test_processing_job_completes_deterministically_and_retry_is_idempotent(): void
    {
        Queue::fake();

        [$user, $organization, $asset] = $this->asset(
            payload: 'video-processing-bytes',
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $job = $this->job($asset, $user, $organization);

        $this->runJob($job);
        $this->runJob($job);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset): void {
                $fresh = MediaAsset::query()->findOrFail($asset->getKey());
                $processing = $fresh->metadata['processing'];

                $this->assertSame('completed', $processing['status']);
                $this->assertSame(1, $processing['attempts']);
                $this->assertSame('probe_v2', $processing['profile']);
                $this->assertSame('video', $processing['media_kind']);
                $this->assertSame('disabled', $processing['technical_probe']);
                $this->assertNull($processing['technical_metadata']);
                $this->assertSame('video/mp4', $processing['mime_type']);
                $this->assertSame(
                    MediaAssetProcessor::VERSION,
                    $processing['version'],
                );
                $this->assertNull($processing['last_error']);
            },
        );
    }

    public function test_enabled_ffprobe_metadata_persists_and_retry_is_idempotent(): void
    {
        Queue::fake();

        config([
            'grindflow.media.ffprobe.enabled' => true,
            'grindflow.media.ffprobe.binary' => 'ffprobe',
            'grindflow.media.ffprobe.timeout_seconds' => 15,
        ]);

        Process::fake([
            '*' => Process::result(output: json_encode([
                'streams' => [
                    [
                        'codec_type' => 'video',
                        'codec_name' => 'h264',
                        'width' => 1280,
                        'height' => 720,
                    ],
                    [
                        'codec_type' => 'audio',
                        'codec_name' => 'aac',
                        'sample_rate' => '48000',
                        'channels' => 2,
                    ],
                ],
                'format' => [
                    'duration' => '12.5',
                    'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        [$user, $organization, $asset] = $this->asset(
            payload: 'ffprobe-processing-bytes',
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $job = $this->job($asset, $user, $organization);

        $this->runJob($job);
        $this->runJob($job);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset): void {
                $fresh = MediaAsset::query()->findOrFail($asset->getKey());
                $processing = $fresh->metadata['processing'];
                $technical = $processing['technical_metadata'];

                $this->assertSame('completed', $processing['status']);
                $this->assertSame(1, $processing['attempts']);
                $this->assertSame(
                    MediaAssetProcessor::FFPROBE_VERSION,
                    $processing['version'],
                );
                $this->assertSame('probe_v3', $processing['profile']);
                $this->assertSame('ffprobe', $processing['technical_probe']);
                $this->assertSame(12.5, $technical['duration_seconds']);
                $this->assertSame('h264', $technical['video']['codec']);
                $this->assertSame(1280, $technical['video']['width']);
                $this->assertSame(720, $technical['video']['height']);
                $this->assertSame('aac', $technical['audio']['codec']);
                $this->assertSame(48000, $technical['audio']['sample_rate']);
                $this->assertSame(2, $technical['audio']['channels']);
            },
        );
    }

    public function test_enabling_ffprobe_reprocesses_completed_disabled_asset(): void
    {
        Queue::fake();

        [$user, $organization, $asset] = $this->asset(
            payload: 'ffprobe-transition-bytes',
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $disabledJob = $this->job($asset, $user, $organization);
        $this->runJob($disabledJob);

        config([
            'grindflow.media.ffprobe.enabled' => true,
            'grindflow.media.ffprobe.binary' => 'ffprobe',
            'grindflow.media.ffprobe.timeout_seconds' => 15,
        ]);

        Process::fake([
            '*' => Process::result(output: json_encode([
                'streams' => [[
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'width' => 640,
                    'height' => 360,
                ]],
                'format' => [
                    'duration' => '3.5',
                    'format_name' => 'mov,mp4',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $ffprobeJob = $this->job($asset, $user, $organization);
        $this->runJob($ffprobeJob);
        $this->runJob($ffprobeJob);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset): void {
                $processing = MediaAsset::query()
                    ->findOrFail($asset->getKey())
                    ->metadata['processing'];

                $this->assertSame(
                    MediaAssetProcessor::FFPROBE_VERSION,
                    $processing['version'],
                );
                $this->assertSame('probe_v3', $processing['profile']);
                $this->assertSame('ffprobe', $processing['technical_probe']);
                $this->assertSame(1, $processing['attempts']);
                $this->assertSame(
                    3.5,
                    $processing['technical_metadata']['duration_seconds'],
                );
            },
        );

        Queue::assertPushed(ProcessMediaAsset::class, 2);
    }

    public function test_processor_version_tracks_probe_and_derivative_modes(): void
    {
        $this->assertSame(
            MediaAssetProcessor::VERSION,
            app(MediaAssetProcessor::class)->currentVersion(),
        );

        config(['grindflow.media.ffprobe.enabled' => true]);

        $this->assertSame(
            MediaAssetProcessor::FFPROBE_VERSION,
            app(MediaAssetProcessor::class)->currentVersion(),
        );

        config([
            'grindflow.media.ffprobe.enabled' => false,
            'grindflow.media.ffmpeg.enabled' => true,
        ]);

        $this->assertSame(
            MediaAssetProcessor::FFMPEG_PREVIEW_VERSION,
            app(MediaAssetProcessor::class)->currentVersion(),
        );

        config(['grindflow.media.ffprobe.enabled' => true]);

        $this->assertSame(
            MediaAssetProcessor::FFPROBE_FFMPEG_PREVIEW_VERSION,
            app(MediaAssetProcessor::class)->currentVersion(),
        );
    }

    public function test_enabling_ffmpeg_reprocesses_and_persists_thumbnail_and_video_preview(): void
    {
        Queue::fake();

        [$user, $organization, $asset] = $this->asset(
            payload: 'ffmpeg-preview-transition-bytes',
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $disabledJob = $this->job($asset, $user, $organization);
        $this->runJob($disabledJob);

        $runner = new class extends FfmpegCommandRunner
        {
            public int $calls = 0;

            /** @var array<int, array<int, string>> */
            public array $commands = [];

            /**
             * @param  array<int, string>  $command
             */
            public function run(array $command, int $timeoutSeconds): void
            {
                $this->calls++;
                $this->commands[] = $command;

                $outputPath = $command[count($command) - 1] ?? null;
                $formatIndex = array_search('-f', $command, true);
                $format = is_int($formatIndex)
                    ? ($command[$formatIndex + 1] ?? null)
                    : null;

                if (
                    is_string($outputPath) === false
                    || $outputPath === ''
                    || in_array($format, ['webp', 'mp4'], true) === false
                ) {
                    throw MediaProcessingException::derivativeInvalidOutput();
                }

                file_put_contents(
                    $outputPath,
                    $format === 'mp4'
                        ? 'deterministic-mp4-preview'
                        : 'deterministic-webp-thumbnail',
                );
            }
        };

        app()->instance(FfmpegCommandRunner::class, $runner);

        config([
            'grindflow.media.ffmpeg.enabled' => true,
            'grindflow.media.ffmpeg.binary' => 'ffmpeg',
            'grindflow.media.ffmpeg.timeout_seconds' => 45,
            'grindflow.media.ffmpeg.preview_seconds' => 8,
        ]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $ffmpegJob = $this->job($asset, $user, $organization);
        $this->runJob($ffmpegJob);
        $this->runJob($ffmpegJob);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset, $organization, $runner): void {
                $fresh = MediaAsset::query()->findOrFail($asset->getKey());
                $processing = $fresh->metadata['processing'];
                $thumbnail = $processing['derivatives']['thumbnail'];
                $preview = $processing['derivatives']['preview'];
                $sourceSha = (string) $fresh->blob()->value('sha256');

                $thumbnailKey = sprintf(
                    'organizations/%s/derivatives/%s/%s/%s.webp',
                    $organization->getKey(),
                    substr($sourceSha, 0, 2),
                    $sourceSha,
                    FfmpegMediaDerivativeGenerator::THUMBNAIL_PROFILE,
                );
                $previewKey = sprintf(
                    'organizations/%s/derivatives/%s/%s/%s.mp4',
                    $organization->getKey(),
                    substr($sourceSha, 0, 2),
                    $sourceSha,
                    FfmpegMediaDerivativeGenerator::PREVIEW_PROFILE,
                );

                $this->assertSame('completed', $processing['status']);
                $this->assertSame(1, $processing['attempts']);
                $this->assertSame(
                    MediaAssetProcessor::FFMPEG_PREVIEW_VERSION,
                    $processing['version'],
                );
                $this->assertSame('probe_v6', $processing['profile']);
                $this->assertSame(
                    FfmpegMediaDerivativeGenerator::PROFILE_SET,
                    $processing['derivative_profile'],
                );

                $this->assertSame($thumbnailKey, $thumbnail['storage_key']);
                $this->assertSame('image/webp', $thumbnail['mime_type']);
                $this->assertSame(
                    hash('sha256', 'deterministic-webp-thumbnail'),
                    $thumbnail['sha256'],
                );

                $this->assertSame($previewKey, $preview['storage_key']);
                $this->assertSame('video/mp4', $preview['mime_type']);
                $this->assertSame(
                    hash('sha256', 'deterministic-mp4-preview'),
                    $preview['sha256'],
                );

                $this->assertSame(2, $runner->calls);
                $this->assertContains('libwebp', $runner->commands[0]);
                $this->assertContains('libx264', $runner->commands[1]);
                $this->assertContains('-t', $runner->commands[1]);
                $this->assertContains('-movflags', $runner->commands[1]);
                $this->assertContains('+faststart', $runner->commands[1]);
                $this->assertContains('-map_metadata', $runner->commands[0]);
                $this->assertContains('-map_metadata', $runner->commands[1]);

                Storage::disk('local')->assertExists($thumbnailKey);
                Storage::disk('local')->assertExists($previewKey);
                $this->assertCount(
                    3,
                    Storage::disk('local')->allFiles(),
                );
            },
        );

        Queue::assertPushed(ProcessMediaAsset::class, 2);
    }

    public function test_legacy_ffmpeg_processor_version_remains_thumbnail_only(): void
    {
        [$user, $organization, $asset] = $this->asset(
            payload: 'legacy-thumbnail-only-bytes',
        );

        $runner = new class extends FfmpegCommandRunner
        {
            public int $calls = 0;

            /**
             * @param  array<int, string>  $command
             */
            public function run(array $command, int $timeoutSeconds): void
            {
                $this->calls++;
                $outputPath = $command[count($command) - 1] ?? null;

                if (is_string($outputPath) === false || $outputPath === '') {
                    throw MediaProcessingException::derivativeInvalidOutput();
                }

                file_put_contents($outputPath, 'legacy-thumbnail');
            }
        };

        app()->instance(FfmpegCommandRunner::class, $runner);

        config([
            'grindflow.media.ffmpeg.enabled' => true,
            'grindflow.media.ffmpeg.binary' => 'ffmpeg',
        ]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset): void {
                $fresh = MediaAsset::query()->findOrFail($asset->getKey());
                $fresh->forceFill([
                    'metadata' => [
                        'processing' => [
                            'version' => MediaAssetProcessor::FFMPEG_VERSION,
                            'status' => 'queued',
                            'attempts' => 0,
                            'last_error' => null,
                        ],
                    ],
                ])->save();
            },
        );

        $job = new ProcessMediaAsset(
            (string) $asset->getKey(),
            (string) $organization->getKey(),
            (string) $user->getKey(),
            MediaAssetProcessor::FFMPEG_VERSION,
        );

        $this->runJob($job);
        $this->runJob($job);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset, $runner): void {
                $processing = MediaAsset::query()
                    ->findOrFail($asset->getKey())
                    ->metadata['processing'];

                $this->assertSame(
                    MediaAssetProcessor::FFMPEG_VERSION,
                    $processing['version'],
                );
                $this->assertSame('probe_v4', $processing['profile']);
                $this->assertSame(
                    FfmpegMediaDerivativeGenerator::THUMBNAIL_PROFILE,
                    $processing['derivative_profile'],
                );
                $this->assertArrayHasKey(
                    'thumbnail',
                    $processing['derivatives'],
                );
                $this->assertArrayNotHasKey(
                    'preview',
                    $processing['derivatives'],
                );
                $this->assertSame(1, $runner->calls);
            },
        );
    }

    public function test_current_ffmpeg_profile_keeps_images_thumbnail_only(): void
    {
        Queue::fake();

        [$user, $organization, $asset] = $this->asset(
            payload: 'image-thumbnail-only-bytes',
            mimeType: 'image/jpeg',
        );

        $runner = new class extends FfmpegCommandRunner
        {
            public int $calls = 0;

            /**
             * @param  array<int, string>  $command
             */
            public function run(array $command, int $timeoutSeconds): void
            {
                $this->calls++;
                $outputPath = $command[count($command) - 1] ?? null;

                if (is_string($outputPath) === false || $outputPath === '') {
                    throw MediaProcessingException::derivativeInvalidOutput();
                }

                file_put_contents($outputPath, 'image-thumbnail');
            }
        };

        app()->instance(FfmpegCommandRunner::class, $runner);
        config(['grindflow.media.ffmpeg.enabled' => true]);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $this->runJob($this->job($asset, $user, $organization));

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset, $runner): void {
                $processing = MediaAsset::query()
                    ->findOrFail($asset->getKey())
                    ->metadata['processing'];

                $this->assertSame(
                    MediaAssetProcessor::FFMPEG_PREVIEW_VERSION,
                    $processing['version'],
                );
                $this->assertSame(
                    FfmpegMediaDerivativeGenerator::THUMBNAIL_PROFILE,
                    $processing['derivative_profile'],
                );
                $this->assertArrayHasKey(
                    'thumbnail',
                    $processing['derivatives'],
                );
                $this->assertArrayNotHasKey(
                    'preview',
                    $processing['derivatives'],
                );
                $this->assertSame(1, $runner->calls);
            },
        );

        Queue::assertPushed(ProcessMediaAsset::class, 1);
    }

    public function test_failed_processing_is_observable_and_can_retry_without_new_asset(): void
    {
        Queue::fake();

        [$user, $organization, $asset] = $this->asset(
            payload: 'retry-processing-bytes',
        );

        $blob = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaBlob => MediaBlob::query()->findOrFail(
                $asset->media_blob_id,
            ),
        );

        Storage::disk($blob->storage_disk)->delete($blob->storage_key);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $job = $this->job($asset, $user, $organization);

        try {
            $this->runJob($job);
            $this->fail('Expected processing to fail for missing object.');
        } catch (MediaProcessingException $exception) {
            $this->assertSame(
                'processing_object_missing',
                $exception->getMessage(),
            );
        }

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset): void {
                $fresh = MediaAsset::query()->findOrFail($asset->getKey());

                $this->assertSame(
                    'failed',
                    $fresh->metadata['processing']['status'],
                );
                $this->assertSame(
                    'processing_object_missing',
                    $fresh->metadata['processing']['last_error'],
                );
                $this->assertSame(
                    1,
                    $fresh->metadata['processing']['attempts'],
                );
                $this->assertSame(1, MediaAsset::query()->count());
            },
        );

        Storage::disk($blob->storage_disk)->put(
            $blob->storage_key,
            'retry-processing-bytes',
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaProcessingCoordinator::class)->queue(
                MediaAsset::query()->findOrFail($asset->getKey()),
                $user,
            ),
        );

        $this->runJob($job);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($asset): void {
                $fresh = MediaAsset::query()->findOrFail($asset->getKey());

                $this->assertSame(
                    'completed',
                    $fresh->metadata['processing']['status'],
                );
                $this->assertSame(
                    2,
                    $fresh->metadata['processing']['attempts'],
                );
                $this->assertSame(1, MediaAsset::query()->count());
            },
        );
    }

    /**
     * @return array{User, Organization, MediaAsset}
     */
    private function asset(
        string $payload,
        string $mimeType = 'video/mp4',
    ): array {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);

        $sha256 = hash('sha256', $payload);
        $key = sprintf(
            'organizations/%s/blobs/%s/%s',
            $organization->getKey(),
            substr($sha256, 0, 2),
            $sha256,
        );

        Storage::disk('local')->put($key, $payload);

        $asset = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use (
                $user,
                $payload,
                $sha256,
                $key,
                $mimeType,
            ): MediaAsset {
                $blob = MediaBlob::query()->create([
                    'storage_disk' => 'local',
                    'storage_key' => $key,
                    'sha256' => $sha256,
                    'byte_size' => strlen($payload),
                    'mime_type' => $mimeType,
                    'metadata' => [],
                ]);

                return MediaAsset::query()->create([
                    'media_blob_id' => $blob->getKey(),
                    'ingested_by_user_id' => $user->getKey(),
                    'original_filename' => str_starts_with(
                        $mimeType,
                        'image/',
                    ) ? 'processing.jpg' : 'processing.mp4',
                    'source_type' => 'manual_upload',
                    'status' => MediaAsset::STATUS_READY,
                    'metadata' => [],
                ]);
            },
        );

        return [$user, $organization, $asset];
    }

    private function job(
        MediaAsset $asset,
        User $user,
        Organization $organization,
    ): ProcessMediaAsset {
        return new ProcessMediaAsset(
            (string) $asset->getKey(),
            (string) $organization->getKey(),
            (string) $user->getKey(),
            app(MediaAssetProcessor::class)->currentVersion(),
        );
    }

    private function runJob(ProcessMediaAsset $job): void
    {
        app(UseOrganizationContext::class)->handle(
            $job,
            fn () => $job->handle(app(MediaAssetProcessor::class)),
        );
    }
}
