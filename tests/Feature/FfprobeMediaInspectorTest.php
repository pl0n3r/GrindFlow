<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaBlob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\FfprobeMediaInspector;
use App\Services\Media\MediaProcessingException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FfprobeMediaInspectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'grindflow.media.ffprobe.enabled' => true,
            'grindflow.media.ffprobe.binary' => 'ffprobe',
            'grindflow.media.ffprobe.timeout_seconds' => 15,
        ]);

        Process::preventStrayProcesses();
    }

    public function test_ffprobe_normalizes_only_whitelisted_technical_metadata(): void
    {
        $payload = json_encode([
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'width' => 1920,
                    'height' => 1080,
                    'tags' => [
                        'location' => 'should-not-persist',
                    ],
                ],
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'sample_rate' => '48000',
                    'channels' => 2,
                    'tags' => [
                        'artist' => 'should-not-persist',
                    ],
                ],
            ],
            'format' => [
                'duration' => '61.2345',
                'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                'tags' => [
                    'comment' => 'should-not-persist',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        Process::fake([
            '*' => Process::result(output: $payload),
        ]);

        [$user, $organization, $blob] = $this->blob();

        $metadata = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): array => app(FfprobeMediaInspector::class)->inspect(
                MediaBlob::query()->findOrFail($blob->getKey()),
            ),
        );

        $this->assertSame(61.235, $metadata['duration_seconds']);
        $this->assertSame(
            'mov,mp4,m4a,3gp,3g2,mj2',
            $metadata['format_name'],
        );
        $this->assertSame(2, $metadata['stream_count']);
        $this->assertSame('h264', $metadata['video']['codec']);
        $this->assertSame(1920, $metadata['video']['width']);
        $this->assertSame(1080, $metadata['video']['height']);
        $this->assertSame('aac', $metadata['audio']['codec']);
        $this->assertSame(48000, $metadata['audio']['sample_rate']);
        $this->assertSame(2, $metadata['audio']['channels']);
        $this->assertStringNotContainsString(
            'should-not-persist',
            json_encode($metadata, JSON_THROW_ON_ERROR),
        );

        Process::assertRan(function (
            PendingProcess $process,
            ProcessResult $result,
        ): bool {
            return is_array($process->command)
                && $process->command[0] === 'ffprobe'
                && in_array('-show_streams', $process->command, true)
                && in_array('-show_format', $process->command, true)
                && $process->timeout === 15
                && $result->successful();
        });
    }

    public function test_invalid_ffprobe_json_returns_safe_processing_error(): void
    {
        Process::fake([
            '*' => Process::result(output: '{invalid-json'),
        ]);

        [$user, $organization, $blob] = $this->blob();

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn (): array => app(FfprobeMediaInspector::class)->inspect(
                    MediaBlob::query()->findOrFail($blob->getKey()),
                ),
            );

            $this->fail('Expected invalid ffprobe JSON to fail.');
        } catch (MediaProcessingException $exception) {
            $this->assertSame(
                'processing_probe_invalid_output',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return array{User, Organization, MediaBlob}
     */
    private function blob(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);

        $bytes = 'ffprobe-test-bytes';
        $sha256 = hash('sha256', $bytes);
        $key = sprintf(
            'organizations/%s/blobs/%s/%s',
            $organization->getKey(),
            substr($sha256, 0, 2),
            $sha256,
        );

        Storage::disk('local')->put($key, $bytes);

        $blob = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaBlob => MediaBlob::query()->create([
                'storage_disk' => 'local',
                'storage_key' => $key,
                'sha256' => $sha256,
                'byte_size' => strlen($bytes),
                'mime_type' => 'video/mp4',
                'metadata' => [],
            ]),
        );

        return [$user, $organization, $blob];
    }
}
