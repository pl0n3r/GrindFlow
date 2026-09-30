<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordVerifiedDatabaseBackupCommandTest extends TestCase
{
    public function test_command_records_receipt_only_for_existing_backup_archive(): void
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
        $this->assertTrue(collect($receipts)->contains(
            static fn (string $path): bool => str_ends_with($path, '.receipt.json')
        ));
    }

    public function test_command_fails_closed_when_archive_is_missing(): void
    {
        Storage::fake('local');

        $this->artisan('operations:record-db-backup', [
            'archive' => 'operations/database-backups/missing.sql.gz',
            'migration_fingerprint' => str_repeat('a', 64),
        ])->assertFailed();

        $this->assertSame(
            [],
            Storage::disk('local')->files('operations/database-backups'),
        );
    }
}
