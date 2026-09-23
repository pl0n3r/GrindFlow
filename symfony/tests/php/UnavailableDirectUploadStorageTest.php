<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\UnavailableDirectUploadStorage;
use PHPUnit\Framework\TestCase;

final class UnavailableDirectUploadStorageTest extends TestCase
{
    public function testDefaultStorageFailsClosedWithoutBreakingReadiness(): void
    {
        $storage = new UnavailableDirectUploadStorage();

        self::assertFalse($storage->available());
        self::assertSame('media', $storage->disk());
        self::assertSame('unavailable', $storage->driver());
        self::assertFalse($storage->exists('organizations/example/staging/example'));
        self::assertNull($storage->size('organizations/example/staging/example'));
        self::assertNull($storage->readStream('organizations/example/staging/example'));

        foreach ([
            fn () => $storage->temporaryUpload('key', 'video/mp4', 1_800_000_900),
            fn () => $storage->delete('key'),
            fn () => $storage->promote('staging', 'final'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Unavailable direct upload storage accepted provider I/O.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Direct upload storage is not configured.', $exception->getMessage());
            }
        }
    }

    public function testDiskNameIsSanitizedBeforeExposure(): void
    {
        self::assertSame('media_private-1', (new UnavailableDirectUploadStorage('media_private-1'))->disk());

        $this->expectException(\InvalidArgumentException::class);
        new UnavailableDirectUploadStorage('../secret');
    }
}
