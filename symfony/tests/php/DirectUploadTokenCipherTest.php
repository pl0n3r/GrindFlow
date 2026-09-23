<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DirectUploadTokenCipherTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testRoundTripIsOpaqueAndBoundToTenantActorDiskAndExpiry(): void
    {
        $cipher = new DirectUploadTokenCipher(str_repeat('s', 32));
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $staging = Uuid::v7()->toRfc4122();

        $token = $cipher->issue(
            $organization,
            $user,
            'media',
            'organizations/'.$organization.'/staging/'.$staging,
            'large-clip.mp4',
            'video/mp4',
            50_000_000,
            900,
            self::NOW,
        );

        self::assertStringStartsWith('v1.', $token);
        self::assertStringNotContainsString($organization, $token);
        self::assertStringNotContainsString($user, $token);
        self::assertStringNotContainsString('large-clip.mp4', $token);

        $payload = $cipher->decryptFor($token, $organization, $user, 'media', self::NOW + 899);
        self::assertNotNull($payload);
        self::assertSame('media', $payload['disk']);
        self::assertSame('large-clip.mp4', $payload['filename']);
        self::assertSame('video/mp4', $payload['mime_type']);
        self::assertSame(50_000_000, $payload['byte_size']);
        self::assertSame(self::NOW + 900, $payload['expires_at']);

        self::assertNull($cipher->decryptFor($token, $organization, $user, 'media', self::NOW + 900));
        self::assertNull($cipher->decryptFor($token, Uuid::v7()->toRfc4122(), $user, 'media', self::NOW + 10));
        self::assertNull($cipher->decryptFor($token, $organization, Uuid::v7()->toRfc4122(), 'media', self::NOW + 10));
        self::assertNull($cipher->decryptFor($token, $organization, $user, 'other', self::NOW + 10));
    }

    public function testTamperingAndMalformedTokensAreRejected(): void
    {
        $cipher = new DirectUploadTokenCipher(str_repeat('k', 32));
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $token = $cipher->issue(
            $organization,
            $user,
            'media',
            'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122(),
            'clip.webm',
            'video/webm',
            20_000_000,
            600,
            self::NOW,
        );

        $parts = explode('.', $token);
        self::assertCount(4, $parts);
        $parts[3] = ($parts[3][0] === 'A' ? 'B' : 'A').substr($parts[3], 1);
        self::assertNull($cipher->decryptFor(implode('.', $parts), $organization, $user, 'media', self::NOW));
        self::assertNull($cipher->decryptFor('v2.'.implode('.', array_slice(explode('.', $token), 1)), $organization, $user, 'media', self::NOW));
        self::assertNull($cipher->decryptFor('not-a-token', $organization, $user, 'media', self::NOW));
        self::assertNull($cipher->decryptFor('v1.%%%%.%%%%.%%%%', $organization, $user, 'media', self::NOW));
    }

    public function testIssueRejectsUnsafeOrUnsupportedMetadata(): void
    {
        $cipher = new DirectUploadTokenCipher(str_repeat('z', 32));
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $staging = 'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122();

        foreach ([
            ['../media', $staging, 'clip.mp4', 'video/mp4', 1, 900],
            ['media', $staging, '../clip.mp4', 'video/mp4', 1, 900],
            ['media', $staging, '..\\clip.mp4', 'video/mp4', 1, 900],
            ['media', $staging, '   ', 'video/mp4', 1, 900],
            ['media', $staging, "bad\nname.mp4", 'video/mp4', 1, 900],
            ['media', $staging, "invalid-\xC3\x28.mp4", 'video/mp4', 1, 900],
            ['media', 'organizations/'.$organization.'/staging/not-a-uuid', 'clip.mp4', 'video/mp4', 1, 900],
            ['media', $staging, 'clip.mov', 'video/quicktime', 1, 900],
            ['media', $staging, 'clip.mp4', 'video/mp4', 0, 900],
            ['media', $staging, 'clip.mp4', 'video/mp4', DirectUploadTokenCipher::MAX_BYTES + 1, 900],
            ['media', 'organizations/'.Uuid::v7()->toRfc4122().'/staging/'.Uuid::v7()->toRfc4122(), 'clip.mp4', 'video/mp4', 1, 900],
            ['media', $staging, 'clip.mp4', 'video/mp4', 1, 299],
            ['media', $staging, 'clip.mp4', 'video/mp4', 1, 3601],
        ] as [$disk, $storageKey, $filename, $mime, $bytes, $ttl]) {
            try {
                $cipher->issue(
                    $organization,
                    $user,
                    $disk,
                    $storageKey,
                    $filename,
                    $mime,
                    $bytes,
                    $ttl,
                    self::NOW,
                );
                self::fail('Unsafe direct-upload metadata was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMissingOrShortEncryptionSecretFailsClosedWithoutBreakingContainerUse(): void
    {
        foreach (['', 'too-short'] as $secret) {
            $cipher = new DirectUploadTokenCipher($secret);
            self::assertFalse($cipher->configured());
            self::assertNull($cipher->decryptFor('not-a-token', Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), 'media', self::NOW));

            try {
                $cipher->issue(
                    Uuid::v7()->toRfc4122(),
                    Uuid::v7()->toRfc4122(),
                    'media',
                    'organizations/'.Uuid::v7()->toRfc4122().'/staging/'.Uuid::v7()->toRfc4122(),
                    'clip.mp4',
                    'video/mp4',
                    1,
                    900,
                    self::NOW,
                );
                self::fail('Unconfigured token encryption issued a completion token.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Direct upload token encryption is not configured.', $exception->getMessage());
            }
        }
    }
}
