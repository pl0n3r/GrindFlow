<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadIntentIssuer;
use GrindFlow\Infrastructure\Storage\DirectUploadObjectKeys;
use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DirectUploadIntentIssuerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testIntentIsOpaqueTenantBoundAndUsesTemporaryStorageContract(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $storage = new class implements DirectUploadStorage {
            public ?string $key = null;
            public ?string $mime = null;
            public ?int $byteSize = null;
            public ?int $expiresAt = null;

            public function available(): bool { return true; }
            public function disk(): string { return 'media'; }
            public function driver(): string { return 's3'; }
            public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array
            {
                $this->key = $storageKey;
                $this->mime = $mimeType;
                $this->byteSize = $byteSize;
                $this->expiresAt = $expiresAt;

                return [
                    'url' => 'https://objects.example.invalid/presigned',
                    'headers' => ['Content-Type' => $mimeType],
                ];
            }
            public function exists(string $storageKey): bool { return false; }
            public function size(string $storageKey): ?int { return null; }
            public function readStream(string $storageKey) { return null; }
            public function delete(string $storageKey): void {}
            public function promote(string $stagingKey, string $finalKey): void {}
        };
        $tokens = new DirectUploadTokenCipher(str_repeat('k', 32));
        $issuer = new DirectUploadIntentIssuer($storage, $tokens, new DirectUploadObjectKeys());

        $intent = $issuer->issue(
            $organization,
            $user,
            'clip privado.mp4',
            'video/mp4',
            50_000_000,
            self::NOW,
        );

        self::assertSame('https://objects.example.invalid/presigned', $intent['url']);
        self::assertSame(['Content-Type' => 'video/mp4'], $intent['headers']);
        self::assertSame('2027-01-15T08:15:00Z', $intent['expires_at']);
        self::assertSame('video/mp4', $storage->mime);
        self::assertSame(50_000_000, $storage->byteSize);
        self::assertSame(self::NOW + DirectUploadIntentIssuer::DEFAULT_TTL_SECONDS, $storage->expiresAt);
        self::assertNotNull($storage->key);
        self::assertMatchesRegularExpression(
            '#\Aorganizations/'.preg_quote($organization, '#').'/staging/[0-9a-f-]{36}\z#D',
            $storage->key,
        );
        self::assertStringNotContainsString('clip privado.mp4', $storage->key);

        $payload = $tokens->decryptFor($intent['upload_token'], $organization, $user, 'media', self::NOW + 1);
        self::assertNotNull($payload);
        self::assertSame($storage->key, $payload['storage_key']);
        self::assertSame('clip privado.mp4', $payload['filename']);
        self::assertSame('video/mp4', $payload['mime_type']);
        self::assertSame(50_000_000, $payload['byte_size']);
    }

    public function testIntentFailsClosedWhenStorageOrTokenEncryptionIsUnavailable(): void
    {
        $storage = new class implements DirectUploadStorage {
            public function available(): bool { return false; }
            public function disk(): string { return 'media'; }
            public function driver(): string { return 'unavailable'; }
            public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array
            {
                throw new \LogicException('Unavailable storage must not receive presign calls.');
            }
            public function exists(string $storageKey): bool { return false; }
            public function size(string $storageKey): ?int { return null; }
            public function readStream(string $storageKey) { return null; }
            public function delete(string $storageKey): void {}
            public function promote(string $stagingKey, string $finalKey): void {}
        };
        $issuer = new DirectUploadIntentIssuer(
            $storage,
            new DirectUploadTokenCipher(),
            new DirectUploadObjectKeys(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Direct upload is not configured.');
        $issuer->issue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'clip.mp4',
            'video/mp4',
            1,
            self::NOW,
        );
    }

    public function testMalformedTemporaryUploadContractIsRejected(): void
    {
        $storage = new class implements DirectUploadStorage {
            public function available(): bool { return true; }
            public function disk(): string { return 'media'; }
            public function driver(): string { return 's3'; }
            public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array
            {
                return ['url' => '', 'headers' => []];
            }
            public function exists(string $storageKey): bool { return false; }
            public function size(string $storageKey): ?int { return null; }
            public function readStream(string $storageKey) { return null; }
            public function delete(string $storageKey): void {}
            public function promote(string $stagingKey, string $finalKey): void {}
        };
        $issuer = new DirectUploadIntentIssuer(
            $storage,
            new DirectUploadTokenCipher(str_repeat('s', 32)),
            new DirectUploadObjectKeys(),
        );

        $this->expectException(\RuntimeException::class);
        $issuer->issue(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            'clip.webm',
            'video/webm',
            1,
            self::NOW,
        );
    }
}
