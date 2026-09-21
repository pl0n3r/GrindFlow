<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Read-only verification of a private original, shared by HTTP delivery and
 * the operator's recovery audit. Never log a blob key, filesystem path or hash.
 */
final class VaultBlobVerifier
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly PrivateVaultDirectory $storage)
    {
    }

    /**
     * @param array{storage_key:mixed,size_bytes:mixed,sha256:mixed} $asset
     * @return 'verified'|'missing'|'mismatch'|'unavailable'
     */
    public function status(array $asset): string
    {
        $root = $this->storage->root();
        $key = (string) $asset['storage_key'];
        $expectedSize = (int) $asset['size_bytes'];
        $expectedHash = (string) $asset['sha256'];

        if (preg_match('/\\A[0-9a-fA-F-]{36}\\z/D', $key) !== 1 || is_link($root) || !is_dir($root)) {
            return 'unavailable';
        }
        $path = $root.'/'.$key.'.blob';
        clearstatcache(true, $path);
        if (is_link($path)) {
            return 'unavailable';
        }
        if (!is_file($path)) {
            return 'missing';
        }
        if (!is_readable($path)) {
            return 'unavailable';
        }
        if ($expectedSize < 1 || $expectedSize > self::MAX_BYTES
            || preg_match('/\\A[a-fA-F0-9]{64}\\z/D', $expectedHash) !== 1) {
            return 'mismatch';
        }
        $actualSize = @filesize($path);
        if ($actualSize === false) {
            return 'unavailable';
        }
        if ($actualSize !== $expectedSize) {
            return 'mismatch';
        }
        $actualHash = @hash_file('sha256', $path);
        if ($actualHash === false) {
            return 'unavailable';
        }

        return hash_equals(strtolower($expectedHash), $actualHash) ? 'verified' : 'mismatch';
    }
}
