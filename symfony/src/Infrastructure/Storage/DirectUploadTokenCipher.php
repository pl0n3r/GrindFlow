<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Symfony\Component\Uid\Uuid;

/**
 * Encrypts short-lived completion tokens for direct-to-object-storage uploads.
 *
 * Tokens contain no provider credential and bind the staged object to one
 * organization, actor, disk and approved upload metadata.
 */
final class DirectUploadTokenCipher
{
    public const MAX_BYTES = 2_147_483_648;
    public const MIN_TTL_SECONDS = 300;
    public const MAX_TTL_SECONDS = 3600;

    private const VERSION = 1;
    private const PREFIX = 'v1';
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'grindflow:direct-upload:v1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/webm',
    ];

    private readonly ?string $key;

    public function __construct(string $secret = '')
    {
        $this->key = strlen($secret) >= 32
            && function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && function_exists('openssl_get_cipher_methods')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true)
            ? hash('sha256', $secret, true)
            : null;
    }

    public function configured(): bool
    {
        return $this->key !== null;
    }

    public function issue(
        string $organizationId,
        string $userId,
        string $disk,
        string $storageKey,
        string $filename,
        string $mimeType,
        int $byteSize,
        int $ttlSeconds = 900,
        ?int $now = null,
    ): string {
        if ($this->key === null) {
            throw new \RuntimeException('Direct upload token encryption is not configured.');
        }

        $issuedAt = $now ?? time();
        if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('Direct upload TTL is outside the allowed range.');
        }

        $payload = [
            'v' => self::VERSION,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'disk' => $disk,
            'storage_key' => $storageKey,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + $ttlSeconds,
        ];
        $this->assertPayload($payload);

        $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
            self::TAG_BYTES,
        );

        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Unable to encrypt direct upload completion token.');
        }

        return implode('.', [
            self::PREFIX,
            self::base64UrlEncode($nonce),
            self::base64UrlEncode($ciphertext),
            self::base64UrlEncode($tag),
        ]);
    }

    /**
     * @return array{
     *   v: int,
     *   organization_id: string,
     *   user_id: string,
     *   disk: string,
     *   storage_key: string,
     *   filename: string,
     *   mime_type: string,
     *   byte_size: int,
     *   issued_at: int,
     *   expires_at: int
     * }|null
     */
    public function decryptFor(
        string $token,
        string $organizationId,
        string $userId,
        string $disk,
        ?int $now = null,
    ): ?array {
        if ($this->key === null || strlen($token) > 4096) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $nonce = self::base64UrlDecode($parts[1]);
        $ciphertext = self::base64UrlDecode($parts[2]);
        $tag = self::base64UrlDecode($parts[3]);
        if ($nonce === null || strlen($nonce) !== self::NONCE_BYTES
            || $ciphertext === null || $ciphertext === ''
            || $tag === null || strlen($tag) !== self::TAG_BYTES) {
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
        );
        if (!is_string($plaintext)) {
            return null;
        }

        try {
            $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload) || array_keys($payload) !== [
            'v',
            'organization_id',
            'user_id',
            'disk',
            'storage_key',
            'filename',
            'mime_type',
            'byte_size',
            'issued_at',
            'expires_at',
        ]) {
            return null;
        }

        try {
            $this->assertPayload($payload);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $clock = $now ?? time();
        if (!hash_equals($organizationId, $payload['organization_id'])
            || !hash_equals($userId, $payload['user_id'])
            || !hash_equals($disk, $payload['disk'])
            || $payload['issued_at'] > $clock + 60
            || $payload['expires_at'] <= $clock
            || $payload['expires_at'] - $payload['issued_at'] < self::MIN_TTL_SECONDS
            || $payload['expires_at'] - $payload['issued_at'] > self::MAX_TTL_SECONDS) {
            return null;
        }

        /** @var array{
         *   v: int,
         *   organization_id: string,
         *   user_id: string,
         *   disk: string,
         *   storage_key: string,
         *   filename: string,
         *   mime_type: string,
         *   byte_size: int,
         *   issued_at: int,
         *   expires_at: int
         * } $payload
         */
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertPayload(array $payload): void
    {
        $organizationId = $payload['organization_id'] ?? null;
        $userId = $payload['user_id'] ?? null;
        $disk = $payload['disk'] ?? null;
        $storageKey = $payload['storage_key'] ?? null;
        $filename = $payload['filename'] ?? null;
        $mimeType = $payload['mime_type'] ?? null;
        $byteSize = $payload['byte_size'] ?? null;
        $issuedAt = $payload['issued_at'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        $storagePrefix = is_string($organizationId)
            ? 'organizations/'.$organizationId.'/staging/'
            : '';
        $stagingId = is_string($storageKey) && str_starts_with($storageKey, $storagePrefix)
            ? substr($storageKey, strlen($storagePrefix))
            : '';

        if (($payload['v'] ?? null) !== self::VERSION
            || !is_string($organizationId) || !Uuid::isValid($organizationId)
            || !is_string($userId) || !Uuid::isValid($userId)
            || !is_string($disk) || preg_match('/\\A[A-Za-z0-9._-]{1,64}\\z/D', $disk) !== 1
            || !is_string($storageKey) || $storagePrefix === ''
            || !Uuid::isValid($stagingId) || $storageKey !== $storagePrefix.$stagingId
            || !is_string($filename) || trim($filename) === '' || strlen($filename) > 180
            || preg_match('//u', $filename) !== 1
            || basename(str_replace('\\', '/', $filename)) !== $filename
            || preg_match('/[\\x00-\\x1F\\x7F]/u', $filename) === 1
            || !is_string($mimeType) || !in_array($mimeType, self::MIMES, true)
            || !is_int($byteSize) || $byteSize < 1 || $byteSize > self::MAX_BYTES
            || !is_int($issuedAt) || $issuedAt < 1
            || !is_int($expiresAt) || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('Direct upload completion token payload is invalid.');
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\\A[A-Za-z0-9_-]+\\z/D', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);

        return $decoded === false ? null : $decoded;
    }
}
