<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Object-storage boundary for large direct uploads.
 *
 * Implementations may presign provider requests, but callers never receive
 * permanent credentials. The local quick-upload Vault remains a separate path.
 */
interface DirectUploadStorage
{
    public function available(): bool;

    public function disk(): string;

    public function driver(): string;

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function temporaryUpload(
        string $storageKey,
        string $mimeType,
        int $expiresAt,
    ): array;

    public function exists(string $storageKey): bool;

    public function size(string $storageKey): ?int;

    /** @return resource|null */
    public function readStream(string $storageKey);

    public function delete(string $storageKey): void;

    public function promote(string $stagingKey, string $finalKey): void;
}
