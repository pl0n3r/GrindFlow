<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\MediaIngestion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connectors\ConnectorMediaStager;
use App\Services\Media\Connectors\MediaConnectorException;
use App\Services\Media\Connectors\RemoteMediaFile;
use App\Services\Media\MediaIngestionCoordinator;
use App\Services\Media\StagedMediaSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ConnectorMediaStagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config()->set('grindflow.media.staging_disk', 'media');
        config()->set('grindflow.media.connector_max_bytes', 8);
    }

    public function test_stages_exact_bytes_and_queues_tenant_scoped_metadata(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $file = $this->remoteFile(4);
        $source = null;
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn(null);
        $coordinator->shouldReceive('queueSource')->once()
            ->andReturnUsing(function (User $user, StagedMediaSource $staged) use ($actor, &$source): MediaIngestion {
                $this->assertSame($actor->getKey(), $user->getKey());
                $source = $staged;

                return new MediaIngestion(['source_key' => $staged->sourceKey]);
            });

        $result = $this->within($actor, $organization, fn (): MediaIngestion => $this->stager($coordinator)
            ->stageAndQueue($actor, 'dropbox', $file, fn () => $this->stream('DATA')));

        $this->assertInstanceOf(MediaIngestion::class, $result);
        $this->assertInstanceOf(StagedMediaSource::class, $source);
        $this->assertSame('dropbox', $source->sourceType);
        $this->assertSame('media', $source->sourceDisk);
        $this->assertSame(4, $source->byteSize);
        $this->assertTrue($source->deleteAfterIngest);
        $this->assertSame('image.jpg', $source->originalFilename);
        $this->assertSame('remote-id', $source->metadata['remote_id']);
        $this->assertSame('/remote/image.jpg', $source->metadata['remote_path']);
        $this->assertSame('2026-10-24T00:00:00Z', $source->metadata['remote_modified_at']);
        $this->assertStringStartsWith('organizations/'.$organization->getKey().'/staging/connectors/dropbox/', $source->sourceKey);
        $this->assertSame('DATA', Storage::disk('media')->get($source->sourceKey));
        $this->assertCount(1, Storage::disk('media')->allFiles());
    }

    public function test_existing_source_skips_download_and_staging(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $existing = new MediaIngestion(['source_key' => 'existing/item']);
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn($existing);
        $coordinator->shouldNotReceive('queueSource');
        $downloads = 0;

        $result = $this->within($actor, $organization, fn (): MediaIngestion => $this->stager($coordinator)
            ->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), function () use (&$downloads) {
                $downloads++;

                return $this->stream('DATA');
            }));

        $this->assertSame($existing, $result);
        $this->assertSame(0, $downloads);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_requires_tenant_context_and_manager_permissions_before_download(): void
    {
        [$actor] = $this->identity(UserRole::Studio);
        [$viewer, $organization] = $this->identity(UserRole::Model);
        $coordinator = $this->coordinator();
        $coordinator->shouldNotReceive('findExistingSource');
        $downloads = 0;
        $download = function () use (&$downloads) {
            $downloads++;

            return $this->stream('DATA');
        };

        try {
            $this->stager($coordinator)->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), $download);
            $this->fail('Missing tenant context was accepted.');
        } catch (AuthorizationException) {
            // No download is performed without an authenticated tenant.
        }
        try {
            $this->within($viewer, $organization, fn () => $this->stager($coordinator)
                ->stageAndQueue($viewer, 'dropbox', $this->remoteFile(4), $download));
            $this->fail('Unprivileged membership was accepted.');
        } catch (AuthorizationException) {
            // A model cannot stage connector media.
        }

        $this->assertSame(0, $downloads);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_rejects_invalid_remote_file_before_storage_or_download(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RemoteMediaFile('', 'image.jpg', '/remote/image.jpg', 4, '2026-10-24T00:00:00Z');
    }

    public function test_rejects_declared_oversize_without_downloading(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $coordinator = $this->coordinator();
        $coordinator->shouldNotReceive('findExistingSource');
        $downloads = 0;

        try {
            $this->within($actor, $organization, fn () => $this->stager($coordinator)
                ->stageAndQueue($actor, 'dropbox', $this->remoteFile(9), function () use (&$downloads) {
                    $downloads++;

                    return $this->stream('DATA');
                }));
            $this->fail('Oversized remote metadata was accepted.');
        } catch (MediaConnectorException $e) {
            $this->assertSame('connector_file_too_large', $e->getMessage());
        }

        $this->assertSame(0, $downloads);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_rejects_invalid_download_result_without_staging(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn(null);
        $coordinator->shouldNotReceive('queueSource');

        try {
            $this->within($actor, $organization, fn () => $this->stager($coordinator)
                ->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), fn () => null));
            $this->fail('Non-resource download was accepted.');
        } catch (MediaConnectorException $e) {
            $this->assertSame('connector_download_failed', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_stream_is_bounded_even_when_metadata_understates_actual_bytes(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn(null);
        $coordinator->shouldNotReceive('queueSource');
        $stream = $this->stream('123456789');

        try {
            $this->within($actor, $organization, fn () => $this->stager($coordinator)
                ->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), fn () => $stream));
            $this->fail('Oversized stream was accepted.');
        } catch (MediaConnectorException $e) {
            $this->assertSame('connector_file_too_large', $e->getMessage());
        }

        $this->assertFalse(is_resource($stream), 'Download handle must be closed on rejection.');
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_size_mismatch_cleans_staging_and_never_queues(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn(null);
        $coordinator->shouldNotReceive('queueSource');

        try {
            $this->within($actor, $organization, fn () => $this->stager($coordinator)
                ->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), fn () => $this->stream('TOO')));
            $this->fail('Mismatched staged byte count was accepted.');
        } catch (MediaConnectorException $e) {
            $this->assertSame('connector_staging_failed', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_failed_queue_handoff_removes_staged_blob(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn(null);
        $coordinator->shouldReceive('queueSource')->once()
            ->andThrow(new RuntimeException('synthetic_queue_failure'));

        try {
            $this->within($actor, $organization, fn () => $this->stager($coordinator)
                ->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), fn () => $this->stream('DATA')));
            $this->fail('Failed queue handoff was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic_queue_failure', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_deduplicated_queue_handoff_removes_new_blob(): void
    {
        [$actor, $organization] = $this->identity(UserRole::Studio);
        $existing = new MediaIngestion(['source_key' => 'already/queued']);
        $coordinator = $this->coordinator();
        $coordinator->shouldReceive('findExistingSource')->once()->andReturn(null);
        $coordinator->shouldReceive('queueSource')->once()->andReturn($existing);

        $result = $this->within($actor, $organization, fn (): MediaIngestion => $this->stager($coordinator)
            ->stageAndQueue($actor, 'dropbox', $this->remoteFile(4), fn () => $this->stream('DATA')));

        $this->assertSame($existing, $result);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    private function stager(MediaIngestionCoordinator $coordinator): ConnectorMediaStager
    {
        return new ConnectorMediaStager(app(TenantContext::class), $coordinator);
    }

    private function coordinator(): MediaIngestionCoordinator
    {
        return Mockery::mock(MediaIngestionCoordinator::class);
    }

    private function remoteFile(int $bytes): RemoteMediaFile
    {
        return new RemoteMediaFile('remote-id', 'image.jpg', '/remote/image.jpg', $bytes, '2026-10-24T00:00:00Z');
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /** @return array{User, Organization} */
    private function identity(UserRole $role): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        Membership::query()->create([
            'user_id' => $actor->getKey(),
            'organization_id' => $organization->getKey(),
            'role' => $role,
        ]);

        return [$actor, $organization];
    }

    private function within(User $actor, Organization $organization, callable $callback): mixed
    {
        return app(TenantContext::class)->runWithinOrganization(
            $actor, (string) $organization->getKey(), $callback,
        );
    }
}
