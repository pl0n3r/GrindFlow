<?php

namespace App\Services\Media\Connectors;

use App\Models\MediaIngestion;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleDriveMediaAdapter
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';

    public function __construct(
        private readonly ConnectorMediaStager $stager,
    ) {}

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
        $this->assertAccessToken($accessToken);

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

        $this->assertSuccessful($response);

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

                $file = $this->mapFile($entry);

                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        $cursor = is_string($data['nextPageToken'] ?? null)
            && $data['nextPageToken'] !== ''
            ? $data['nextPageToken']
            : null;

        return new RemoteMediaListing(
            files: $files,
            cursor: $cursor,
            hasMore: $cursor !== null,
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
            'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,md5Checksum,capabilities(canDownload))',
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
    private function mapFile(array $entry): ?RemoteMediaFile
    {
        $id = $entry['id'] ?? null;
        $name = $entry['name'] ?? null;
        $mimeType = $entry['mimeType'] ?? null;
        $size = $entry['size'] ?? null;
        $modifiedAt = $entry['modifiedTime'] ?? null;
        $capabilities = $entry['capabilities'] ?? null;

        if (
            is_string($id) === false
            || is_string($name) === false
            || is_string($mimeType) === false
            || is_numeric($size) === false
            || is_string($modifiedAt) === false
            || (int) $size < 1
            || is_array($capabilities) === false
            || ($capabilities['canDownload'] ?? false) !== true
            || $this->isSupportedMediaMime($mimeType) === false
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

    private function isSupportedMediaMime(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || str_starts_with($mimeType, 'video/');
    }

    /**
     * @return resource
     */
    private function downloadStream(
        string $accessToken,
        string $fileId,
    ) {
        $this->assertAccessToken($accessToken);

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

        $this->assertSuccessful($response, true);

        $stream = $response->toPsrResponse()
            ->getBody()
            ->detach();

        if (is_resource($stream) === false) {
            throw MediaConnectorException::downloadFailed();
        }

        return $stream;
    }

    private function assertSuccessful(
        Response $response,
        bool $download = false,
    ): void {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            throw MediaConnectorException::unauthorized();
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');
            $retryAfterSeconds = is_numeric($retryAfter)
                ? max(1, min((int) $retryAfter, 3600))
                : null;

            throw MediaConnectorException::rateLimited(
                $retryAfterSeconds,
            );
        }

        throw $download
            ? MediaConnectorException::downloadFailed()
            : MediaConnectorException::requestFailed();
    }

    private function assertAccessToken(string $accessToken): void
    {
        if ($accessToken === '') {
            throw MediaConnectorException::unauthorized();
        }
    }
}
