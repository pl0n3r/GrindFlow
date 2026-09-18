<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\IngestMediaObject;
use App\Jobs\ScanMediaConnection;
use App\Models\MediaConnection;
use App\Models\MediaIngestion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connections\MediaConnectionScanner;
use App\Services\Media\Connections\MediaConnectionScheduler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaConnectionSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'grindflow.security.encryption_master_key' => str_repeat('ab', 32),
        ]);
    }

    public function test_dropbox_credentials_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                'dropbox-secret-access-token',
                'Primary Dropbox',
                '/Media',
            ),
        );

        $rawCiphertext = DB::table('media_connections')
            ->where('id', $connection->getKey())
            ->value('access_ciphertext');

        $this->assertIsString($rawCiphertext);
        $this->assertStringStartsWith('v1.', $rawCiphertext);
        $this->assertStringNotContainsString(
            'dropbox-secret-access-token',
            $rawCiphertext,
        );
        $this->assertArrayNotHasKey('access_ciphertext', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_ciphertext', $connection->toArray());
    }

    public function test_due_scheduler_claims_connection_before_dispatch_and_does_not_duplicate_tick(): void
    {
        Queue::fake([ScanMediaConnection::class]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                'dropbox-token',
            ),
        );

        $scheduler = app(MediaConnectionScheduler::class);

        $this->assertSame(1, $scheduler->dispatchDue());
        $this->assertSame(0, $scheduler->dispatchDue());

        Queue::assertPushed(ScanMediaConnection::class, 1);
    }

    public function test_scheduler_marks_connection_for_reconnect_if_authorizing_actor_loses_access(): void
    {
        Queue::fake([ScanMediaConnection::class]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $membership = $this->membership($user, $organization, UserRole::Studio);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                'dropbox-token',
            ),
        );

        $membership->delete();

        $this->assertSame(
            0,
            app(MediaConnectionScheduler::class)->dispatchDue(),
        );

        $raw = DB::table('media_connections')
            ->where('id', $connection->getKey())
            ->first();

        $this->assertNotNull($raw);
        $this->assertSame(MediaConnection::STATUS_NEEDS_RECONNECT, $raw->status);
        $this->assertSame('connection_actor_unauthorized', $raw->last_error);
        $this->assertNull($raw->next_scan_at);

        Queue::assertNothingPushed();
    }

    public function test_dropbox_scan_stages_files_queues_ingestion_and_persists_cursor(): void
    {
        Queue::fake([IngestMediaObject::class]);
        Storage::fake('media');

        config([
            'grindflow.media.staging_disk' => 'media',
            'grindflow.media.connector_scan_page_budget' => 20,
        ]);

        $bytes = 'scheduled-connector-bytes';

        Http::fake(function (Request $request) use ($bytes) {
            if ($request->url() === 'https://api.dropboxapi.com/2/files/list_folder') {
                return Http::response([
                    'entries' => [[
                        '.tag' => 'file',
                        'id' => 'id:scheduled-1',
                        'name' => 'scheduled-1.mp4',
                        'path_display' => '/Media/scheduled-1.mp4',
                        'size' => strlen($bytes),
                        'server_modified' => '2026-09-18T14:00:00Z',
                        'content_hash' => 'scheduled-version-1',
                    ]],
                    'cursor' => 'cursor-scheduled-1',
                    'has_more' => false,
                ], 200);
            }

            if ($request->url() === 'https://content.dropboxapi.com/2/files/download') {
                return Http::response(
                    $bytes,
                    200,
                    ['Content-Type' => 'application/octet-stream'],
                );
            }

            return Http::response(status: 404);
        });

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                'dropbox-scan-token',
                'Scheduled Dropbox',
                '/Media',
                30,
            ),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame('cursor-scheduled-1', $fresh->cursor);
                $this->assertNotNull($fresh->last_scan_at);
                $this->assertNotNull($fresh->next_scan_at);
                $this->assertNull($fresh->last_error);
                $this->assertSame(0, $fresh->consecutive_failures);

                $ingestion = MediaIngestion::query()->firstOrFail();

                $this->assertSame('dropbox', $ingestion->source_type);
                $this->assertSame('id:scheduled-1', $ingestion->metadata['remote_id']);
                $this->assertStringStartsWith(
                    'organizations/'.$connection->organization_id.'/staging/connectors/dropbox/',
                    $ingestion->source_key,
                );
            },
        );

        Queue::assertPushed(IngestMediaObject::class, 1);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_defers_scan_without_spending_failure_budget(): void
    {
        Queue::fake();
        Storage::fake('media');

        Http::fake([
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response(
                ['error_summary' => 'too_many_requests'],
                429,
                ['Retry-After' => '120'],
            ),
        ]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                'dropbox-rate-limit-token',
            ),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame(MediaConnection::STATUS_ACTIVE, $fresh->status);
                $this->assertSame('connector_rate_limited', $fresh->last_error);
                $this->assertSame(0, $fresh->consecutive_failures);
                $this->assertNotNull($fresh->next_scan_at);
                $this->assertTrue($fresh->next_scan_at->isFuture());
            },
        );

        Queue::assertNothingPushed();
    }

    public function test_unauthorized_provider_response_marks_connection_for_reconnect(): void
    {
        Queue::fake();

        Http::fake([
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response(
                ['error_summary' => 'expired_access_token'],
                401,
            ),
        ]);

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->membership($user, $organization, UserRole::Studio);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                'dropbox-expired-token',
            ),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame(
                    MediaConnection::STATUS_NEEDS_RECONNECT,
                    $fresh->status,
                );
                $this->assertSame('connector_unauthorized', $fresh->last_error);
                $this->assertNull($fresh->next_scan_at);
            },
        );

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
