<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\IngestMediaObject;
use App\Models\MediaConnection;
use App\Models\MediaIngestion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connections\GoogleDriveScanCursor;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connections\MediaConnectionScanner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveIncrementalScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'grindflow.security.encryption_master_key' => str_repeat('ab', 32),
            'grindflow.media.staging_disk' => 'media',
            'grindflow.media.connector_scan_page_budget' => 20,
        ]);

        Queue::fake([IngestMediaObject::class]);
        Storage::fake('media');
    }

    public function test_google_scan_captures_start_token_before_bootstrap_and_advances_changes_cursor(): void
    {
        $baselineBytes = 'google-baseline-media';
        $changedBytes = 'google-changed-media';
        $requests = [];

        Http::fake(function (Request $request) use (
            $baselineBytes,
            $changedBytes,
            &$requests,
        ) {
            $requests[] = $request->url();

            if (str_contains($request->url(), '/changes/startPageToken')) {
                return Http::response([
                    'startPageToken' => 'changes-start-100',
                ]);
            }

            if (
                str_starts_with(
                    $request->url(),
                    'https://www.googleapis.com/drive/v3/files?',
                )
            ) {
                return Http::response([
                    'files' => [[
                        'id' => 'baseline-1',
                        'name' => 'baseline.mp4',
                        'mimeType' => 'video/mp4',
                        'size' => (string) strlen($baselineBytes),
                        'modifiedTime' => '2026-09-18T14:00:00Z',
                        'md5Checksum' => 'baseline-version',
                        'parents' => ['root'],
                        'trashed' => false,
                        'capabilities' => ['canDownload' => true],
                    ]],
                ]);
            }

            if (
                str_starts_with(
                    $request->url(),
                    'https://www.googleapis.com/drive/v3/changes?',
                )
            ) {
                return Http::response([
                    'changes' => [[
                        'fileId' => 'changed-1',
                        'removed' => false,
                        'file' => [
                            'id' => 'changed-1',
                            'name' => 'changed.mp4',
                            'mimeType' => 'video/mp4',
                            'size' => (string) strlen($changedBytes),
                            'modifiedTime' => '2026-09-18T14:30:00Z',
                            'md5Checksum' => 'changed-version',
                            'parents' => ['root'],
                            'trashed' => false,
                            'capabilities' => ['canDownload' => true],
                        ],
                    ]],
                    'newStartPageToken' => 'changes-start-200',
                ]);
            }

            if (str_contains($request->url(), '/files/baseline-1?')) {
                return Http::response(
                    $baselineBytes,
                    200,
                    ['Content-Type' => 'video/mp4'],
                );
            }

            if (str_contains($request->url(), '/files/changed-1?')) {
                return Http::response(
                    $changedBytes,
                    200,
                    ['Content-Type' => 'video/mp4'],
                );
            }

            return Http::response(status: 404);
        });

        [$user, $organization, $connection] = $this->connection(30);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );
            },
        );

        $fresh = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => MediaConnection::query()
                ->findOrFail($connection->getKey()),
        );

        $cursor = GoogleDriveScanCursor::decode($fresh->cursor);

        $this->assertNotNull($cursor);
        $this->assertFalse($cursor->isBootstrap());
        $this->assertSame('changes-start-200', $cursor->pageToken);
        $this->assertSame(MediaConnection::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->last_error);
        $this->assertSame(0, $fresh->consecutive_failures);
        $this->assertNotNull($fresh->last_scan_at);
        $this->assertNotNull($fresh->next_scan_at);
        $this->assertTrue(
            $fresh->next_scan_at->isAfter(now()->addMinutes(29)),
        );

        $ingestionCount = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): int => MediaIngestion::query()->count(),
        );

        $this->assertSame(2, $ingestionCount);
        Queue::assertPushed(IngestMediaObject::class, 2);

        $startIndex = $this->requestIndex(
            $requests,
            '/changes/startPageToken',
        );
        $filesIndex = $this->requestIndex(
            $requests,
            '/drive/v3/files?',
        );

        $this->assertLessThan($filesIndex, $startIndex);
    }

    public function test_page_budget_resumes_from_changes_cursor_without_repeating_bootstrap(): void
    {
        config([
            'grindflow.media.connector_scan_page_budget' => 1,
        ]);

        $startRequests = 0;
        $fileListRequests = 0;
        $changeRequests = 0;

        Http::fake(function (Request $request) use (
            &$startRequests,
            &$fileListRequests,
            &$changeRequests,
        ) {
            if (str_contains($request->url(), '/changes/startPageToken')) {
                $startRequests++;

                return Http::response([
                    'startPageToken' => 'changes-start-500',
                ]);
            }

            if (
                str_starts_with(
                    $request->url(),
                    'https://www.googleapis.com/drive/v3/files?',
                )
            ) {
                $fileListRequests++;

                return Http::response([
                    'files' => [],
                ]);
            }

            if (
                str_starts_with(
                    $request->url(),
                    'https://www.googleapis.com/drive/v3/changes?',
                )
            ) {
                $changeRequests++;

                return Http::response([
                    'changes' => [],
                    'newStartPageToken' => 'changes-start-600',
                ]);
            }

            return Http::response(status: 404);
        });

        [$user, $organization, $connection] = $this->connection(15);

        $this->scan($user, $organization, $connection);

        $afterBootstrap = $this->fresh(
            $user,
            $organization,
            $connection,
        );
        $bootstrapCursor = GoogleDriveScanCursor::decode(
            $afterBootstrap->cursor,
        );

        $this->assertNotNull($bootstrapCursor);
        $this->assertFalse($bootstrapCursor->isBootstrap());
        $this->assertSame(
            'changes-start-500',
            $bootstrapCursor->pageToken,
        );
        $this->assertSame(
            'scan_page_budget_reached',
            $afterBootstrap->last_error,
        );

        $this->scan($user, $organization, $connection);

        $afterChanges = $this->fresh(
            $user,
            $organization,
            $connection,
        );
        $changesCursor = GoogleDriveScanCursor::decode(
            $afterChanges->cursor,
        );

        $this->assertNotNull($changesCursor);
        $this->assertSame(
            'changes-start-600',
            $changesCursor->pageToken,
        );
        $this->assertNull($afterChanges->last_error);
        $this->assertSame(1, $startRequests);
        $this->assertSame(1, $fileListRequests);
        $this->assertSame(1, $changeRequests);
        Queue::assertNothingPushed();
    }

    /**
     * @return array{User, Organization, MediaConnection}
     */
    private function connection(int $scanIntervalMinutes): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)
                ->connectGoogleDrive(
                    $user,
                    'google-scan-access-token',
                    'Google Drive Incremental',
                    null,
                    $scanIntervalMinutes,
                ),
        );

        return [$user, $organization, $connection];
    }

    private function scan(
        User $user,
        Organization $organization,
        MediaConnection $connection,
    ): void {
        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaConnectionScanner::class)->scan(
                MediaConnection::query()->findOrFail($connection->getKey()),
                $user,
            ),
        );
    }

    private function fresh(
        User $user,
        Organization $organization,
        MediaConnection $connection,
    ): MediaConnection {
        return app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => MediaConnection::query()
                ->findOrFail($connection->getKey()),
        );
    }

    /**
     * @param  list<string>  $requests
     */
    private function requestIndex(array $requests, string $needle): int
    {
        foreach ($requests as $index => $url) {
            if (str_contains($url, $needle)) {
                return $index;
            }
        }

        $this->fail('Expected request was not sent: '.$needle);
    }
}
