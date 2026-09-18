<?php

namespace App\Support\Security;

class SecretCipher
{
    private const VERSION = 'v1';

    private const ALGORITHM = 'aes-256-gcm';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    private const KEY_BYTES = 32;

    public function encrypt(string $plaintext, ?string $context = null): string
    {
        if ($plaintext === '') {
            throw new SecretCryptoException('Secret plaintext cannot be empty.');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGORITHM,
            $this->masterKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context ?? '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new SecretCryptoException('Secret encryption failed.');
        }

        return implode('.', [
            self::VERSION,
            $this->base64UrlEncode($iv),
            $this->base64UrlEncode($tag),
            $this->base64UrlEncode($ciphertext),
        ]);
    }

    public function decrypt(string $payload, ?string $context = null): string
    {
        $parts = explode('.', $payload);

        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new SecretCryptoException('Encrypted secret format is invalid.');
        }

        $iv = $this->base64UrlDecode($parts[1]);
        $tag = $this->base64UrlDecode($parts[2]);
        $ciphertext = $this->base64UrlDecode($parts[3]);

        if (strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new SecretCryptoException('Encrypted secret payload is corrupt.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGORITHM,
            $this->masterKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context ?? '',
        );

        if ($plaintext === false) {
            throw new SecretCryptoException(
                'Encrypted secret authentication failed.',
            );
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        $parts = explode('.', $value);

        return count($parts) === 4 && $parts[0] === self::VERSION;
    }

    private function masterKey(): string
    {
        $raw = (string) config('grindflow.security.encryption_master_key', '');

        if (
            strlen($raw) !== self::KEY_BYTES * 2
            || ctype_xdigit($raw) === false
        ) {
            throw new SecretCryptoException(
                'ENCRYPTION_MASTER_KEY must be 64 hexadecimal characters.',
            );
        }

        $key = hex2bin($raw);

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new SecretCryptoException('ENCRYPTION_MASTER_KEY is invalid.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new SecretCryptoException('Encrypted secret encoding is invalid.');
        }

        return $decoded;
    }
}
