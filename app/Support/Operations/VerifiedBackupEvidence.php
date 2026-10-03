<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class VerifiedBackupEvidence
{
    public const int MAX_AGE_SECONDS = 900;

    private const string LATEST_POINTER_DIRECTORY = 'operations/database-backups/latest';

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

        try {
            $this->writeLatestPointer($receiptId, $migrationFingerprint);
        } catch (RuntimeException $exception) {
            $disk->delete($receiptRelativePath);

            throw $exception;
        }

        return $receiptId;
    }

    public function assertLatestValidForFingerprint(string $migrationFingerprint): void
    {
        $this->assertFingerprint($migrationFingerprint);

        $disk = Storage::disk('local');
        $pointerRelativePath = self::LATEST_POINTER_DIRECTORY."/{$migrationFingerprint}.ref";
        $pointerPath = $disk->path($pointerRelativePath);

        if (! is_file($pointerPath) || is_link($pointerPath)) {
            throw new RuntimeException('Verified database backup pointer is missing or unsafe.');
        }

        $permissions = fileperms($pointerPath);

        if ($permissions === false || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('Verified database backup pointer permissions are unsafe.');
        }

        $size = filesize($pointerPath);

        if ($size === false || $size < 64 || $size > 65) {
            throw new RuntimeException('Verified database backup pointer is malformed.');
        }

        $raw = file_get_contents($pointerPath);
        $receiptId = is_string($raw) ? trim($raw) : '';

        if (preg_match('/\\A[a-f0-9]{64}\\z/', $receiptId) !== 1) {
            throw new RuntimeException('Verified database backup pointer is malformed.');
        }

        $this->assertValid($receiptId, $migrationFingerprint);
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
            || ($payload['version'] ?? null) !== 1
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

    private function writeLatestPointer(string $receiptId, string $migrationFingerprint): void
    {
        $disk = Storage::disk('local');
        $directory = $disk->path(self::LATEST_POINTER_DIRECTORY);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Verified database backup pointer directory could not be created.');
        }

        if (! is_dir($directory) || is_link($directory) || @chmod($directory, 0700) === false) {
            throw new RuntimeException('Verified database backup pointer directory is unsafe.');
        }

        $pointerPath = $directory."/{$migrationFingerprint}.ref";

        if (is_link($pointerPath)) {
            throw new RuntimeException('Verified database backup pointer is unsafe.');
        }

        $temporaryPath = tempnam($directory, '.tmp-receipt-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Verified database backup pointer could not be staged.');
        }

        try {
            if (
                file_put_contents($temporaryPath, $receiptId."\n", LOCK_EX) === false
                || @chmod($temporaryPath, 0600) === false
            ) {
                throw new RuntimeException('Verified database backup pointer could not be secured.');
            }

            if (@rename($temporaryPath, $pointerPath) === false) {
                throw new RuntimeException('Verified database backup pointer could not be published.');
            }

            if (! is_file($pointerPath) || is_link($pointerPath) || @chmod($pointerPath, 0600) === false) {
                @unlink($pointerPath);

                throw new RuntimeException('Verified database backup pointer could not be secured.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
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
