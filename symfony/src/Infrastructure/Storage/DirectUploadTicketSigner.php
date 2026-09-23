<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Symfony\Component\Uid\Uuid;

/**
 * Issues short-lived HMAC tickets for direct-to-object-storage uploads.
 *
 * Ticket payloads are intentionally readable by the browser: never place
 * credentials, signed provider URLs or secret material inside them.
 */
final class DirectUploadTicketSigner
{
    public const MAX_BYTES = 2_147_483_648;
    public const MIN_TTL_SECONDS = 300;
    public const MAX_TTL_SECONDS = 3600;

    private const VERSION = 1;
    private const MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/webm',
    ];

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Direct upload signing secret must contain at least 32 bytes.');
        }
    }

    public function issue(
        string $organizationId,
        string $userId,
        string $storageKey,
        string $filename,
        string $mimeType,
        int $byteSize,
        int $ttlSeconds = 900,
        ?int $now = null,
    ): string {
        $issuedAt = $now ?? time();
        if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('Direct upload TTL is outside the allowed range.');
        }

        $payload = [
            'v' => self::VERSION,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'storage_key' => $storageKey,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'byte_size' => $byteSize,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + $ttlSeconds,
        ];
        $this->assertPayload($payload);

        $encoded = self::base64UrlEncode(json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $signature = hash_hmac('sha256', $encoded, $this->secret, true);

        return $encoded.'.'.self::base64UrlEncode($signature);
    }

    /**
     * @return array{
     *   v: int,
     *   organization_id: string,
     *   user_id: string,
     *   storage_key: string,
     *   filename: string,
     *   mime_type: string,
     *   byte_size: int,
     *   issued_at: int,
     *   expires_at: int
     * }|null
     */
    public function verify(
        string $ticket,
        string $organizationId,
        string $userId,
        ?int $now = null,
    ): ?array {
        if (strlen($ticket) > 4096 || substr_count($ticket, '.') !== 1) {
            return null;
        }

        [$encoded, $encodedSignature] = explode('.', $ticket, 2);
        $payloadJson = self::base64UrlDecode($encoded);
        $signature = self::base64UrlDecode($encodedSignature);
        if ($payloadJson === null || $signature === null || strlen($signature) !== 32) {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, $this->secret, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload) || array_keys($payload) !== [
            'v',
            'organization_id',
            'user_id',
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
        $storageKey = $payload['storage_key'] ?? null;
        $filename = $payload['filename'] ?? null;
        $mimeType = $payload['mime_type'] ?? null;
        $byteSize = $payload['byte_size'] ?? null;
        $issuedAt = $payload['issued_at'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if (($payload['v'] ?? null) !== self::VERSION
            || !is_string($organizationId) || !Uuid::isValid($organizationId)
            || !is_string($userId) || !Uuid::isValid($userId)
            || !is_string($storageKey)
            || preg_match(
                '#\\Aorganizations/'.preg_quote($organizationId, '#').'/staging/[0-9a-fA-F-]{36}\\z#D',
                $storageKey,
            ) !== 1
            || !is_string($filename) || $filename === '' || mb_strlen($filename) > 180
            || basename(str_replace('\\\\', '/', $filename)) !== $filename
            || preg_match('/[\\x00-\\x1F\\x7F]/u', $filename) === 1
            || !is_string($mimeType) || !in_array($mimeType, self::MIMES, true)
            || !is_int($byteSize) || $byteSize < 1 || $byteSize > self::MAX_BYTES
            || !is_int($issuedAt) || $issuedAt < 1
            || !is_int($expiresAt) || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('Direct upload ticket payload is invalid.');
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
