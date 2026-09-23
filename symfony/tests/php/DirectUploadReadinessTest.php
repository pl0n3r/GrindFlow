<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadReadiness;
use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use GrindFlow\Infrastructure\Storage\UnavailableDirectUploadStorage;
use PHPUnit\Framework\TestCase;

final class DirectUploadReadinessTest extends TestCase
{
    public function testUnavailableSummaryIsExplicitAndContainsNoProviderSecretMaterial(): void
    {
        $summary = (new DirectUploadReadiness(
            new UnavailableDirectUploadStorage('media'),
            new DirectUploadTokenCipher(),
        ))->publicSummary();

        self::assertSame([
            'disk' => 'media',
            'driver' => 'unavailable',
            'max_bytes' => DirectUploadTokenCipher::MAX_BYTES,
            'configured' => false,
        ], $summary);
        self::assertSame(['disk', 'driver', 'max_bytes', 'configured'], array_keys($summary));
    }

    public function testConfiguredStorageCanExposeOnlyTheSanitizedCapabilityShape(): void
    {
        $storage = new class implements DirectUploadStorage {
            public function available(): bool { return true; }
            public function disk(): string { return 'media'; }
            public function driver(): string { return 's3'; }
            public function temporaryUpload(string $storageKey, string $mimeType, int $expiresAt): array
            {
                return ['url' => 'https://example.invalid/signed', 'headers' => []];
            }
            public function exists(string $storageKey): bool { return true; }
            public function size(string $storageKey): ?int { return 1; }
            public function readStream(string $storageKey) { return null; }
            public function delete(string $storageKey): void {}
            public function promote(string $stagingKey, string $finalKey): void {}
        };

        self::assertSame([
            'disk' => 'media',
            'driver' => 's3',
            'max_bytes' => DirectUploadTokenCipher::MAX_BYTES,
            'configured' => true,
        ], (new DirectUploadReadiness($storage, new DirectUploadTokenCipher(str_repeat('s', 32))))->publicSummary());

        self::assertFalse((new DirectUploadReadiness($storage, new DirectUploadTokenCipher()))->publicSummary()['configured']);
    }
}
