<?php
declare(strict_types=1);

namespace App\Support\Security;

use InvalidArgumentException;
use RuntimeException;

final class MobileUploadGrant
{
    private const VERSION = 'v1';
    private const SCOPE = 'mobile-upload';
    private const MAX_FILES_CAP = 25;
    private const MAX_BYTES_CAP = 104857600;

    public function __construct(private readonly string $signingKey)
    {
        if (strlen($signingKey) < 32 || preg_match('/[\r\n]/', $signingKey) === 1) {
            throw new InvalidArgumentException('Mobile upload signing key is invalid.');
        }
    }

    public function issue(
        string $organizationId,
        int $expiresAt,
        int $maxFiles,
        int $maxBytes,
        string $nonce,
    ): string {
        self::identifier($organizationId, 'organization_id');
        self::identifier($nonce, 'nonce');
        if ($expiresAt < 1) {
            throw new InvalidArgumentException('expires_at invalid.');
        }
        self::limits($maxFiles, $maxBytes);

        $payload = json_encode([
            'v' => 1,
            'scope' => self::SCOPE,
            'organization_id' => $organizationId,
            'expires_at' => $expiresAt,
            'max_files' => $maxFiles,
            'max_bytes' => $maxBytes,
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $encoded = self::encode($payload);
        $signature = hash_hmac('sha256', self::VERSION . '.' . $encoded, $this->signingKey, true);

        return self::VERSION . '.' . $encoded . '.' . self::encode($signature);
    }

    public function validate(string $token, string $organizationId, int $now): array
    {
        self::identifier($organizationId, 'organization_id');
        if ($now < 1) {
            throw new InvalidArgumentException('now invalid.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Mobile upload grant format invalid.');
        }

        [$version, $encoded, $encodedSignature] = $parts;
        $provided = self::decode($encodedSignature);
        $expected = hash_hmac('sha256', $version . '.' . $encoded, $this->signingKey, true);
        if (!hash_equals($expected, $provided)) {
            throw new RuntimeException('Mobile upload grant signature invalid.');
        }

        $payload = json_decode(self::decode($encoded), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Mobile upload grant payload invalid.');
        }

        $expectedKeys = ['v','scope','organization_id','expires_at','max_files','max_bytes','nonce'];
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected, SORT_STRING);
        if ($keys !== $sortedExpected || ($payload['v'] ?? null) !== 1 || ($payload['scope'] ?? null) !== self::SCOPE) {
            throw new RuntimeException('Mobile upload grant payload invalid.');
        }

        $tenant = $payload['organization_id'] ?? null;
        $nonce = $payload['nonce'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;
        $maxFiles = $payload['max_files'] ?? null;
        $maxBytes = $payload['max_bytes'] ?? null;

        if (!is_string($tenant) || !is_string($nonce) || !is_int($expiresAt) || !is_int($maxFiles) || !is_int($maxBytes)) {
            throw new RuntimeException('Mobile upload grant payload invalid.');
        }
        self::identifier($tenant, 'organization_id');
        self::identifier($nonce, 'nonce');
        self::limits($maxFiles, $maxBytes);

        if (!hash_equals($tenant, $organizationId)) {
            throw new RuntimeException('Mobile upload grant tenant mismatch.');
        }
        if ($expiresAt <= $now) {
            throw new RuntimeException('Mobile upload grant expired.');
        }

        return [
            'scope' => self::SCOPE,
            'organization_id' => $tenant,
            'expires_at' => $expiresAt,
            'max_files' => $maxFiles,
            'max_bytes' => $maxBytes,
            'nonce' => $nonce,
        ];
    }

    private static function limits(int $maxFiles, int $maxBytes): void
    {
        if ($maxFiles < 1 || $maxFiles > self::MAX_FILES_CAP || $maxBytes < 1 || $maxBytes > self::MAX_BYTES_CAP) {
            throw new InvalidArgumentException('Mobile upload grant limits invalid.');
        }
    }

    private static function identifier(string $value, string $label): void
    {
        if (
            trim($value) === ''
            || strlen($value) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new RuntimeException('Mobile upload grant encoding invalid.');
        }
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Mobile upload grant encoding invalid.');
        }
        return $decoded;
    }
}
