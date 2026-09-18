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

class GoogleDriveChangesScanTest extends TestCase
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

    public function test_google_scan_bootstraps_then_advances_to_new_start_page_token(): void
    {
        $baselineBytes = 'baseline-google-bytes';
        $changedBytes = 'changed-google-bytes';

        Http::fake(function (Request $request) use ($baselineBytes, $changedBytes) {
            $url = $request->url();

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/changes/startPageToken?',
            )) {
                return Http::response([
                    'startPageToken' => 'changes-start-100',
                ]);
            }

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/files?',
            )) {
                return Http::response([
                    'files' => [[
                        'id' => 'baseline-file',
                        'name' => 'baseline.mp4',
                        'mimeType' => 'video/mp4',
                        'size' => (string) strlen($baselineBytes),
                        'modifiedTime' => '2026-09-18T16:00:00Z',
                        'md5Checksum' => 'baseline-md5',
                        'parents' => ['root-folder'],
                        'trashed' => false,
                        'capabilities' => ['canDownload' => true],
                    ]],
                ]);
            }

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/changes?',
            )) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                $this->assertSame(
                    'changes-start-100',
                    $query['pageToken'] ?? null,
                );

                return Http::response([
                    'changes' => [
                        [
                            'fileId' => 'changed-file',
                            'removed' => false,
                            'file' => [
                                'id' => 'changed-file',
                                'name' => 'changed.mp4',
                                'mimeType' => 'video/mp4',
                                'size' => (string) strlen($changedBytes),
                                'modifiedTime' => '2026-09-18T16:05:00Z',
                                'md5Checksum' => 'changed-md5',
                                'parents' => ['root-folder'],
                                'trashed' => false,
                                'capabilities' => ['canDownload' => true],
                            ],
                        ],
                        [
                            'fileId' => 'removed-file',
                            'removed' => true,
                        ],
                    ],
                    'newStartPageToken' => 'changes-start-200',
                ]);
            }

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/files/baseline-file?',
            )) {
                return Http::response($baselineBytes, 200, [
                    'Content-Type' => 'video/mp4',
                ]);
            }

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/files/changed-file?',
            )) {
                return Http::response($changedBytes, 200, [
                    'Content-Type' => 'video/mp4',
                ]);
            }

            return Http::response(status: 404);
        });

        [$user, $organization, $connection] = $this->connection('root-folder');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());
                $cursor = GoogleDriveScanCursor::decode($fresh->cursor);

                $this->assertNotNull($cursor);
                $this->assertFalse($cursor->isBootstrap());
                $this->assertSame('changes-start-200', $cursor->pageToken);
                $this->assertNull($fresh->last_error);
                $this->assertNotNull($fresh->last_scan_at);
                $this->assertNotNull($fresh->next_scan_at);
            },
        );

        $this->assertSame(2, MediaIngestion::query()->count());
        $this->assertSame(
            ['baseline-file', 'changed-file'],
            MediaIngestion::query()
                ->orderBy('created_at')
                ->get()
                ->map(fn (MediaIngestion $item): string => (string) $item->metadata['remote_id'])
                ->all(),
        );

        Queue::assertPushed(IngestMediaObject::class, 2);

        $sent = Http::recorded();

        $startIndex = $this->requestIndex($sent, '/changes/startPageToken?');
        $filesIndex = $this->requestIndex($sent, '/drive/v3/files?');

        $this->assertLessThan($filesIndex, $startIndex);
    }

    public function test_page_budget_persists_bootstrap_transition_and_resumes_changes(): void
    {
        config([
            'grindflow.media.connector_scan_page_budget' => 1,
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/changes/startPageToken?',
            )) {
                return Http::response([
                    'startPageToken' => 'changes-start-budget',
                ]);
            }

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/files?',
            )) {
                return Http::response([
                    'files' => [],
                ]);
            }

            if (str_starts_with(
                $url,
                'https://www.googleapis.com/drive/v3/changes?',
            )) {
                return Http::response([
                    'changes' => [],
                    'newStartPageToken' => 'changes-after-budget',
                ]);
            }

            return Http::response(status: 404);
        });

        [$user, $organization, $connection] = $this->connection();

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                $scanner = app(MediaConnectionScanner::class);

                $scanner->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $afterBootstrap = MediaConnection::query()
                    ->findOrFail($connection->getKey());
                $cursor = GoogleDriveScanCursor::decode(
                    $afterBootstrap->cursor,
                );

                $this->assertNotNull($cursor);
                $this->assertFalse($cursor->isBootstrap());
                $this->assertSame(
                    'changes-start-budget',
                    $cursor->pageToken,
                );
                $this->assertSame(
                    'scan_page_budget_reached',
                    $afterBootstrap->last_error,
                );

                $scanner->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $afterChanges = MediaConnection::query()
                    ->findOrFail($connection->getKey());
                $cursor = GoogleDriveScanCursor::decode(
                    $afterChanges->cursor,
                );

                $this->assertNotNull($cursor);
                $this->assertSame(
                    'changes-after-budget',
                    $cursor->pageToken,
                );
                $this->assertNull($afterChanges->last_error);
            },
        );

        Http::assertSentCount(3);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<int, array{0: Request, 1: mixed}>  $recorded
     */
    private function requestIndex(array $recorded, string $needle): int
    {
        foreach ($recorded as $index => [$request]) {
            if (str_contains($request->url(), $needle)) {
                return $index;
            }
        }

        $this->fail('Expected HTTP request containing '.$needle);

        return -1;
    }

    /**
     * @return array{User, Organization, MediaConnection}
     */
    private function connection(
        ?string $rootFolderId = null,
    ): array {
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
                    'Google Drive Changes',
                    $rootFolderId,
                    30,
                    'google-scan-refresh-token',
                    now()->addHour(),
                    ['https://www.googleapis.com/auth/drive.readonly'],
                ),
        );

        return [$user, $organization, $connection];
    }
}
