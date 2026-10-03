<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use RuntimeException;

final class DistributionProviderException extends RuntimeException
{
    public const string KIND_CONFIGURATION = 'configuration';
    public const string KIND_AUTHENTICATION = 'authentication';
    public const string KIND_RATE_LIMIT = 'rate_limit';
    public const string KIND_REJECTED = 'rejected';
    public const string KIND_AMBIGUOUS = 'ambiguous';

    private function __construct(
        public readonly string $kind,
        public readonly bool $automaticRetryAllowed,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('distribution_provider_'.$kind);
    }

    public static function configuration(): self
    {
        return new self(self::KIND_CONFIGURATION, false);
    }

    public static function authentication(): self
    {
        return new self(self::KIND_AUTHENTICATION, false);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            self::KIND_RATE_LIMIT,
            true,
            max(60, min($retryAfterSeconds, 3600)),
        );
    }

    public static function rejected(): self
    {
        return new self(self::KIND_REJECTED, false);
    }

    public static function ambiguous(): self
    {
        return new self(self::KIND_AMBIGUOUS, false);
    }
}
