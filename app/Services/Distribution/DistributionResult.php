<?php

namespace App\Services\Distribution;

use InvalidArgumentException;

final readonly class DistributionResult
{
    public function __construct(
        public string $externalPublicationId,
    ) {
        if (trim($this->externalPublicationId) === '') {
            throw new InvalidArgumentException(
                'External publication id cannot be empty.',
            );
        }
    }
}
