<?php

namespace App\Services\Distribution;

use InvalidArgumentException;

final readonly class DistributionResult
{
    public function __construct(
        public string $externalPublicationId,
    ) {
        if (
            trim($this->externalPublicationId) === ''
            || mb_strlen($this->externalPublicationId) > 191
        ) {
            throw new InvalidArgumentException(
                'External publication id must contain 1-191 characters.',
            );
        }
    }
}
