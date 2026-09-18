<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\IngestMediaObject;
use App\Models\MediaIngestion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connectors\GoogleDriveMediaAdapter;
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

class GoogleDriveMediaAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_initial_normalizes_downloadable_media_and_skips_other_files(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with(
                $request->url(),
                'https://www.googleapis.com/drive/v3/files?',
            )) {
                return Http::response([
                    'files' => [
                        [
                            'id' => 'folder-1',
                            'name' => 'Folder',
                            'mimeType' => 'application/vnd.google-apps.folder',
                            'modifiedTime' => '2026-09-18T12:00:00Z',
                            'capabilities' => ['canDownload' => false],
                        ],
                        [
                            'id' => 'doc-1',
                            'name' => 'Notes',
                            'mimeType' => 'application/vnd.google-apps.document',
                            'modifiedTime' => '2026-09-18T12:00:00Z',
                            'capabilities' => ['canDownload' => true],
                        ],
                        [
                            'id' => 'blocked-1',
                            'name' => 'blocked.mp4',
                            'mimeType' => 'video/mp4',
                            'size' => '99',
                            'modifiedTime' => '2026-09-18T12:10:00Z',
                            'capabilities' => ['canDownload' => false],
                        ],
                        [
                            'id' => 'file-1',
                            'name' => 'clip.mp4',
                            'mimeType' => 'video/mp4',
                            'size' => '2048',
                            'modifiedTime' => '2026-09-18T12:30:00Z',
                            'md5Checksum' => 'google-md5',
                            'capabilities' => ['canDownload' => true],
                        ],
                    ],
                    'nextPageToken' => 'page-2',
                ]);
            }

            return Http::response(status: 404);
        });

        $listing = app(GoogleDriveMediaAdapter::class)
            ->listInitial('google-access-token');

        $this->assertCount(1, $listing->files);
        $this->assertTrue($listing->hasMore);
        $this->assertSame('page-2', $listing->cursor);

        $file = $listing->files[0];

        $this->assertSame('file-1', $file->id);
        $this->assertSame('clip.mp4', $file->name);
        $this->assertSame('/clip.mp4', $file->path);
        $this->assertSame(2048, $file->sizeBytes);
        $this->assertSame('google-md5', $file->checksum);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with(
                $request->url(),
                'https://www.googleapis.com/drive/v3/files?',
            )
                && $request->hasHeader(
                    'Authorization',
                    'Bearer google-access-token',
                );
        });
    }

    public function test_list_page_preserves_folder_filter_and_page_token(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [],
            ]),
        ]);

        $listing = app(GoogleDriveMediaAdapter::class)->listPage(
            'google-access-token',
            'page-2',
            'folder-123',
        );

        $this->assertFalse($listing->hasMore);
        $this->assertNull($listing->cursor);

        Http::assertSent(function (Request $request): bool {
            $query = parse_url($request->url(), PHP_URL_QUERY);

            if (is_string($query) === false) {
                return false;
            }

            parse_str($query, $values);

            return ($values['pageToken'] ?? null) === 'page-2'
                && ($values['q'] ?? null)
                    === "'folder-123' in parents and trashed = false"
                && ($values['pageSize'] ?? null) === '500'
                && ($values['spaces'] ?? null) === 'drive';
        });
    }

    public function test_start_page_token_uses_drive_changes_endpoint(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/changes/startPageToken*' => Http::response([
                'startPageToken' => 'start-token-100',
            ]),
        ]);

        $token = app(GoogleDriveMediaAdapter::class)
            ->startPageToken('google-access-token');

        $this->assertSame('start-token-100', $token);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with(
                $request->url(),
                'https://www.googleapis.com/drive/v3/changes/startPageToken?',
            )
                && $request->hasHeader(
                    'Authorization',
                    'Bearer google-access-token',
                );
        });
    }

    public function test_list_changes_normalizes_current_media_and_preserves_next_page_token(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/changes*' => Http::response([
                'changes' => [
                    [
                        'fileId' => 'removed-1',
                        'removed' => true,
                    ],
                    [
                        'fileId' => 'trashed-1',
                        'removed' => false,
                        'file' => [
                            'id' => 'trashed-1',
                            'name' => 'trashed.mp4',
                            'mimeType' => 'video/mp4',
                            'size' => '100',
                            'modifiedTime' => '2026-09-18T14:00:00Z',
                            'parents' => ['folder-123'],
                            'trashed' => true,
                            'capabilities' => ['canDownload' => true],
                        ],
                    ],
                    [
                        'fileId' => 'outside-1',
                        'removed' => false,
                        'file' => [
                            'id' => 'outside-1',
                            'name' => 'outside.mp4',
                            'mimeType' => 'video/mp4',
                            'size' => '100',
                            'modifiedTime' => '2026-09-18T14:00:00Z',
                            'parents' => ['other-folder'],
                            'trashed' => false,
                            'capabilities' => ['canDownload' => true],
                        ],
                    ],
                    [
                        'fileId' => 'file-99',
                        'removed' => false,
                        'file' => [
                            'id' => 'file-99',
                            'name' => 'changed.mp4',
                            'mimeType' => 'video/mp4',
                            'size' => '4096',
                            'modifiedTime' => '2026-09-18T14:30:00Z',
                            'md5Checksum' => 'changed-md5',
                            'parents' => ['folder-123'],
                            'trashed' => false,
                            'capabilities' => ['canDownload' => true],
                        ],
                    ],
                ],
                'nextPageToken' => 'changes-page-2',
            ]),
        ]);

        $listing = app(GoogleDriveMediaAdapter::class)->listChanges(
            'google-access-token',
            'changes-page-1',
            'folder-123',
        );

        $this->assertCount(1, $listing->files);
        $this->assertTrue($listing->hasMore());
        $this->assertSame('changes-page-2', $listing->nextPageToken);
        $this->assertNull($listing->newStartPageToken);
        $this->assertSame('file-99', $listing->files[0]->id);
        $this->assertSame('changed-md5', $listing->files[0]->checksum);

        Http::assertSent(function (Request $request): bool {
            if (
                str_starts_with(
                    $request->url(),
                    'https://www.googleapis.com/drive/v3/changes?',
                ) === false
            ) {
                return false;
            }

            $query = parse_url($request->url(), PHP_URL_QUERY);

            if (is_string($query) === false) {
                return false;
            }

            parse_str($query, $values);

            return ($values['pageToken'] ?? null) === 'changes-page-1'
                && ($values['pageSize'] ?? null) === '500'
                && ($values['spaces'] ?? null) === 'drive'
                && ($values['includeRemoved'] ?? null) === '1';
        });
    }

    public function test_list_changes_returns_new_start_page_token_at_feed_end(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/changes*' => Http::response([
                'changes' => [],
                'newStartPageToken' => 'changes-start-300',
            ]),
        ]);

        $listing = app(GoogleDriveMediaAdapter::class)->listChanges(
            'google-access-token',
            'changes-start-200',
        );

        $this->assertFalse($listing->hasMore());
        $this->assertNull($listing->nextPageToken);
        $this->assertSame(
            'changes-start-300',
            $listing->newStartPageToken,
        );
    }

    public function test_stage_and_queue_downloads_blob_once_and_reuses_existing_source(): void
    {
        Queue::fake();
        Storage::fake('media');

        config([
            'grindflow.media.staging_disk' => 'media',
            'grindflow.media.connector_max_bytes' => 2_147_483_648,
        ]);

        $bytes = 'google-drive-media-bytes';

        Http::fake(function (Request $request) use ($bytes) {
            if (str_starts_with(
                $request->url(),
                'https://www.googleapis.com/drive/v3/files/file-42?',
            )) {
                return Http::response(
                    $bytes,
                    200,
                    ['Content-Type' => 'video/mp4'],
                );
            }

            return Http::response(status: 404);
        });

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $file = new RemoteMediaFile(
            id: 'file-42',
            name: 'remote-42.mp4',
            path: '/remote-42.mp4',
            sizeBytes: strlen($bytes),
            modifiedAt: '2026-09-18T12:30:00Z',
            checksum: 'google-version-42',
        );

        [$first, $second] = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($user, $file): array {
                $adapter = app(GoogleDriveMediaAdapter::class);

                return [
                    $adapter->stageAndQueue($user, 'google-access-token', $file),
                    $adapter->stageAndQueue($user, 'google-access-token', $file),
                ];
            },
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('google_drive', $first->source_type);
        $this->assertStringStartsWith(
            'google_drive:file-42:',
            $first->source_ref,
        );
        $this->assertSame('google_drive', $first->metadata['provider']);
        $this->assertSame('file-42', $first->metadata['remote_id']);
        $this->assertSame('google-version-42', $first->metadata['provider_checksum']);
        $this->assertStringStartsWith(
            'organizations/'.$organization->getKey().'/staging/connectors/google_drive/',
            $first->source_key,
        );
        $this->assertStringNotContainsString(
            'google-access-token',
            json_encode($first->metadata, JSON_THROW_ON_ERROR),
        );

        Storage::disk('media')->assertExists($first->source_key);
        Http::assertSentCount(1);
        Queue::assertPushed(IngestMediaObject::class, 1);
    }

    public function test_rate_limit_returns_safe_bounded_retry_after(): void
    {
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response(
                ['error' => ['message' => 'sensitive provider body']],
                429,
                ['Retry-After' => '90'],
            ),
        ]);

        try {
            app(GoogleDriveMediaAdapter::class)
                ->listInitial('google-access-token');

            $this->fail('Expected the provider rate limit to fail.');
        } catch (MediaConnectorException $exception) {
            $this->assertSame('connector_rate_limited', $exception->getMessage());
            $this->assertSame(90, $exception->retryAfterSeconds);
            $this->assertStringNotContainsString(
                'sensitive provider body',
                $exception->getMessage(),
            );
        }
    }

    public function test_unauthorized_download_requests_reconnect_without_leaking_body(): void
    {
        Queue::fake();
        Storage::fake('media');

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/file-expired*' => Http::response(
                'sensitive google response',
                401,
            ),
        ]);

        [$user, $organization] = $this->manager();

        $file = new RemoteMediaFile(
            id: 'file-expired',
            name: 'expired.mp4',
            path: '/expired.mp4',
            sizeBytes: 100,
            modifiedAt: '2026-09-18T12:30:00Z',
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn () => app(GoogleDriveMediaAdapter::class)
                    ->stageAndQueue($user, 'expired-token', $file),
            );

            $this->fail('Expected the connector request to fail.');
        } catch (MediaConnectorException $exception) {
            $this->assertSame('connector_unauthorized', $exception->getMessage());
            $this->assertTrue($exception->needsReconnect);
            $this->assertStringNotContainsString(
                'sensitive google response',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, MediaIngestion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_manager_is_rejected_before_google_download(): void
    {
        Queue::fake();
        Storage::fake('media');
        Http::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Model);

        $file = new RemoteMediaFile(
            id: 'file-blocked',
            name: 'blocked.mp4',
            path: '/blocked.mp4',
            sizeBytes: 100,
            modifiedAt: '2026-09-18T12:30:00Z',
        );

        try {
            app(TenantContext::class)->runWithinOrganization(
                $user,
                (string) $organization->getKey(),
                fn () => app(GoogleDriveMediaAdapter::class)
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
