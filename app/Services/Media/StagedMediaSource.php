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
        self::assertLength($sourceType, 64, 'sourceType');
        self::assertLength($sourceRef, 1024, 'sourceRef');
        self::assertLength($sourceDisk, 64, 'sourceDisk');
        self::assertLength($sourceKey, 1024, 'sourceKey');
        self::assertLength($originalFilename, 512, 'originalFilename');

        if ($mimeType !== null) {
            self::assertLength($mimeType, 191, 'mimeType');
        }

        if ($byteSize !== null && $byteSize < 1) {
            throw new InvalidArgumentException('byteSize must be positive when supplied.');
        }
    }

    public function idempotencyKey(): string
    {
        return self::idempotencyKeyFor(
            $this->sourceType,
            $this->sourceRef,
        );
    }

    public static function idempotencyKeyFor(
        string $sourceType,
        string $sourceRef,
    ): string {
        self::assertLength($sourceType, 64, 'sourceType');
        self::assertLength($sourceRef, 1024, 'sourceRef');

        return hash(
            'sha256',
            $sourceType."\0".$sourceRef,
        );
    }

    private static function assertLength(
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
