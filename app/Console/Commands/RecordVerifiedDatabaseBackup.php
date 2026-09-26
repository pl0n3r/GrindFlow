<?php

namespace App\Console\Commands;

use App\Support\Operations\VerifiedBackupEvidence;
use Illuminate\Console\Command;
use RuntimeException;

class RecordVerifiedDatabaseBackup extends Command
{
    protected $signature = 'operations:record-db-backup
        {archive : Relative local-disk path operations/database-backups/*.sql.gz}
        {migration_fingerprint : 64-hex fingerprint for the pending migration batch}';

    protected $description = 'Record a short-lived receipt for an already-created verified database backup';

    public function handle(VerifiedBackupEvidence $evidence): int
    {
        try {
            $receipt = $evidence->record(
                (string) $this->argument('archive'),
                (string) $this->argument('migration_fingerprint'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line($receipt);

        return self::SUCCESS;
    }
}
