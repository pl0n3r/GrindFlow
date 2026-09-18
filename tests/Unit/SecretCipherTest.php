<?php

namespace Tests\Unit;

use App\Support\Security\SecretCipher;
use App\Support\Security\SecretCryptoException;
use PHPUnit\Framework\TestCase;

class SecretCipherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'grindflow.security.encryption_master_key' => str_repeat('ab', 32),
        ]);
    }

    public function test_secret_round_trip_uses_versioned_authenticated_format(): void
    {
        $cipher = new SecretCipher;
        $context = 'grindflow:cloud:organization-a:dropbox';

        $encrypted = $cipher->encrypt(
            'sensitive-access-token',
            $context,
        );

        $this->assertStringStartsWith('v1.', $encrypted);
        $this->assertStringNotContainsString('sensitive-access-token', $encrypted);
        $this->assertTrue($cipher->isEncrypted($encrypted));
        $this->assertSame(
            'sensitive-access-token',
            $cipher->decrypt($encrypted, $context),
        );
    }

    public function test_ciphertext_cannot_be_moved_to_a_different_tenant_context(): void
    {
        $cipher = new SecretCipher;

        $encrypted = $cipher->encrypt(
            'tenant-bound-token',
            'grindflow:cloud:organization-a:dropbox',
        );

        $this->expectException(SecretCryptoException::class);

        $cipher->decrypt(
            $encrypted,
            'grindflow:cloud:organization-b:dropbox',
        );
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $cipher = new SecretCipher;
        $context = 'grindflow:cloud:organization-a:dropbox';

        $encrypted = $cipher->encrypt('original-token', $context);
        $parts = explode('.', $encrypted);
        $parts[3] = substr($parts[3], 0, -1)
            .($parts[3][-1] === 'A' ? 'B' : 'A');

        $this->expectException(SecretCryptoException::class);

        $cipher->decrypt(implode('.', $parts), $context);
    }

    public function test_invalid_master_key_is_rejected_before_encryption(): void
    {
        config([
            'grindflow.security.encryption_master_key' => 'not-a-valid-key',
        ]);

        $this->expectException(SecretCryptoException::class);

        (new SecretCipher)->encrypt(
            'token',
            'grindflow:cloud:organization-a:dropbox',
        );
    }
}
