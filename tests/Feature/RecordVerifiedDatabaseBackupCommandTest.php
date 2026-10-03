<?php

namespace Tests\Feature;

use App\Support\Operations\VerifiedBackupEvidence;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordVerifiedDatabaseBackupCommandTest extends TestCase
{
    public function test_command_records_receipt_and_latest_pointer_for_existing_backup_archive(): void
    {
        Storage::fake('local');
        $archive = 'operations/database-backups/command-test.sql.gz';
        Storage::disk('local')->put($archive, 'db-backup');
        $fingerprint = str_repeat('a', 64);

        $this->artisan('operations:record-db-backup', [
            'archive' => $archive,
            'migration_fingerprint' => $fingerprint,
        ])->assertSuccessful();

        $receipts = Storage::disk('local')->files('operations/database-backups');
        $this->assertCount(2, $receipts);
        $receiptPath = collect($receipts)->first(
            static fn (string $path): bool => str_ends_with($path, '.receipt.json')
        );
        $this->assertIsString($receiptPath);

        $pointer = "operations/database-backups/latest/{$fingerprint}.ref";
        Storage::disk('local')->assertExists($pointer);
        $receiptId = trim((string) Storage::disk('local')->get($pointer));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $receiptId);
        $this->assertSame(
            "operations/database-backups/{$receiptId}.receipt.json",
            $receiptPath,
        );

        app(VerifiedBackupEvidence::class)
            ->assertLatestValidForFingerprint($fingerprint);
    }

    public function test_command_fails_closed_when_archive_is_missing(): void
    {
        Storage::fake('local');
        $fingerprint = str_repeat('a', 64);

        $this->artisan('operations:record-db-backup', [
            'archive' => 'operations/database-backups/missing.sql.gz',
            'migration_fingerprint' => $fingerprint,
        ])->assertFailed();

        $this->assertSame(
            [],
            Storage::disk('local')->files('operations/database-backups'),
        );
        Storage::disk('local')->assertMissing(
            "operations/database-backups/latest/{$fingerprint}.ref"
        );
    }
}
