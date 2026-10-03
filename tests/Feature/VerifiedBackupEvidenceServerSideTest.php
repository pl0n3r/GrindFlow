<?php

namespace Tests\Feature;

use App\Support\Operations\VerifiedBackupEvidence;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class VerifiedBackupEvidenceServerSideTest extends TestCase
{
    public function test_latest_receipt_is_private_and_validated_server_side(): void
    {
        Storage::fake('local');
        $fingerprint = str_repeat('a', 64);
        $archive = 'operations/database-backups/server-side.sql.gz';
        Storage::disk('local')->put($archive, 'database-backup');

        $evidence = app(VerifiedBackupEvidence::class);
        $receipt = $evidence->record($archive, $fingerprint);
        $pointer = "operations/database-backups/latest/{$fingerprint}.ref";
        $pointerPath = Storage::disk('local')->path($pointer);

        Storage::disk('local')->assertExists($pointer);
        $this->assertSame($receipt, trim((string) Storage::disk('local')->get($pointer)));
        clearstatcache(true, $pointerPath);
        $permissions = fileperms($pointerPath);
        $this->assertIsInt($permissions);
        $this->assertSame(0600, $permissions & 0777);

        $evidence->assertLatestValidForFingerprint($fingerprint);
        $this->assertTrue(true);
    }

    public function test_latest_receipt_fails_closed_for_missing_malformed_or_open_pointer(): void
    {
        Storage::fake('local');
        $fingerprint = str_repeat('b', 64);
        $archive = 'operations/database-backups/pointer-guards.sql.gz';
        Storage::disk('local')->put($archive, 'database-backup');
        $evidence = app(VerifiedBackupEvidence::class);
        $receipt = $evidence->record($archive, $fingerprint);
        $pointer = "operations/database-backups/latest/{$fingerprint}.ref";
        $pointerPath = Storage::disk('local')->path($pointer);

        Storage::disk('local')->delete($pointer);
        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($fingerprint),
            'pointer is missing or unsafe',
        );

        Storage::disk('local')->put($pointer, 'not-a-receipt');
        chmod($pointerPath, 0600);
        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($fingerprint),
            'pointer is malformed',
        );

        Storage::disk('local')->put($pointer, $receipt."\n");
        chmod($pointerPath, 0644);
        clearstatcache(true, $pointerPath);
        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($fingerprint),
            'pointer permissions are unsafe',
        );
    }

    public function test_latest_receipt_fails_closed_for_stale_mismatch_and_tampering(): void
    {
        Storage::fake('local');
        $fingerprint = str_repeat('c', 64);
        $otherFingerprint = str_repeat('d', 64);
        $archive = 'operations/database-backups/integrity.sql.gz';
        Storage::disk('local')->put($archive, 'database-backup');
        $evidence = app(VerifiedBackupEvidence::class);
        $receipt = $evidence->record($archive, $fingerprint);

        $otherPointer = "operations/database-backups/latest/{$otherFingerprint}.ref";
        Storage::disk('local')->put($otherPointer, $receipt."\n");
        chmod(Storage::disk('local')->path($otherPointer), 0600);
        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($otherFingerprint),
            'another migration batch',
        );

        Storage::disk('local')->put($archive, 'tampered-backup');
        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($fingerprint),
            'checksum does not match',
        );

        Storage::disk('local')->put($archive, 'database-backup');
        $receiptPath = "operations/database-backups/{$receipt}.receipt.json";
        Storage::disk('local')->put($receiptPath, '{"broken":true}');
        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($fingerprint),
            'receipt is malformed',
        );

        Storage::fake('local');
        Storage::disk('local')->put($archive, 'database-backup');
        $evidence = app(VerifiedBackupEvidence::class);
        $evidence->record($archive, $fingerprint);
        $this->travel(16)->minutes();

        $this->assertRuntimeFailure(
            fn () => $evidence->assertLatestValidForFingerprint($fingerprint),
            'not recent enough',
        );
    }

    public function test_failed_pointer_publication_removes_new_receipt(): void
    {
        Storage::fake('local');
        $fingerprint = str_repeat('e', 64);
        $archive = 'operations/database-backups/publication-failure.sql.gz';
        $disk = Storage::disk('local');
        $disk->put($archive, 'database-backup');

        $directory = $disk->path('operations/database-backups/latest');
        mkdir($directory, 0700, true);
        $pointerPath = $directory."/{$fingerprint}.ref";
        $this->assertTrue(symlink($disk->path($archive), $pointerPath));

        $this->assertRuntimeFailure(
            fn () => app(VerifiedBackupEvidence::class)->record($archive, $fingerprint),
            'pointer is unsafe',
        );

        $receipts = array_values(array_filter(
            $disk->files('operations/database-backups'),
            static fn (string $path): bool => str_ends_with($path, '.receipt.json'),
        ));
        $this->assertSame([], $receipts);
    }

    private function assertRuntimeFailure(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail("Expected RuntimeException containing: {$message}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
