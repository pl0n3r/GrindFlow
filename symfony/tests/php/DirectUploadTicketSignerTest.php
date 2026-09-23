<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadTicketSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DirectUploadTicketSignerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testRoundTripIsTenantActorAndExpiryBound(): void
    {
        $signer = new DirectUploadTicketSigner(str_repeat('s', 32));
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $staging = Uuid::v7()->toRfc4122();

        $ticket = $signer->issue(
            $organization,
            $user,
            'organizations/'.$organization.'/staging/'.$staging,
            'large-clip.mp4',
            'video/mp4',
            50_000_000,
            900,
            self::NOW,
        );

        $payload = $signer->verify($ticket, $organization, $user, self::NOW + 899);
        self::assertNotNull($payload);
        self::assertSame('large-clip.mp4', $payload['filename']);
        self::assertSame('video/mp4', $payload['mime_type']);
        self::assertSame(50_000_000, $payload['byte_size']);
        self::assertSame(self::NOW + 900, $payload['expires_at']);

        self::assertNull($signer->verify($ticket, $organization, $user, self::NOW + 900));
        self::assertNull($signer->verify($ticket, Uuid::v7()->toRfc4122(), $user, self::NOW + 10));
        self::assertNull($signer->verify($ticket, $organization, Uuid::v7()->toRfc4122(), self::NOW + 10));
    }

    public function testTamperingMalformedTokensAndExtraPayloadKeysAreRejected(): void
    {
        $signer = new DirectUploadTicketSigner(str_repeat('k', 32));
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $ticket = $signer->issue(
            $organization,
            $user,
            'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122(),
            'clip.webm',
            'video/webm',
            20_000_000,
            600,
            self::NOW,
        );

        [$payload, $signature] = explode('.', $ticket, 2);
        $tamperedPayload = substr($payload, 0, -1).($payload[-1] === 'A' ? 'B' : 'A');
        self::assertNull($signer->verify($tamperedPayload.'.'.$signature, $organization, $user, self::NOW));
        self::assertNull($signer->verify($payload.'.'.substr($signature, 0, -1).'A', $organization, $user, self::NOW));
        self::assertNull($signer->verify('not-a-ticket', $organization, $user, self::NOW));
        self::assertNull($signer->verify($payload.'.%%%%', $organization, $user, self::NOW));

        $decoded = json_decode($this->decodePart($payload), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['unexpected'] = 'smuggled';
        $encoded = $this->encode(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signed = $encoded.'.'.$this->encode(hash_hmac('sha256', $encoded, str_repeat('k', 32), true));
        self::assertNull($signer->verify($signed, $organization, $user, self::NOW));
    }

    public function testIssueRejectsUnsafeOrUnsupportedMetadata(): void
    {
        $signer = new DirectUploadTicketSigner(str_repeat('z', 32));
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $staging = 'organizations/'.$organization.'/staging/'.Uuid::v7()->toRfc4122();

        foreach ([
            [$staging, '../clip.mp4', 'video/mp4', 1, 900],
            [$staging, "bad\nname.mp4", 'video/mp4', 1, 900],
            [$staging, 'clip.mov', 'video/quicktime', 1, 900],
            [$staging, 'clip.mp4', 'video/mp4', 0, 900],
            [$staging, 'clip.mp4', 'video/mp4', DirectUploadTicketSigner::MAX_BYTES + 1, 900],
            ['organizations/'.Uuid::v7()->toRfc4122().'/staging/'.Uuid::v7()->toRfc4122(), 'clip.mp4', 'video/mp4', 1, 900],
            [$staging, 'clip.mp4', 'video/mp4', 1, 299],
            [$staging, 'clip.mp4', 'video/mp4', 1, 3601],
        ] as [$storageKey, $filename, $mime, $bytes, $ttl]) {
            try {
                $signer->issue(
                    $organization,
                    $user,
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

    public function testSigningSecretMustBeHighEntropySized(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DirectUploadTicketSigner('too-short');
    }

    private function decodePart(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
