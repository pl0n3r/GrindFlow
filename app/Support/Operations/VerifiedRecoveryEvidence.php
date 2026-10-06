<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class VerifiedRecoveryEvidence
{
    private const string DIRECTORY = 'operations/recovery-backups';

    public function record(
        string $ciphertextRelativePath,
        string $migrationFingerprint,
        string $vaultIndexSha256,
        string $releaseVersion,
        string $releaseSha,
        int $organizationCount,
        int $assetCount,
    ): string {
        $ciphertextRelativePath = $this->assertCiphertextPath($ciphertextRelativePath);
        $this->assertSha256($migrationFingerprint, 'Migration fingerprint');
        $this->assertSha256($vaultIndexSha256, 'Vault index fingerprint');
        $this->assertRelease($releaseVersion, $releaseSha);
        $this->assertCounts($organizationCount, $assetCount);

        $disk = Storage::disk('local');
        $ciphertextPath = $disk->path($ciphertextRelativePath);
        $this->assertPrivateRegularFile($ciphertextPath, 'Recovery ciphertext');

        $ciphertextSha256 = hash_file('sha256', $ciphertextPath);
        if ($ciphertextSha256 === false) {
            throw new RuntimeException('Recovery ciphertext cannot be hashed.');
        }

        $receiptPayload = [
            'version' => 1,
            'nonce' => bin2hex(random_bytes(16)),
            'format' => 'grindflow-recovery-v1',
            'created_at' => now('UTC')->toIso8601String(),
            'ciphertext' => $ciphertextRelativePath,
            'ciphertext_sha256' => $ciphertextSha256,
            'migration_fingerprint' => strtolower($migrationFingerprint),
            'vault_index_sha256' => strtolower($vaultIndexSha256),
            'release_version' => $releaseVersion,
            'release_sha' => strtolower($releaseSha),
            'organization_count' => $organizationCount,
            'asset_count' => $assetCount,
        ];
        $payload = json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $receiptId = hash('sha256', $payload."\n");

        $this->writeReceipt($receiptId, $payload);

        return $receiptId;
    }

    public function assertValid(
        string $receiptId,
        string $migrationFingerprint,
        string $vaultIndexSha256,
        string $releaseVersion,
        string $releaseSha,
    ): void {
        $this->assertReceiptId($receiptId);
        $this->assertSha256($migrationFingerprint, 'Migration fingerprint');
        $this->assertSha256($vaultIndexSha256, 'Vault index fingerprint');
        $this->assertRelease($releaseVersion, $releaseSha);

        $disk = Storage::disk('local');
        $receiptPath = $disk->path(self::DIRECTORY."/{$receiptId}.receipt.json");
        $this->assertPrivateRegularFile($receiptPath, 'Recovery receipt');

        $raw = file_get_contents($receiptPath);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $expectedKeys = [
            'version',
            'nonce',
            'format',
            'created_at',
            'ciphertext',
            'ciphertext_sha256',
            'migration_fingerprint',
            'vault_index_sha256',
            'release_version',
            'release_sha',
            'organization_count',
            'asset_count',
        ];

        if (
            ! is_array($payload)
            || array_keys($payload) !== $expectedKeys
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['nonce'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/', $payload['nonce']) !== 1
            || ($payload['format'] ?? null) !== 'grindflow-recovery-v1'
            || ! is_string($payload['created_at'] ?? null)
            || ! is_string($payload['ciphertext'] ?? null)
            || ! is_string($payload['ciphertext_sha256'] ?? null)
            || ! is_string($payload['migration_fingerprint'] ?? null)
            || ! is_string($payload['vault_index_sha256'] ?? null)
            || ! is_string($payload['release_version'] ?? null)
            || ! is_string($payload['release_sha'] ?? null)
            || ! is_int($payload['organization_count'] ?? null)
            || ! is_int($payload['asset_count'] ?? null)
        ) {
            throw new RuntimeException('Verified recovery receipt is malformed.');
        }

        $this->assertSha256($payload['ciphertext_sha256'], 'Recovery ciphertext checksum');
        $this->assertSha256($payload['migration_fingerprint'], 'Migration fingerprint');
        $this->assertSha256($payload['vault_index_sha256'], 'Vault index fingerprint');
        $this->assertRelease($payload['release_version'], $payload['release_sha']);
        $this->assertCounts($payload['organization_count'], $payload['asset_count']);

        if (
            ! hash_equals(strtolower($migrationFingerprint), $payload['migration_fingerprint'])
            || ! hash_equals(strtolower($vaultIndexSha256), $payload['vault_index_sha256'])
            || ! hash_equals($releaseVersion, $payload['release_version'])
            || ! hash_equals(strtolower($releaseSha), $payload['release_sha'])
        ) {
            throw new RuntimeException('Verified recovery receipt belongs to different recovery evidence.');
        }

        try {
            CarbonImmutable::parse($payload['created_at'], 'UTC');
        } catch (\Throwable) {
            throw new RuntimeException('Verified recovery receipt timestamp is invalid.');
        }

        $identityPayload = $payload;
        $encodedIdentity = json_encode(
            $identityPayload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (! hash_equals($receiptId, hash('sha256', $encodedIdentity."\n"))) {
            throw new RuntimeException('Verified recovery receipt identity does not match payload.');
        }

        $ciphertextRelativePath = $this->assertCiphertextPath($payload['ciphertext']);
        $ciphertextPath = $disk->path($ciphertextRelativePath);
        $this->assertPrivateRegularFile($ciphertextPath, 'Recovery ciphertext');

        $actualSha256 = hash_file('sha256', $ciphertextPath);
        if (
            $actualSha256 === false
            || ! hash_equals($payload['ciphertext_sha256'], $actualSha256)
        ) {
            throw new RuntimeException('Verified recovery ciphertext checksum does not match.');
        }
    }

    private function writeReceipt(string $receiptId, string $payload): void
    {
        $disk = Storage::disk('local');
        $directory = $disk->path(self::DIRECTORY);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Recovery receipt directory could not be created.');
        }

        if (! is_dir($directory) || is_link($directory) || @chmod($directory, 0700) === false) {
            throw new RuntimeException('Recovery receipt directory is unsafe.');
        }

        $receiptPath = $directory."/{$receiptId}.receipt.json";
        if (file_exists($receiptPath) || is_link($receiptPath)) {
            throw new RuntimeException('Recovery receipt path already exists.');
        }

        $temporaryPath = tempnam($directory, '.tmp-recovery-receipt-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Recovery receipt could not be staged.');
        }

        try {
            if (
                @chmod($temporaryPath, 0600) === false
                || file_put_contents($temporaryPath, $payload."\n", LOCK_EX) === false
            ) {
                throw new RuntimeException('Recovery receipt could not be secured.');
            }

            $this->assertPrivateRegularFile($temporaryPath, 'Recovery receipt staging file');

            if (@link($temporaryPath, $receiptPath) === false) {
                throw new RuntimeException('Recovery receipt could not be published.');
            }

            $this->assertPrivateRegularFile($receiptPath, 'Recovery receipt');
        } catch (RuntimeException $exception) {
            @unlink($receiptPath);

            throw $exception;
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function assertPrivateRegularFile(string $path, string $label): void
    {
        $metadata = @lstat($path);
        if (
            $metadata === false
            || ($metadata['mode'] & 0170000) !== 0100000
            || ($metadata['mode'] & 0777) !== 0600
        ) {
            throw new RuntimeException("{$label} is unavailable or unsafe.");
        }
    }

    private function assertCiphertextPath(string $relativePath): string
    {
        if (
            preg_match(
                '#\Aoperations/recovery-backups/[A-Za-z0-9._-]+\.gfrec\z#',
                $relativePath,
            ) !== 1
            || str_contains($relativePath, '..')
        ) {
            throw new RuntimeException('Recovery ciphertext path is invalid.');
        }

        return $relativePath;
    }

    private function assertReceiptId(string $receiptId): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $receiptId) !== 1) {
            throw new RuntimeException('Recovery receipt identifier is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $value) !== 1) {
            throw new RuntimeException("{$label} is invalid.");
        }
    }

    private function assertRelease(string $version, string $sha): void
    {
        if (
            preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version) !== 1
            || preg_match('/\A[a-f0-9]{40}\z/i', $sha) !== 1
        ) {
            throw new RuntimeException('Recovery release identity is invalid.');
        }
    }

    private function assertCounts(int $organizationCount, int $assetCount): void
    {
        if (
            $organizationCount < 0
            || $organizationCount > 10000
            || $assetCount < 0
            || $assetCount > 1000000
            || ($organizationCount === 0 && $assetCount !== 0)
        ) {
            throw new RuntimeException('Recovery evidence counts are invalid.');
        }
    }
}
