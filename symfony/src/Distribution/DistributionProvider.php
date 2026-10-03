<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

interface DistributionProvider
{
    public function publish(DistributionCommand $command): DistributionOutcome;
}
