<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadCompletionVerifier;
use GrindFlow\Infrastructure\Storage\DirectUploadIntentIssuer;
use GrindFlow\Infrastructure\Storage\DirectUploadObjectKeys;
use GrindFlow\Infrastructure\Storage\DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\DirectUploadTokenCipher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Disposable local S3-like object storage: no HTTP, SDK or Backblaze I/O. */
final class SimulatedB2ObjectStore implements DirectUploadStorage
{
    private const ORIGIN = 'https://app.example.test';
    private const SIGNING_KEY = 'synthetic-test-key-not-for-deployment';

    /** @var array<string, array{mime:string, size:int, expires:int, signature:string}> */
    private array $intents = [];

    /** @var array<string, string> */
    private array $objects = [];

    public function available(): bool { return true; }
    public function disk(): string { return 'media'; }
    public function driver(): string { return 's3'; }

    public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array
    {
        $signature = hash_hmac('sha256', "$storageKey\n$mimeType\n$byteSize\n$expiresAt", self::SIGNING_KEY);
        $this->intents[$storageKey] = [
            'mime' => $mimeType, 'size' => $byteSize,
            'expires' => $expiresAt, 'signature' => $signature,
        ];

        return [
            'url' => 'https://b2.example.invalid/'.rawurlencode($storageKey).'?signature='.$signature,
            'headers' => ['Content-Type' => $mimeType],
        ];
    }

    /** Models exact-origin browser preflight. Not an actual provider CORS check. */
    public function corsAllows(string $origin, string $method, array $requestHeaders): bool
    {
        $names = array_map(static fn (string $name): string => strtolower($name), $requestHeaders);

        return $origin === self::ORIGIN && $method === 'PUT'
            && count(array_diff($names, ['content-type'])) === 0;
    }

    /** Local synthetic PUT; intentionally not part of the production storage interface. */
    public function put(string $url, string $origin, string $mimeType, string $bytes, int $now): void
    {
        if (!$this->corsAllows($origin, 'PUT', ['content-type'])) {
            throw new InvalidArgumentException('CORS origin not authorized.');
        }

        $urlParts = parse_url($url);
        if (!is_array($urlParts) || ($urlParts['scheme'] ?? '') !== 'https'
            || ($urlParts['host'] ?? '') !== 'b2.example.invalid') {
            throw new InvalidArgumentException('Invalid signed target.');
        }

        $key = rawurldecode(ltrim($urlParts['path'] ?? '', '/'));
        parse_str($urlParts['query'] ?? '', $query);
        $intent = $this->intents[$key] ?? null;
        if ($intent === null || !is_string($query['signature'] ?? null)
            || !hash_equals($intent['signature'], $query['signature'])
            || $mimeType !== $intent['mime']
            || strlen($bytes) !== $intent['size']
            || $now >= $intent['expires']) {
            throw new InvalidArgumentException('Unsigned, mismatched or expired upload.');
        }

        $this->objects[$key] = $bytes;
    }

    public function exists(string $storageKey): bool
    {
        return array_key_exists($storageKey, $this->objects);
    }

    public function size(string $storageKey): ?int
    {
        return isset($this->objects[$storageKey]) ? strlen($this->objects[$storageKey]) : null;
    }

    public function readStream(string $storageKey)
    {
        if (!isset($this->objects[$storageKey])) {
            return null;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }
        fwrite($stream, $this->objects[$storageKey]);
        rewind($stream);

        return $stream;
    }

    public function delete(string $storageKey): void
    {
        unset($this->objects[$storageKey]);
    }

    public function promote(string $stagingKey, string $finalKey): void
    {
        if (!isset($this->objects[$stagingKey])) {
            throw new InvalidArgumentException('Missing staging object.');
        }
        $this->objects[$finalKey] = $this->objects[$stagingKey];
        unset($this->objects[$stagingKey]);
    }
}

