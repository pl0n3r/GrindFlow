<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Safe default while no S3-compatible media store is configured.
 *
 * Vault rendering and small local uploads remain available; any attempt to use
 * the large-file transport fails closed before provider I/O.
 */
final class UnavailableDirectUploadStorage implements DirectUploadStorage
{
    public function __construct(private readonly string $diskName = 'media')
    {
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $diskName) !== 1) {
            throw new \InvalidArgumentException('Direct upload disk name is invalid.');
        }
    }

    public function available(): bool
    {
        return false;
    }

    public function disk(): string
    {
        return $this->diskName;
    }

    public function driver(): string
    {
        return 'unavailable';
    }

    public function temporaryUpload(string $storageKey, string $mimeType, int $expiresAt): array
    {
        throw $this->unavailable();
    }

    public function exists(string $storageKey): bool
    {
        return false;
    }

    public function size(string $storageKey): ?int
    {
        return null;
    }

    public function readStream(string $storageKey)
    {
        return null;
    }

    public function delete(string $storageKey): void
    {
        throw $this->unavailable();
    }

    public function promote(string $stagingKey, string $finalKey): void
    {
        throw $this->unavailable();
    }

    private function unavailable(): \RuntimeException
    {
        return new \RuntimeException('Direct upload storage is not configured.');
    }
}
