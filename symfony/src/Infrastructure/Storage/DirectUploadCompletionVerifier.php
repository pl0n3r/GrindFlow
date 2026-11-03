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
            // Read only a bounded signature prefix; never load multi-GiB media into memory.
            $head = '';
            $limit = min(4096, $payload['byte_size'] + 1);
            while (strlen($head) < $limit) {
                $chunk = fread($stream, $limit - strlen($head));
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new \RuntimeException('Direct upload object could not be read for signature verification.');
                }
                if ($chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
            if (strlen($head) > $payload['byte_size']) {
                throw new \InvalidArgumentException('Direct upload object stream exceeds approved size.');
            }
            if (!$this->matchesDeclaredMediaSignature($head, $payload['mime_type'])) {
                throw new \InvalidArgumentException('Direct upload object signature does not match declared MIME.');
            }
            // Hash the full stream after validating the signature and enforce the exact byte count.
            $hash = hash_init('sha256');
            hash_update($hash, $head);
            $tailBytes = hash_update_stream($hash, $stream, $payload['byte_size'] - strlen($head) + 1);
            if ($tailBytes === false) {
                throw new \RuntimeException('Direct upload object could not be fully hashed.');
            }
            if (strlen($head) + $tailBytes !== $payload['byte_size']) {
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
    /**
     * Strict bounded media magic bytes, without trusting the user-supplied MIME
     * or invoking external media decoders on untrusted staging content.
     */
    private function matchesDeclaredMediaSignature(string $head, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => strlen($head) >= 3 && str_starts_with($head, "\xFF\xD8\xFF"),
            'image/png' => strlen($head) >= 24
                && str_starts_with($head, "\x89PNG\r\n\x1A\n")
                && substr($head, 12, 4) === 'IHDR',
            'image/webp' => strlen($head) >= 12
                && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'video/webm' => strlen($head) >= 4 && str_starts_with($head, "\x1A\x45\xDF\xA3"),
            'video/mp4' => $this->hasKnownMp4FileTypeBox($head),
            default => false,
        };
    }

    private function hasKnownMp4FileTypeBox(string $head): bool
    {
        $offset = 0;
        $length = strlen($head);
        while ($offset + 16 <= $length) {
            $box = unpack('Nsize', substr($head, $offset, 4));
            $boxSize = is_array($box) ? (int) ($box['size'] ?? 0) : 0;
            if ($boxSize < 8 || $offset + $boxSize > $length) {
                return false;
            }
            if (substr($head, $offset + 4, 4) === 'ftyp') {
                if ($boxSize < 16) {
                    return false;
                }
                $brand = substr($head, $offset + 8, 4);
                // Recognized ISO Base Media / MP4 and common progressive media brands.
                return in_array($brand, [
                    'isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6',
                    'mp41', 'mp42', 'avc1', 'M4V ', 'MSNV',
                    '3gp4', '3gp5', '3gp6', 'dash', 'cmfc', 'cmfs',
                ], true);
            }
            $offset += $boxSize;
        }

        return false;
    }

}
