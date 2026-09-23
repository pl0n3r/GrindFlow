<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Sanitized direct-upload capability summary safe for operator/UI diagnostics.
 *
 * Provider credentials, bucket names and endpoints are deliberately excluded.
 */
final class DirectUploadReadiness
{
    public function __construct(private readonly DirectUploadStorage $storage)
    {
    }

    /**
     * @return array{disk: string, driver: string, max_bytes: int, configured: bool}
     */
    public function publicSummary(): array
    {
        return [
            'disk' => $this->storage->disk(),
            'driver' => $this->storage->driver(),
            'max_bytes' => DirectUploadTokenCipher::MAX_BYTES,
            'configured' => $this->storage->available(),
        ];
    }
}
