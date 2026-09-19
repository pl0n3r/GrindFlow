<?php

namespace App\Services\Distribution;

use RuntimeException;

final class DistributionProviderException extends RuntimeException
{
    public const KIND_AUTHENTICATION = 'authentication';

    public const KIND_RATE_LIMIT = 'rate_limit';

    public const KIND_TRANSIENT = 'transient';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('distribution_provider_'.$kind);
    }

    public static function authentication(): self
    {
        return new self(self::KIND_AUTHENTICATION);
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(self::KIND_RATE_LIMIT, $retryAfterSeconds);
    }

    public static function transient(): self
    {
        return new self(self::KIND_TRANSIENT);
    }
}
