<?php

namespace App\Services\Media;

use InvalidArgumentException;

final readonly class StagedMediaSource
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceType,
        public string $sourceRef,
        public string $sourceDisk,
        public string $sourceKey,
        public string $originalFilename,
        public ?string $mimeType = null,
        public ?int $byteSize = null,
        public bool $deleteAfterIngest = false,
        public array $metadata = [],
    ) {
        $this->assertLength($sourceType, 64, 'sourceType');
        $this->assertLength($sourceRef, 1024, 'sourceRef');
        $this->assertLength($sourceDisk, 64, 'sourceDisk');
        $this->assertLength($sourceKey, 1024, 'sourceKey');
        $this->assertLength($originalFilename, 512, 'originalFilename');

        if ($mimeType !== null) {
            $this->assertLength($mimeType, 191, 'mimeType');
        }

        if ($byteSize !== null && $byteSize < 1) {
            throw new InvalidArgumentException('byteSize must be positive when supplied.');
        }
    }

    public function idempotencyKey(): string
    {
        return hash(
            'sha256',
            $this->sourceType."\0".$this->sourceRef,
        );
    }

    private function assertLength(
        string $value,
        int $maxLength,
        string $field,
    ): void {
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "{$field} must contain between 1 and {$maxLength} characters.",
            );
        }
    }
}
