<?php

namespace App\Services\Media\Connectors;

use App\Models\MediaIngestion;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class DropboxMediaAdapter
{
    private const API_BASE = 'https://api.dropboxapi.com/2';

    private const CONTENT_BASE = 'https://content.dropboxapi.com/2';

    public function __construct(
        private readonly ConnectorMediaStager $stager,
        private readonly ConnectorHttpPolicy $httpPolicy,
    ) {}

    public function listInitial(
        string $accessToken,
        ?string $rootPath = null,
    ): RemoteMediaListing {
        $path = $rootPath === null || $rootPath === '/'
            ? ''
            : rtrim($rootPath, '/');

        return $this->listingRequest(
            $accessToken,
            '/files/list_folder',
            [
                'path' => $path,
                'recursive' => true,
                'include_deleted' => false,
                'include_media_info' => false,
                'limit' => 500,
            ],
        );
    }

    public function listIncremental(
        string $accessToken,
        string $cursor,
    ): RemoteMediaListing {
        if ($cursor === '') {
            throw MediaConnectorException::requestFailed();
        }

        return $this->listingRequest(
            $accessToken,
            '/files/list_folder/continue',
            ['cursor' => $cursor],
        );
    }

    public function stageAndQueue(
        User $actor,
        string $accessToken,
        RemoteMediaFile $file,
    ): MediaIngestion {
        return $this->stager->stageAndQueue(
            $actor,
            'dropbox',
            $file,
            fn () => $this->downloadStream($accessToken, $file->id),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function listingRequest(
        string $accessToken,
        string $path,
        array $payload,
    ): RemoteMediaListing {
        $this->httpPolicy->assertAccessToken($accessToken);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::API_BASE.$path, $payload);
        } catch (ConnectionException) {
            throw MediaConnectorException::requestFailed();
        }

        $this->httpPolicy->assertSuccessful($response);

        $data = $response->json();

        if (is_array($data) === false) {
            throw MediaConnectorException::requestFailed();
        }

        $entries = $data['entries'] ?? [];
        $files = [];

        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (
                    is_array($entry) === false
                    || ($entry['.tag'] ?? null) !== 'file'
                ) {
                    continue;
                }

                $file = $this->mapFile($entry);

                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        $hasMore = (bool) ($data['has_more'] ?? false);
        $cursor = is_string($data['cursor'] ?? null)
            ? $data['cursor']
            : null;

        if ($hasMore && ($cursor === null || $cursor === '')) {
            throw MediaConnectorException::requestFailed();
        }

        return new RemoteMediaListing(
            files: $files,
            cursor: $cursor,
            hasMore: $hasMore,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function mapFile(array $entry): ?RemoteMediaFile
    {
        $id = $entry['id'] ?? null;
        $name = $entry['name'] ?? null;
        $path = $entry['path_display'] ?? $entry['path_lower'] ?? null;
        $size = $entry['size'] ?? null;
        $modifiedAt = $entry['server_modified'] ?? null;

        if (
            is_string($id) === false
            || is_string($name) === false
            || is_string($path) === false
            || is_numeric($size) === false
            || is_string($modifiedAt) === false
            || (int) $size < 1
        ) {
            return null;
        }

        $checksum = is_string($entry['content_hash'] ?? null)
            ? $entry['content_hash']
            : null;

        return new RemoteMediaFile(
            id: $id,
            name: $name,
            path: $path,
            sizeBytes: (int) $size,
            modifiedAt: $modifiedAt,
            checksum: $checksum,
        );
    }

    /**
     * @return resource
     */
    private function downloadStream(
        string $accessToken,
        string $fileId,
    ) {
        try {
            $argument = json_encode(
                ['path' => $fileId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw MediaConnectorException::downloadFailed();
        }

        $this->httpPolicy->assertAccessToken($accessToken);

        try {
            $response = Http::withOptions([
                'stream' => true,
                'connect_timeout' => 10,
                'timeout' => 300,
            ])
                ->withToken($accessToken)
                ->withHeaders([
                    'Dropbox-API-Arg' => $argument,
                ])
                ->post(self::CONTENT_BASE.'/files/download');
        } catch (ConnectionException) {
            throw MediaConnectorException::downloadFailed();
        }

        $this->httpPolicy->assertSuccessful($response, true);

        return $this->httpPolicy->detachStream($response);
    }
}
