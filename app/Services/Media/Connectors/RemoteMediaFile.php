<?php

namespace App\Services\Media\Connectors;

use InvalidArgumentException;

final readonly class RemoteMediaFile
{
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
        public int $sizeBytes,
        public string $modifiedAt,
        public ?string $checksum = null,
    ) {
        if ($id === '' || mb_strlen($id) > 512) {
            throw new InvalidArgumentException('Remote media id is invalid.');
        }

        if ($name === '' || mb_strlen($name) > 512) {
            throw new InvalidArgumentException('Remote media name is invalid.');
        }

        if ($path === '' || mb_strlen($path) > 2048) {
            throw new InvalidArgumentException('Remote media path is invalid.');
        }

        if ($sizeBytes < 1) {
            throw new InvalidArgumentException('Remote media size must be positive.');
        }

        if ($modifiedAt === '' || mb_strlen($modifiedAt) > 191) {
            throw new InvalidArgumentException('Remote media modified timestamp is invalid.');
        }

        if ($checksum !== null && mb_strlen($checksum) > 512) {
            throw new InvalidArgumentException('Remote media checksum is too long.');
        }
    }

    public function versionFingerprint(): string
    {
        return $this->checksum !== null && $this->checksum !== ''
            ? $this->checksum
            : $this->modifiedAt;
    }
}
