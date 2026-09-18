<?php

namespace App\Services\Media\Connectors;

use App\Models\MediaIngestion;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GoogleDriveMediaAdapter
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';

    public function __construct(
        private readonly ConnectorMediaStager $stager,
        private readonly ConnectorHttpPolicy $httpPolicy,
    ) {}

    public function startPageToken(string $accessToken): string
    {
        $this->httpPolicy->assertAccessToken($accessToken);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get(self::API_BASE.'/changes/startPageToken', [
                    'supportsAllDrives' => true,
                    'fields' => 'startPageToken',
                ]);
        } catch (ConnectionException) {
            throw MediaConnectorException::requestFailed();
        }

        $this->httpPolicy->assertSuccessful($response);

        $token = $response->json('startPageToken');

        if (is_string($token) === false || $token === '') {
            throw MediaConnectorException::requestFailed();
        }

        return $token;
    }

    public function listInitial(
        string $accessToken,
        ?string $rootFolderId = null,
    ): RemoteMediaListing {
        return $this->listPage(
            $accessToken,
            null,
            $rootFolderId,
        );
    }

    public function listPage(
        string $accessToken,
        ?string $pageToken = null,
        ?string $rootFolderId = null,
    ): RemoteMediaListing {
        $this->httpPolicy->assertAccessToken($accessToken);

        if ($pageToken === '') {
            throw MediaConnectorException::requestFailed();
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get(self::API_BASE.'/files', $this->listQuery(
                    $pageToken,
                    $rootFolderId,
                ));
        } catch (ConnectionException) {
            throw MediaConnectorException::requestFailed();
        }

        $this->httpPolicy->assertSuccessful($response);

        $data = $response->json();

        if (is_array($data) === false) {
            throw MediaConnectorException::requestFailed();
        }

        $entries = $data['files'] ?? [];
        $files = [];

        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (is_array($entry) === false) {
                    continue;
                }

                $file = $this->mapFile($entry, $rootFolderId);

                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        $cursor = $this->optionalToken($data['nextPageToken'] ?? null);

        return new RemoteMediaListing(
            files: $files,
            cursor: $cursor,
            hasMore: $cursor !== null,
        );
    }

    public function listChanges(
        string $accessToken,
        string $pageToken,
        ?string $rootFolderId = null,
    ): GoogleDriveChangeListing {
        $this->httpPolicy->assertAccessToken($accessToken);

        if ($pageToken === '') {
            throw MediaConnectorException::requestFailed();
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get(self::API_BASE.'/changes', [
                    'pageToken' => $pageToken,
                    'pageSize' => 500,
                    'spaces' => 'drive',
                    'includeRemoved' => true,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                    'fields' => 'nextPageToken,newStartPageToken,changes(fileId,removed,file(id,name,mimeType,size,modifiedTime,md5Checksum,parents,trashed,capabilities(canDownload)))',
                ]);
        } catch (ConnectionException) {
            throw MediaConnectorException::requestFailed();
        }

        $this->httpPolicy->assertSuccessful($response);

        $data = $response->json();

        if (is_array($data) === false) {
            throw MediaConnectorException::requestFailed();
        }

        $files = [];
        $changes = $data['changes'] ?? [];

        if (is_array($changes)) {
            foreach ($changes as $change) {
                if (
                    is_array($change) === false
                    || ($change['removed'] ?? false) === true
                    || is_array($change['file'] ?? null) === false
                ) {
                    continue;
                }

                $file = $this->mapFile(
                    $change['file'],
                    $rootFolderId,
                );

                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        return new GoogleDriveChangeListing(
            files: $files,
            nextPageToken: $this->optionalToken(
                $data['nextPageToken'] ?? null,
            ),
            newStartPageToken: $this->optionalToken(
                $data['newStartPageToken'] ?? null,
            ),
        );
    }

    public function stageAndQueue(
        User $actor,
        string $accessToken,
        RemoteMediaFile $file,
    ): MediaIngestion {
        return $this->stager->stageAndQueue(
            $actor,
            'google_drive',
            $file,
            fn () => $this->downloadStream($accessToken, $file->id),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function listQuery(
        ?string $pageToken,
        ?string $rootFolderId,
    ): array {
        $query = [
            'corpora' => 'user',
            'spaces' => 'drive',
            'pageSize' => 500,
            'q' => $this->searchQuery($rootFolderId),
            'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,md5Checksum,parents,trashed,capabilities(canDownload))',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ];

        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        return $query;
    }

    private function searchQuery(?string $rootFolderId): string
    {
        if ($rootFolderId === null || $rootFolderId === '') {
            return 'trashed = false';
        }

        $escaped = str_replace(
            ['\\', "'"],
            ['\\\\', "\\'"],
            $rootFolderId,
        );

        return sprintf(
            "'%s' in parents and trashed = false",
            $escaped,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function mapFile(
        array $entry,
        ?string $rootFolderId = null,
    ): ?RemoteMediaFile {
        $id = $entry['id'] ?? null;
        $name = $entry['name'] ?? null;
        $mimeType = $entry['mimeType'] ?? null;
        $size = $entry['size'] ?? null;
        $modifiedAt = $entry['modifiedTime'] ?? null;
        $capabilities = $entry['capabilities'] ?? null;

        if (
            ($entry['trashed'] ?? false) === true
            || is_string($id) === false
            || is_string($name) === false
            || is_string($mimeType) === false
            || is_numeric($size) === false
            || is_string($modifiedAt) === false
            || (int) $size < 1
            || is_array($capabilities) === false
            || ($capabilities['canDownload'] ?? false) !== true
            || $this->isSupportedMediaMime($mimeType) === false
            || $this->belongsToRoot($entry, $rootFolderId) === false
        ) {
            return null;
        }

        $checksum = is_string($entry['md5Checksum'] ?? null)
            ? $entry['md5Checksum']
            : null;

        return new RemoteMediaFile(
            id: $id,
            name: $name,
            path: '/'.$name,
            sizeBytes: (int) $size,
            modifiedAt: $modifiedAt,
            checksum: $checksum,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function belongsToRoot(
        array $entry,
        ?string $rootFolderId,
    ): bool {
        if ($rootFolderId === null || $rootFolderId === '') {
            return true;
        }

        $parents = $entry['parents'] ?? null;

        return is_array($parents)
            && in_array($rootFolderId, $parents, true);
    }

    private function isSupportedMediaMime(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || str_starts_with($mimeType, 'video/');
    }

    private function optionalToken(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @return resource
     */
    private function downloadStream(
        string $accessToken,
        string $fileId,
    ) {
        $this->httpPolicy->assertAccessToken($accessToken);

        try {
            $response = Http::withOptions([
                'stream' => true,
                'connect_timeout' => 10,
                'timeout' => 300,
            ])
                ->withToken($accessToken)
                ->get(
                    self::API_BASE.'/files/'.rawurlencode($fileId),
                    [
                        'alt' => 'media',
                        'supportsAllDrives' => true,
                    ],
                );
        } catch (ConnectionException) {
            throw MediaConnectorException::downloadFailed();
        }

        $this->httpPolicy->assertSuccessful($response, true);

        return $this->httpPolicy->detachStream($response);
    }
}
