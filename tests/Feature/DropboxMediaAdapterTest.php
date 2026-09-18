<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\IngestMediaObject;
use App\Models\MediaIngestion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connectors\DropboxMediaAdapter;
use App\Services\Media\Connectors\MediaConnectorException;
use App\Services\Media\Connectors\RemoteMediaFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DropboxMediaAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_initial_normalizes_dropbox_files_and_ignores_folders(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [
                    [
                        '.tag' => 'folder',
                        'id' => 'id:folder',
                        'name' => 'Folder',
                        'path_display' => '/Folder',
                    ],
                    [
                        '.tag' => 'file',
                        'id' => 'id:file-1',
                        'name' => 'clip.mp4',
                        'path_display' => '/Folder/clip.mp4',
                        'size' => 2048,
                        'server_modified' => '2026-09-18T12:00:00Z',
                        'content_hash' => 'dropbox-checksum',
                    ],
                ],
                'cursor' => 'cursor-1',
                'has_more' => true,
            ], 200),
        ]);

        $listing = app(DropboxMediaAdapter::class)
            ->listInitial('access-token-value', '/');

        $this->assertCount(1, $listing->files);
        $this->assertSame('cursor-1', $listing->cursor);
        $this->assertTrue($listing->hasMore);

        $file = $listing->files[0];

        $this->assertSame('id:file-1', $file->id);
        $this->assertSame('clip.mp4', $file->name);
        $this->assertSame('/Folder/clip.mp4', $file->path);
        $this->assertSame(2048, $file->sizeBytes);
        $this->assertSame('dropbox-checksum', $file->checksum);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.dropboxapi.com/2/files/list_folder'
                && $request['path'] === ''
                && $request['recursive'] === true;
        });
    }

    public function test_stage_and_queue_streams_once_and_reuses_existing_source(): void
    {
        Queue::fake();
        Storage::fake('media');

        config([
            'grindflow.media.staging_disk' => 'media',
            'grindflow.media.connector_max_bytes' => 2_147_483_648,
        ]);

        $bytes = 'connector-staged-bytes';

        Http::fake([
            'https://content.dropboxapi.com/2/files/download' => Http::response(
                $bytes,
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $file = new RemoteMediaFile(
            id: 'id:remote-42',
            name: 'remote-42.mp4',
            path: '/Media/remote-42.mp4',
            sizeBytes: strlen($bytes),
            modifiedAt: '2026-09-18T12:30:00Z',
            checksum: 'remote-version-42',
        );

        [$first, $second] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user, $file): array {
                $adapter = app(DropboxMediaAdapter::class);

                return [
                    $adapter->stageAndQueue($user, 'access-token-value', $file),
                    $adapter->stageAndQueue($user, 'access-token-value', $file),
                ];
            },
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('dropbox', $first->source_type);
        $this->assertStringStartsWith('dropbox:id:remote-42:', $first->source_ref);
        $this->assertSame('dropbox', $first->metadata['provider']);
        $this->assertSame('id:remote-42', $first->metadata['remote_id']);
        $this->assertSame('/Media/remote-42.mp4', $first->metadata['remote_path']);
        $this->assertSame('remote-version-42', $first->metadata['provider_checksum']);
        $this->assertTrue($first->delete_source_after_ingest);
        $this->assertStringNotContainsString(
            'access-token-value',
            json_encode($first->metadata, JSON_THROW_ON_ERROR),
        );

        Storage::disk('media')->assertExists($first->source_key);

        Http::assertSentCount(1);
        Queue::assertPushed(IngestMediaObject::class, 1);
    }

    public function test_unauthorized_provider_response_is_safe_and_requests_reconnect(): void
    {
        Queue::fake();
        Storage::fake('media');

        Http::fake([
            'https://content.dropboxapi.com/2/files/download' => Http::response(
                'sensitive provider response that must not leak',
                401,
            ),
        ]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $file = new RemoteMediaFile(
            id: 'id:expired',
            name: 'expired.mp4',
            path: '/expired.mp4',
            sizeBytes: 100,
            modifiedAt: '2026-09-18T12:30:00Z',
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn () => app(DropboxMediaAdapter::class)
                    ->stageAndQueue($user, 'expired-token', $file),
            );

            $this->fail('Expected the connector request to fail.');
        } catch (MediaConnectorException $exception) {
            $this->assertSame('connector_unauthorized', $exception->getMessage());
            $this->assertTrue($exception->needsReconnect);
            $this->assertStringNotContainsString(
                'sensitive provider response',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, MediaIngestion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_manager_is_rejected_before_remote_download(): void
    {
        Queue::fake();
        Storage::fake('media');
        Http::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);

        $file = new RemoteMediaFile(
            id: 'id:blocked',
            name: 'blocked.mp4',
            path: '/blocked.mp4',
            sizeBytes: 100,
            modifiedAt: '2026-09-18T12:30:00Z',
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn () => app(DropboxMediaAdapter::class)
                    ->stageAndQueue($user, 'unused-token', $file),
            );

            $this->fail('Expected the non-manager to be rejected.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, MediaIngestion::query()->count());
    }

    public function test_oversized_remote_file_is_rejected_before_download(): void
    {
        Queue::fake();
        Storage::fake('media');
        Http::fake();

        config(['grindflow.media.connector_max_bytes' => 1024]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $file = new RemoteMediaFile(
            id: 'id:too-large',
            name: 'too-large.mp4',
            path: '/too-large.mp4',
            sizeBytes: 1025,
            modifiedAt: '2026-09-18T12:30:00Z',
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn () => app(DropboxMediaAdapter::class)
                    ->stageAndQueue($user, 'unused-token', $file),
            );

            $this->fail('Expected the file-size guard to reject the remote file.');
        } catch (MediaConnectorException $exception) {
            $this->assertSame('connector_file_too_large', $exception->getMessage());
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
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
