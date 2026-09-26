<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Security;

final class StaffOpsAuthException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
    ) {
        parent::__construct($errorCode);
    }
}
