<?php

namespace App\Services\Media\Connectors;

use App\Models\MediaIngestion;
use App\Models\User;
use App\Services\Media\MediaIngestionCoordinator;
use App\Services\Media\StagedMediaSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class DropboxMediaAdapter
{
    private const API_BASE = 'https://api.dropboxapi.com/2';

    private const CONTENT_BASE = 'https://content.dropboxapi.com/2';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MediaIngestionCoordinator $coordinator,
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
        $organizationId = $this->tenantContext->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException(
                'Tenant context is required to stage connector media.',
            );
        }

        if ($actor->canManageOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot manage connector media for this organization.',
            );
        }

        if ($file->sizeBytes > $this->maxBytes()) {
            throw MediaConnectorException::fileTooLarge();
        }

        $sourceRef = $this->sourceRef($file);
        $existing = $this->coordinator->findExistingSource(
            $actor,
            'dropbox',
            $sourceRef,
        );

        if ($existing instanceof MediaIngestion) {
            return $existing;
        }

        $disk = $this->stagingDisk();
        $storageKey = sprintf(
            'organizations/%s/staging/connectors/dropbox/%s',
            $organizationId,
            Str::uuid(),
        );

        $stream = $this->downloadStream($accessToken, $file->id);

        try {
            try {
                $stored = Storage::disk($disk)->put($storageKey, $stream);
            } catch (Throwable) {
                throw MediaConnectorException::stagingFailed();
            }
        } finally {
            fclose($stream);
        }

        if ($stored === false) {
            $this->deleteStaged($disk, $storageKey);

            throw MediaConnectorException::stagingFailed();
        }

        try {
            try {
                $stagedSize = Storage::disk($disk)->size($storageKey);
            } catch (Throwable) {
                throw MediaConnectorException::stagingFailed();
            }

            if ($stagedSize !== $file->sizeBytes) {
                throw MediaConnectorException::stagingFailed();
            }

            $source = new StagedMediaSource(
                sourceType: 'dropbox',
                sourceRef: $sourceRef,
                sourceDisk: $disk,
                sourceKey: $storageKey,
                originalFilename: $file->name,
                mimeType: null,
                byteSize: $file->sizeBytes,
                deleteAfterIngest: true,
                metadata: [
                    'provider' => 'dropbox',
                    'remote_id' => $file->id,
                    'remote_path' => $file->path,
                    'remote_modified_at' => $file->modifiedAt,
                    'provider_checksum' => $file->checksum,
                ],
            );

            $ingestion = $this->coordinator->queueSource($actor, $source);

            if ($ingestion->source_key !== $storageKey) {
                $this->deleteStaged($disk, $storageKey);
            }

            return $ingestion;
        } catch (Throwable $exception) {
            $this->deleteStaged($disk, $storageKey);

            throw $exception;
        }
    }

    private function listingRequest(
        string $accessToken,
        string $path,
        array $payload,
    ): RemoteMediaListing {
        $this->assertAccessToken($accessToken);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::API_BASE.$path, $payload);
        } catch (ConnectionException) {
            throw MediaConnectorException::requestFailed();
        }

        $this->assertSuccessful($response);

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

        $cursor = is_string($data['cursor'] ?? null)
            ? $data['cursor']
            : null;

        return new RemoteMediaListing(
            files: $files,
            cursor: $cursor,
            hasMore: (bool) ($data['has_more'] ?? false),
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

        $this->assertAccessToken($accessToken);

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

    private function deleteStaged(string $disk, string $storageKey): void
    {
        try {
            Storage::disk($disk)->delete($storageKey);
        } catch (Throwable) {
            // Cleanup is best-effort. The original safe connector error wins.
        }
    }

    private function sourceRef(RemoteMediaFile $file): string
    {
        return sprintf(
            'dropbox:%s:%s',
            $file->id,
            substr(hash('sha256', $file->versionFingerprint()), 0, 32),
        );
    }

    private function stagingDisk(): string
    {
        return (string) config(
            'grindflow.media.staging_disk',
            'media',
        );
    }

    private function maxBytes(): int
    {
        $configured = (int) config(
            'grindflow.media.connector_max_bytes',
            2_147_483_648,
        );

        return max(1, min($configured, 2_147_483_648));
    }
}
