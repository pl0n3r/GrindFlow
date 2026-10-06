<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Converts one already integrity-verified private JPEG/PNG into an ephemeral
 * decoder-backed copy. The original stays untouched and private.
 */
final class VaultPhotoSafetyMaterializer
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_DIMENSION = 8192;
    public const MAX_PIXELS = 32_000_000;
    private const DECODE_BYTES_PER_PIXEL = 8;
    private const MEMORY_SAFETY_MARGIN = 16 * 1024 * 1024;

    /**
     * @throws \RuntimeException when the input cannot be safely decoded and re-encoded
     */
    public function materialize(
        string $sourcePath,
        string $mimeType,
        int $expectedSize,
        string $expectedSha256,
    ): string {
        if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException('Unsupported pilot image type.');
        }
        if ($expectedSize < 1 || $expectedSize > self::MAX_BYTES
            || preg_match('/\A[a-fA-F0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new \RuntimeException('Invalid verified image metadata.');
        }
        if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('Private image is unavailable.');
        }

        clearstatcache(true, $sourcePath);
        $actualSize = @filesize($sourcePath);
        if ($actualSize !== $expectedSize) {
            throw new \RuntimeException('Private image integrity changed.');
        }
        if (!$this->decoderAvailable()) {
            throw new \RuntimeException('Image decoder is unavailable.');
        }

        $bytes = @file_get_contents($sourcePath);
        if (!is_string($bytes) || strlen($bytes) !== $expectedSize) {
            throw new \RuntimeException('Private image could not be read.');
        }
        if (!hash_equals(strtolower($expectedSha256), hash('sha256', $bytes))) {
            throw new \RuntimeException('Private image integrity changed.');
        }
        $this->assertNoTrailingPayload($bytes, $mimeType);

        $dimensions = @getimagesizefromstring($bytes);
        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1], $dimensions['mime'])
            || $dimensions['mime'] !== $mimeType) {
            throw new \RuntimeException('Image header does not match the declared type.');
        }
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        if ($width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException('Image dimensions exceed the pilot safety limit.');
        }

        $this->assertMemoryAvailable($width, $height, strlen($bytes));

        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Image decoder rejected the private original.');
        }

        $temporary = null;
        try {
            if (imagesx($image) !== $width || imagesy($image) !== $height) {
                throw new \RuntimeException('Decoded image dimensions are inconsistent.');
            }

            $tempDir = sys_get_temp_dir();
            if (!is_dir($tempDir) || !is_writable($tempDir)) {
                throw new \RuntimeException('Temporary storage is unavailable.');
            }
            $temporary = tempnam($tempDir, 'gf-photo-');
            if ($temporary === false || !@chmod($temporary, 0600)) {
                throw new \RuntimeException('Safe temporary image could not be created.');
            }

            $written = match ($mimeType) {
                'image/jpeg' => @imagejpeg($image, $temporary, 90),
                'image/png' => $this->writePng($image, $temporary),
            };
            if ($written !== true) {
                throw new \RuntimeException('Safe image re-encode failed.');
            }

            clearstatcache(true, $temporary);
            $safeSize = @filesize($temporary);
            if (!is_int($safeSize) || $safeSize < 1 || $safeSize > self::MAX_BYTES) {
                throw new \RuntimeException('Safe image exceeds the pilot size limit.');
            }
            $safeDimensions = @getimagesize($temporary);
            if (!is_array($safeDimensions) || ($safeDimensions['mime'] ?? null) !== $mimeType
                || (int) ($safeDimensions[0] ?? 0) !== $width
                || (int) ($safeDimensions[1] ?? 0) !== $height) {
                throw new \RuntimeException('Safe image validation failed after re-encode.');
            }
            $safeBytes = @file_get_contents($temporary);
            if (!is_string($safeBytes) || strlen($safeBytes) !== $safeSize) {
                throw new \RuntimeException('Safe image could not be verified.');
            }
            $this->assertNoTrailingPayload($safeBytes, $mimeType);

            $result = $temporary;
            $temporary = null;

            return $result;
        } finally {
            if (is_string($temporary)) {
                $this->cleanup($temporary);
            }
        }
    }

    public function cleanup(?string $path): void
    {
        if (!is_string($path) || $path === '' || !str_starts_with(basename($path), 'gf-photo-')) {
            return;
        }
        $tempRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        if ($tempRoot === false || $parent === false || $parent !== $tempRoot) {
            return;
        }
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    public function decoderAvailable(): bool
    {
        return extension_loaded('gd')
            && function_exists('getimagesizefromstring')
            && function_exists('imagecreatefromstring')
            && function_exists('imagejpeg')
            && function_exists('imagepng');
    }

    /** @return array{decoder: bool, temporary_storage: bool, private_vault: bool} */
    public function runtimeReadiness(string $vaultRoot): array
    {
        return [
            'decoder' => $this->decoderAvailable(),
            'temporary_storage' => $this->temporaryStorageReady(),
            'private_vault' => $this->privateVaultReadinessState($vaultRoot) === 'ready',
        ];
    }

    public function privateVaultReadinessState(string $vaultRoot): string
    {
        if ($vaultRoot === '' || is_link($vaultRoot)) {
            return 'root_unavailable';
        }
        if (!is_dir($vaultRoot)) {
            return 'missing';
        }
        if (!is_readable($vaultRoot) || !is_executable($vaultRoot)) {
            return 'unreadable';
        }
        $mode = @fileperms($vaultRoot);
        if (!is_int($mode)) {
            return 'permissions_unavailable';
        }
        if (($mode & 0077) !== 0) {
            return 'permissions_not_private';
        }

        return 'ready';
    }

    private function temporaryStorageReady(): bool
    {
        $root = sys_get_temp_dir();
        if (!is_dir($root) || is_link($root) || !is_writable($root)) {
            return false;
        }

        $probe = tempnam($root, 'gf-ready-');
        if (!is_string($probe)) {
            return false;
        }

        try {
            if (!chmod($probe, 0600)) {
                return false;
            }
            $written = file_put_contents($probe, 'ok', LOCK_EX);
            clearstatcache(true, $probe);

            return $written === 2
                && filesize($probe) === 2
                && is_readable($probe)
                && is_writable($probe);
        } finally {
            if (is_file($probe) && !is_link($probe)) {
                unlink($probe);
            }
        }
    }

    private function assertMemoryAvailable(int $width, int $height, int $inputBytes): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }

        $pixels = $width * $height;
        $required = ($pixels * self::DECODE_BYTES_PER_PIXEL)
            + $inputBytes
            + self::MEMORY_SAFETY_MARGIN;
        $available = max(0, $limit - memory_get_usage(true));
        if ($required > $available) {
            throw new \RuntimeException('Image exceeds the available decoder memory budget.');
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }
        if (preg_match('/\A(\d+)([KMG]?)\z/i', $raw, $match) !== 1) {
            return 0;
        }

        $multiplier = match (strtoupper($match[2])) {
            'G' => 1024 * 1024 * 1024,
            'M' => 1024 * 1024,
            'K' => 1024,
            default => 1,
        };
        $value = (int) $match[1];
        if ($value > intdiv(PHP_INT_MAX, $multiplier)) {
            return PHP_INT_MAX;
        }

        return $value * $multiplier;
    }

    private function writePng(\GdImage $image, string $path): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return @imagepng($image, $path, 6);
    }

    private function assertNoTrailingPayload(string $bytes, string $mimeType): void
    {
        if ($mimeType === 'image/jpeg') {
            if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8"
                || substr($bytes, -2) !== "\xFF\xD9") {
                throw new \RuntimeException('JPEG framing is invalid or contains trailing payload.');
            }

            return;
        }

        $signature = "\x89PNG\r\n\x1A\n";
        $iend = "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
        if (!str_starts_with($bytes, $signature) || !str_ends_with($bytes, $iend)) {
            throw new \RuntimeException('PNG framing is invalid or contains trailing payload.');
        }
    }
}
