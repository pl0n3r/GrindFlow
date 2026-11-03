<?php

declare(strict_types=1);

namespace GrindFlow\Plans;

use InvalidArgumentException;

/** A configurable non-enforcing quota. Null means unknown, never unlimited. */
final class PlanLimit
{
    public function __construct(
        public readonly string $dimension,
        public readonly ?int $maximum = null,
    ) {
        if (!in_array($dimension, PlanCatalog::DIMENSIONS, true)) {
            throw new InvalidArgumentException('Unknown plan limit dimension.');
        }
        if ($maximum !== null && $maximum < 0) {
            throw new InvalidArgumentException('Plan limit cannot be negative.');
        }
    }

    /** @return array{limit: ?int, used: ?int, remaining: ?int, status: string, within_limit: ?bool} */
    public function evaluate(?int $used): array
    {
        if ($used !== null && $used < 0) {
            throw new InvalidArgumentException('Plan consumption cannot be negative.');
        }

        $known = $this->maximum !== null && $used !== null;
        $within = $known ? $used <= $this->maximum : null;

        return [
            'limit' => $this->maximum,
            'used' => $used,
            'remaining' => $known ? max(0, $this->maximum - $used) : null,
            'status' => $within === null ? 'unknown' : ($within ? 'within' : 'exceeded'),
            'within_limit' => $within,
        ];
    }
}
