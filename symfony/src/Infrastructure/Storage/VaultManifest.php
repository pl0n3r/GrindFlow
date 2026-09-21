<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Deterministic Vault catalog fingerprint. Includes retained trash and all
 * metadata needed to detect an incomplete catalog restoration.
 */
final class VaultManifest
{
    /** @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    public static function canonical(array $asset): array
    {
        return [
            'id' => (string) $asset['id'],
            'uploaded_by' => (string) $asset['uploaded_by'],
            'original_name' => (string) $asset['original_name'],
            'mime_type' => (string) $asset['mime_type'],
            'size_bytes' => (int) $asset['size_bytes'],
            'sha256' => strtolower((string) $asset['sha256']),
            'storage_key' => (string) $asset['storage_key'],
            'created_at' => (string) $asset['created_at'],
            'deleted_at' => $asset['deleted_at'] === null ? null : (string) $asset['deleted_at'],
            'deleted_by' => $asset['deleted_by'] === null ? null : (string) $asset['deleted_by'],
            'private_note' => $asset['private_note'] === null ? null : (string) $asset['private_note'],
            'usage_scope' => (string) $asset['usage_scope'],
        ];
    }

    /** @param list<array<string,mixed>> $assets */
    public static function digest(array $assets): string
    {
        $hash = hash_init('sha256');
        foreach ($assets as $asset) {
            hash_update($hash, json_encode(self::canonical($asset), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        }

        return hash_final($hash);
    }
}
