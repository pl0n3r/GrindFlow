<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class VerifiedBackupEvidence
{
    public const MAX_AGE_SECONDS = 900;

    public function record(string $archiveRelativePath, string $migrationFingerprint): string
    {
        $this->assertFingerprint($migrationFingerprint);
        $archiveRelativePath = $this->assertArchivePath($archiveRelativePath);
        $disk = Storage::disk('local');
        $archivePath = $disk->path($archiveRelativePath);

        if (! is_file($archivePath) || is_link($archivePath)) {
            throw new RuntimeException('Database backup archive is unavailable or unsafe.');
        }

        $archiveHash = hash_file('sha256', $archivePath);

        if ($archiveHash === false) {
            throw new RuntimeException('Database backup archive cannot be hashed.');
        }

        $receiptId = hash('sha256', random_bytes(32).$migrationFingerprint.$archiveHash);
        $receiptRelativePath = "operations/database-backups/{$receiptId}.receipt.json";
        $payload = json_encode([
            'version' => 1,
            'created_at' => now('UTC')->toIso8601String(),
            'migration_fingerprint' => $migrationFingerprint,
            'archive' => $archiveRelativePath,
            'archive_sha256' => $archiveHash,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($disk->put($receiptRelativePath, $payload."\n") !== true) {
            throw new RuntimeException('Database backup receipt could not be stored.');
        }

        if (@chmod($disk->path($receiptRelativePath), 0600) === false) {
            $disk->delete($receiptRelativePath);
            throw new RuntimeException('Database backup receipt could not be secured.');
        }

        return $receiptId;
    }

    public function assertValid(string $receiptId, string $migrationFingerprint): void
    {
        $this->assertFingerprint($migrationFingerprint);

        if (preg_match('/\A[a-f0-9]{64}\z/', $receiptId) !== 1) {
            throw new RuntimeException('Database backup receipt identifier is invalid.');
        }

        $disk = Storage::disk('local');
        $receiptRelativePath = "operations/database-backups/{$receiptId}.receipt.json";
        $receiptPath = $disk->path($receiptRelativePath);

        if (! is_file($receiptPath) || is_link($receiptPath)) {
            throw new RuntimeException('Verified database backup receipt is missing.');
        }

        $raw = file_get_contents($receiptPath);
        $payload = is_string($raw) ? json_decode($raw, true) : null;

        if (
            ! is_array($payload)
            || $payload['version'] !== 1
            || ! is_string($payload['created_at'] ?? null)
            || ! is_string($payload['migration_fingerprint'] ?? null)
            || ! is_string($payload['archive'] ?? null)
            || ! is_string($payload['archive_sha256'] ?? null)
        ) {
            throw new RuntimeException('Verified database backup receipt is malformed.');
        }

        if (! hash_equals($migrationFingerprint, $payload['migration_fingerprint'])) {
            throw new RuntimeException('Database backup receipt belongs to another migration batch.');
        }

        try {
            $createdAt = CarbonImmutable::parse($payload['created_at'], 'UTC');
        } catch (\Throwable) {
            throw new RuntimeException('Database backup receipt timestamp is invalid.');
        }

        $age = $createdAt->diffInSeconds(now('UTC'), false);

        if ($age < -60 || $age > self::MAX_AGE_SECONDS) {
            throw new RuntimeException('Database backup receipt is not recent enough.');
        }

        $archiveRelativePath = $this->assertArchivePath($payload['archive']);
        $archivePath = $disk->path($archiveRelativePath);

        if (! is_file($archivePath) || is_link($archivePath)) {
            throw new RuntimeException('Verified database backup archive is unavailable.');
        }

        $actualHash = hash_file('sha256', $archivePath);

        if ($actualHash === false || ! hash_equals($payload['archive_sha256'], $actualHash)) {
            throw new RuntimeException('Verified database backup archive checksum does not match.');
        }
    }

    private function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Migration fingerprint is invalid.');
        }
    }

    private function assertArchivePath(string $relativePath): string
    {
        if (
            preg_match('/\Aoperations\/database-backups\/[A-Za-z0-9._-]+\.sql\.gz\z/', $relativePath) !== 1
            || str_contains($relativePath, '..')
        ) {
            throw new RuntimeException('Database backup archive path is invalid.');
        }

        return $relativePath;
    }
}
