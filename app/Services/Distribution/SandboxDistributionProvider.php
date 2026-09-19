<?php

namespace App\Services\Distribution;

use App\Contracts\DistributionProvider;
use App\Models\ScheduledPublication;

class SandboxDistributionProvider implements DistributionProvider
{
    public function publish(
        ScheduledPublication $publication,
        string $idempotencyKey,
    ): DistributionResult {
        return new DistributionResult(
            'sandbox-'.substr(hash('sha256', $idempotencyKey), 0, 24),
        );
    }
}
