<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Verifies a staged direct-upload object without mutating storage or catalog rows.
 */
final class DirectUploadCompletionVerifier
{
    public function __construct(
        private readonly DirectUploadStorage $storage,
        private readonly DirectUploadTokenCipher $tokens,
        private readonly DirectUploadObjectKeys $keys,
    ) {
    }

    /**
     * @return array{
     *   organization_id: string,
     *   user_id: string,
     *   disk: string,
     *   staging_key: string,
     *   final_key: string,
     *   filename: string,
     *   mime_type: string,
     *   byte_size: int,
     *   sha256: string
     * }
     */
    public function verify(
        string $uploadToken,
        string $organizationId,
        string $userId,
        ?int $now = null,
    ): array {
        if (!$this->storage->available() || !$this->tokens->configured()) {
            throw new \RuntimeException('Direct upload is not configured.');
        }

        $payload = $this->tokens->decryptFor(
            $uploadToken,
            $organizationId,
            $userId,
            $this->storage->disk(),
            $now,
        );
        if ($payload === null) {
            throw new \InvalidArgumentException('Direct upload token is invalid or expired.');
        }

        $stagingKey = $payload['storage_key'];
        if (!$this->storage->exists($stagingKey)) {
            throw new \InvalidArgumentException('Direct upload object was not found.');
        }

        $actualSize = $this->storage->size($stagingKey);
        if ($actualSize === null || $actualSize !== $payload['byte_size']) {
            throw new \InvalidArgumentException('Direct upload object size does not match the approved upload.');
        }

        $stream = $this->storage->readStream($stagingKey);
        if (!is_resource($stream)) {
            throw new \RuntimeException('Direct upload object cannot be opened for integrity verification.');
        }

        try {
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $stream);
            if ($read === false) {
                throw new \RuntimeException('Direct upload object could not be fully hashed.');
            }
            if ($read !== $payload['byte_size']) {
                throw new \InvalidArgumentException('Direct upload object stream size does not match the approved upload.');
            }
            $sha256 = hash_final($hash);
        } finally {
            fclose($stream);
        }

        return [
            'organization_id' => $payload['organization_id'],
            'user_id' => $payload['user_id'],
            'disk' => $payload['disk'],
            'staging_key' => $stagingKey,
            'final_key' => $this->keys->blob($organizationId, $sha256),
            'filename' => $payload['filename'],
            'mime_type' => $payload['mime_type'],
            'byte_size' => $payload['byte_size'],
            'sha256' => $sha256,
        ];
    }
}
