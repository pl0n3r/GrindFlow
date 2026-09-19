<?php

namespace App\Contracts;

use App\Models\ScheduledPublication;
use App\Services\Distribution\DistributionResult;

interface DistributionProvider
{
    public function publish(
        ScheduledPublication $publication,
        string $idempotencyKey,
    ): DistributionResult;
}
