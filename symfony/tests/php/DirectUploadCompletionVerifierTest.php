<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadCompletionVerifier;
use GrindFlow\Infrastructure\Storage\DirectUploadObjectKeys;
use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DirectUploadCompletionVerifierTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testVerifierBindsContextChecksSizeAndHashesStream(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $stagingKey = 'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122();
        $bytes = 'verified direct upload bytes';
        $storage = $this->storage($stagingKey, $bytes);
        $tokens = new DirectUploadTokenCipher(str_repeat('s', 32));
        $token = $tokens->issue(
            $organization,
            $user,
            'media',
            $stagingKey,
            'clip.mp4',
            'video/mp4',
            strlen($bytes),
            900,
            self::NOW,
        );

        $verified = (new DirectUploadCompletionVerifier(
            $storage,
            $tokens,
            new DirectUploadObjectKeys(),
        ))->verify($token, $organization, $user, self::NOW + 1);

        $sha = hash('sha256', $bytes);
        self::assertSame($organization, $verified['organization_id']);
        self::assertSame($user, $verified['user_id']);
        self::assertSame('media', $verified['disk']);
        self::assertSame($stagingKey, $verified['staging_key']);
        self::assertSame('organizations/'.$organization.'/blobs/'.substr($sha, 0, 2).'/'.$sha, $verified['final_key']);
        self::assertSame('clip.mp4', $verified['filename']);
        self::assertSame('video/mp4', $verified['mime_type']);
        self::assertSame(strlen($bytes), $verified['byte_size']);
        self::assertSame($sha, $verified['sha256']);
        self::assertSame(1, $storage->existsCalls);
        self::assertSame(1, $storage->readCalls);
    }

    public function testForeignTenantTokenIsRejectedBeforeStorageProbe(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $stagingKey = 'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122();
        $storage = $this->storage($stagingKey, 'bytes');
        $tokens = new DirectUploadTokenCipher(str_repeat('s', 32));
        $token = $tokens->issue(
            $organization,
            $user,
            'media',
            $stagingKey,
            'clip.webm',
            'video/webm',
            5,
            900,
            self::NOW,
        );
        $verifier = new DirectUploadCompletionVerifier($storage, $tokens, new DirectUploadObjectKeys());

        try {
            $verifier->verify($token, Uuid::v7()->toRfc4122(), $user, self::NOW + 1);
            self::fail('Foreign tenant completion token was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Direct upload token is invalid or expired.', $exception->getMessage());
        }
        self::assertSame(0, $storage->existsCalls);
        self::assertSame(0, $storage->readCalls);
    }

    public function testSizeMismatchStopsBeforeStreaming(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $stagingKey = 'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122();
        $storage = $this->storage($stagingKey, 'actual');
        $tokens = new DirectUploadTokenCipher(str_repeat('s', 32));
        $token = $tokens->issue(
            $organization,
            $user,
            'media',
            $stagingKey,
            'clip.mp4',
            'video/mp4',
            99,
            900,
            self::NOW,
        );
        $verifier = new DirectUploadCompletionVerifier($storage, $tokens, new DirectUploadObjectKeys());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Direct upload object size does not match the approved upload.');
        try {
            $verifier->verify($token, $organization, $user, self::NOW + 1);
        } finally {
            self::assertSame(1, $storage->existsCalls);
            self::assertSame(0, $storage->readCalls);
        }
    }

    private function storage(string $key, string $bytes): DirectUploadStorage
    {
        return new class($key, $bytes) implements DirectUploadStorage {
            public int $existsCalls = 0;
            public int $readCalls = 0;

            public function __construct(
                private readonly string $expectedKey,
                private readonly string $bytes,
            ) {
            }

            public function available(): bool { return true; }
            public function disk(): string { return 'media'; }
            public function driver(): string { return 's3'; }
            public function temporaryUpload(string $storageKey, string $mimeType, int $expiresAt): array
            {
                return ['url' => 'https://example.invalid', 'headers' => []];
            }
            public function exists(string $storageKey): bool
            {
                $this->existsCalls++;

                return $storageKey === $this->expectedKey;
            }
            public function size(string $storageKey): ?int
            {
                return $storageKey === $this->expectedKey ? strlen($this->bytes) : null;
            }
            public function readStream(string $storageKey)
            {
                $this->readCalls++;
                if ($storageKey !== $this->expectedKey) {
                    return null;
                }

                $stream = fopen('php://temp', 'w+b');
                if ($stream === false) {
                    return null;
                }
                fwrite($stream, $this->bytes);
                rewind($stream);

                return $stream;
            }
            public function delete(string $storageKey): void {}
            public function promote(string $stagingKey, string $finalKey): void {}
        };
    }
}
