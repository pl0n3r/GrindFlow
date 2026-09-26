<?php

namespace App\Support\Operations;

use RuntimeException;

final class ProductionWritePolicy
{
    public const PHASE_CONSTRUCTION = 'construccion';
    public const PHASE_LIVE = 'live';

    public function assertAutonomousWriteAllowed(
        string $operation,
        bool $destructive,
        bool $bulk,
        bool $versioned,
        bool $backupVerified,
        bool $lockHeld,
    ): void {
        $phase = (string) config('app.phase', self::PHASE_LIVE);

        if (! in_array($phase, [self::PHASE_CONSTRUCTION, self::PHASE_LIVE], true)) {
            throw new RuntimeException('APP_PHASE is invalid; production writes fail closed.');
        }

        if ($phase !== self::PHASE_CONSTRUCTION) {
            throw new RuntimeException('Autonomous production writes are disabled outside construction.');
        }

        if ($destructive) {
            throw new RuntimeException('Destructive production writes require explicit owner authorization.');
        }

        if (! $versioned) {
            throw new RuntimeException('Autonomous production writes must be versioned.');
        }

        if ($operation === 'migration' || $bulk) {
            if (! $backupVerified) {
                throw new RuntimeException('A recent verified database backup is required.');
            }

            if (! $lockHeld) {
                throw new RuntimeException('An exclusive operation lock is required.');
            }
        }
    }
}