/** Class/method name is deliberately the exact issue #796 acceptance target. */
final class S3compatiblemediatargettest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function test_criterio_principal(): void
    {
        $organization = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $bytes = 'synthetic media bytes - never a real customer file';
        $storage = new SimulatedB2ObjectStore();
        $cipher = new DirectUploadTokenCipher(str_repeat('q', 32));
        $keys = new DirectUploadObjectKeys();
        $intent = (new DirectUploadIntentIssuer($storage, $cipher, $keys))->issue(
            $organization, $actor, 'private.mp4', 'video/mp4', strlen($bytes), self::NOW,
        );

        self::assertSame(['Content-Type' => 'video/mp4'], $intent['headers']);
        self::assertSame('2027-01-15T08:15:00Z', $intent['expires_at']);
        self::assertStringStartsWith('https://b2.example.invalid/', $intent['url']);
        self::assertStringNotContainsString('private.mp4', $intent['url']);
        self::assertTrue($storage->corsAllows('https://app.example.test', 'PUT', ['Content-Type']));
        $storage->put($intent['url'], 'https://app.example.test', 'video/mp4', $bytes, self::NOW + 1);

        $verified = (new DirectUploadCompletionVerifier($storage, $cipher, $keys))->verify(
            $intent['upload_token'], $organization, $actor, self::NOW + 2,
        );
        self::assertSame($organization, $verified['organization_id']);
        self::assertSame($actor, $verified['user_id']);
        self::assertSame('media', $verified['disk']);
        self::assertSame('s3', $storage->driver());
        self::assertSame(strlen($bytes), $verified['byte_size']);
        self::assertSame(hash('sha256', $bytes), $verified['sha256']);
        self::assertFalse($storage->exists($verified['final_key']));
    }

    public function testCorsRequiresExactHttpsOriginPutAndSignedHeaders(): void
    {
        $storage = new SimulatedB2ObjectStore();
        foreach ([
            ['https://app.example.test.evil.invalid', 'PUT', ['content-type']],
            ['https://other.example.test', 'PUT', ['content-type']],
            ['http://app.example.test', 'PUT', ['content-type']],
            ['*', 'PUT', ['content-type']],
            ['https://app.example.test', 'POST', ['content-type']],
            ['https://app.example.test', 'PUT', ['authorization']],
        ] as [$origin, $method, $headers]) {
            self::assertFalse($storage->corsAllows($origin, $method, $headers));
        }
    }

    public function testSignedPutRejectsTamperingExpiryMimeSizeAndCrossOrigin(): void
    {
        $storage = new SimulatedB2ObjectStore();
        $key = 'organizations/synthetic/staging/test';
        $intent = $storage->temporaryUpload($key, 'image/png', 3, self::NOW + 30);
        foreach ([
            [$intent['url'], 'https://evil.invalid', 'image/png', 'abc', self::NOW],
            [$intent['url'].'tampered', 'https://app.example.test', 'image/png', 'abc', self::NOW],
            [$intent['url'], 'https://app.example.test', 'text/plain', 'abc', self::NOW],
            [$intent['url'], 'https://app.example.test', 'image/png', 'toolong', self::NOW],
            [$intent['url'], 'https://app.example.test', 'image/png', 'abc', self::NOW + 30],
        ] as [$url, $origin, $mimeType, $bytes, $time]) {
            try {
                $storage->put($url, $origin, $mimeType, $bytes, $time);
                self::fail('Simulated S3 store accepted unauthorized PUT.');
            } catch (InvalidArgumentException) {
                self::assertFalse($storage->exists($key));
            }
        }
    }

    public function testLaunchGuideKeepsProviderReadinessUnverified(): void
    {
        $guide = file_get_contents(__DIR__.'/../../../docs/launch/media-b2.md');
        self::assertIsString($guide);
        foreach ([
            'MEDIA_STORAGE_ENDPOINT_FILE', 'MEDIA_STORAGE_BUCKET_FILE',
            'MEDIA_STORAGE_ACCESS_KEY_ID_FILE', 'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE',
            'CORS', 'PUT', '10 GB', 'NO ENSAYADO', 'UNKNOWN', '#783',
        ] as $required) {
            self::assertStringContainsString($required, $guide);
        }
        self::assertStringNotContainsString('"AllowedOrigins": ["*"]', $guide);
    }
}
