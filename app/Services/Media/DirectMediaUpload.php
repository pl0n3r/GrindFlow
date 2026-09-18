<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DirectMediaUpload
{
    private const HARD_MAX_BYTES = 2_147_483_648;

    /**
     * @return array<string, mixed>
     */
    public function createIntent(
        Organization $organization,
        User $actor,
        string $filename,
        string $mimeType,
        int $byteSize,
    ): array {
        if ($this->available() === false) {
            throw ValidationException::withMessages([
                'media' => 'Direct upload storage is not configured.',
            ]);
        }

        $maxBytes = $this->maxBytes();

        if ($byteSize < 1 || $byteSize > $maxBytes) {
            throw ValidationException::withMessages([
                'byte_size' => 'The selected file exceeds the direct upload limit.',
            ]);
        }

        $disk = $this->disk();
        $uploadId = (string) Str::uuid();
        $storageKey = sprintf(
            'organizations/%s/staging/%s',
            $organization->getKey(),
            $uploadId,
        );
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        $tokenPayload = [
            'v' => 1,
            'organization_id' => (string) $organization->getKey(),
            'user_id' => (string) $actor->getKey(),
            'disk' => $disk,
            'storage_key' => $storageKey,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'expires_at' => $expiresAt->timestamp,
        ];

        $token = Crypt::encryptString(json_encode(
            $tokenPayload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        $upload = Storage::disk($disk)->temporaryUploadUrl(
            $storageKey,
            $expiresAt,
            ['ContentType' => $mimeType],
        );

        $url = $upload['url'] ?? null;
        $headers = $upload['headers'] ?? null;

        if (is_string($url) === false || is_array($headers) === false) {
            throw new RuntimeException('Unable to create a direct upload URL.');
        }

        $normalizedHeaders = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) === false || is_scalar($value) === false) {
                throw new RuntimeException('Direct upload returned an unsupported header value.');
            }

            $normalizedHeaders[$name] = (string) $value;
        }

        return [
            'url' => $url,
            'headers' => $normalizedHeaders,
            'upload_token' => $token,
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ];
    }

    public function complete(
        string $uploadToken,
        Organization $organization,
        User $actor,
    ): MediaAsset {
        $payload = $this->decodeToken($uploadToken);

        $this->assertTokenContext($payload, $organization, $actor);

        $disk = (string) $payload['disk'];
        $storageKey = (string) $payload['storage_key'];
        $expectedSize = (int) $payload['byte_size'];
        $mimeType = (string) $payload['mime_type'];

        $filesystem = Storage::disk($disk);

        if ($filesystem->exists($storageKey) === false) {
            throw ValidationException::withMessages([
                'upload_token' => 'The uploaded object was not found.',
            ]);
        }

        $actualSize = $filesystem->size($storageKey);

        if ($actualSize !== $expectedSize) {
            throw ValidationException::withMessages([
                'upload_token' => 'The uploaded object size does not match the approved upload.',
            ]);
        }

        $stream = $filesystem->readStream($storageKey);

        if ($stream === false) {
            throw new RuntimeException('Unable to open the uploaded object for integrity verification.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $sha256 = hash_final($hash);
        } finally {
            fclose($stream);
        }

        $existingBlob = MediaBlob::query()
            ->where('sha256', $sha256)
            ->first();

        if ($existingBlob instanceof MediaBlob) {
            $filesystem->delete($storageKey);
            $blob = $existingBlob;
        } else {
            $finalKey = sprintf(
                'organizations/%s/blobs/%s/%s',
                $organization->getKey(),
                substr($sha256, 0, 2),
                $sha256,
            );

            if ($storageKey !== $finalKey && $filesystem->move($storageKey, $finalKey) === false) {
                throw new RuntimeException('Unable to promote the uploaded object into the media vault.');
            }

            $blob = MediaBlob::query()->firstOrCreate(
                ['sha256' => $sha256],
                [
                    'storage_disk' => $disk,
                    'storage_key' => $finalKey,
                    'byte_size' => $actualSize,
                    'mime_type' => $mimeType,
                    'metadata' => [
                        'upload_mode' => 'direct',
                        'integrity' => 'sha256_verified',
                    ],
                ],
            );
        }

        $canonicalAsset = MediaAsset::query()
            ->where('media_blob_id', $blob->getKey())
            ->whereNull('duplicate_of')
            ->oldest('created_at')
            ->first();

        return MediaAsset::query()->create([
            'media_blob_id' => $blob->getKey(),
            'duplicate_of' => $canonicalAsset?->getKey(),
            'ingested_by_user_id' => $actor->getKey(),
            'original_filename' => (string) $payload['filename'],
            'source_type' => 'direct_upload',
            'source_ref' => null,
            'status' => $canonicalAsset === null
                ? MediaAsset::STATUS_READY
                : MediaAsset::STATUS_DUPLICATE,
            'metadata' => [
                'upload_mode' => 'direct',
                'verified_sha256' => true,
            ],
        ]);
    }

    public function available(): bool
    {
        $disk = $this->disk();
        $config = config('filesystems.disks.'.$disk);

        if (is_array($config) === false || ($config['driver'] ?? null) !== 's3') {
            return false;
        }

        return filled($config['key'] ?? null)
            && filled($config['secret'] ?? null)
            && filled($config['bucket'] ?? null);
    }

    public function maxBytes(): int
    {
        $configured = (int) config(
            'grindflow.media.direct_upload_max_bytes',
            self::HARD_MAX_BYTES,
        );

        return max(1, min($configured, self::HARD_MAX_BYTES));
    }

    private function disk(): string
    {
        return (string) config('grindflow.media.direct_upload_disk', 's3');
    }

    private function ttlMinutes(): int
    {
        $configured = (int) config('grindflow.media.direct_upload_ttl_minutes', 15);

        return max(5, min($configured, 60));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeToken(string $uploadToken): array
    {
        try {
            $decoded = Crypt::decryptString($uploadToken);
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException) {
            throw ValidationException::withMessages([
                'upload_token' => 'The upload token is invalid.',
            ]);
        }

        if (is_array($payload) === false || ($payload['v'] ?? null) !== 1) {
            throw ValidationException::withMessages([
                'upload_token' => 'The upload token version is invalid.',
            ]);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertTokenContext(
        array $payload,
        Organization $organization,
        User $actor,
    ): void {
        $organizationId = (string) $organization->getKey();
        $storageKey = (string) ($payload['storage_key'] ?? '');
        $expectedPrefix = 'organizations/'.$organizationId.'/staging/';

        $valid = hash_equals(
            $organizationId,
            (string) ($payload['organization_id'] ?? ''),
        )
            && hash_equals(
                (string) $actor->getKey(),
                (string) ($payload['user_id'] ?? ''),
            )
            && ($payload['disk'] ?? null) === $this->disk()
            && str_starts_with($storageKey, $expectedPrefix)
            && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp
            && is_string($payload['filename'] ?? null)
            && is_string($payload['mime_type'] ?? null)
            && (int) ($payload['byte_size'] ?? 0) > 0
            && (int) ($payload['byte_size'] ?? 0) <= $this->maxBytes();

        if ($valid === false) {
            throw ValidationException::withMessages([
                'upload_token' => 'The upload token does not match the current tenant or has expired.',
            ]);
        }
    }
}
