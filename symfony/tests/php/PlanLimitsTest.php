<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Plans\PlanCatalog;
use GrindFlow\Plans\PlanLimit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Named exactly as the acceptance contract requires. */
final class Planlimitstest extends TestCase
{
    public function test_criterio_principal(): void
    {
        $catalog = new PlanCatalog();
        $unknown = $catalog->evaluate('org-a', 'owner-defined', ['storage_bytes' => 150]);
        self::assertSame('unknown', $unknown['status']);
        self::assertNull($unknown['within_limits']);
        self::assertNull($unknown['limits']['storage_bytes']['limit']);
        self::assertSame('unknown', $unknown['limits']['api_calls']['status']);

        // Values are only supplied by a configuration caller; never hard-coded as plan policy.
        $catalog->configure('owner-defined', [
            'storage_bytes' => 200, 'traffic_bytes' => 0,
            'queued_jobs' => 2, 'api_calls' => 10,
        ]);
        $usage = ['storage_bytes' => 200, 'traffic_bytes' => 0, 'queued_jobs' => 2, 'api_calls' => 10];
        $within = $catalog->evaluate('org-a', 'owner-defined', $usage);
        self::assertTrue($within['within_limits']);
        self::assertSame('within', $within['status']);
        self::assertSame(0, $within['limits']['storage_bytes']['remaining']);

        $over = $catalog->evaluate('org-b', 'owner-defined', [...$usage, 'api_calls' => 11]);
        self::assertFalse($over['within_limits']);
        self::assertSame('exceeded', $over['status']);
        self::assertSame('org-b', $over['organization_id']);
        self::assertSame('exceeded', $over['limits']['api_calls']['status']);

        // Partial configuration does not grant an invented allowance (or a ban).
        $catalog->configure('partial', ['queued_jobs' => 1]);
        $partial = $catalog->evaluate('org-a', 'partial', ['queued_jobs' => 1]);
        self::assertNull($partial['within_limits']);
        self::assertSame('unknown', $partial['status']);
        self::assertNull($partial['limits']['traffic_bytes']['limit']);
        self::assertSame('unknown', $catalog->evaluate('org-a', 'missing', [])['status']);
    }

    public function test_invalid_configurations_and_consumption(): void
    {
        $catalog = new PlanCatalog();
        foreach ([
            static fn () => $catalog->configure('', []),
            static fn () => $catalog->configure('bad plan ', []),
            static fn () => $catalog->configure('p', ['nonexistent' => 1]),
            static fn () => $catalog->configure('p', ['storage_bytes' => -1]),
            static fn () => $catalog->configure('p', ['storage_bytes' => 1.5]),
            static fn () => $catalog->configure('p', ['storage_bytes' => '2']),
            static fn () => $catalog->evaluate('', 'p', []),
            static fn () => $catalog->evaluate('org', '', []),
            static fn () => $catalog->evaluate('org', 'p', ['api_calls' => -1]),
            static fn () => $catalog->evaluate('org', 'p', ['api_calls' => '1']),
            static fn () => $catalog->evaluate('org', 'p', ['unexpected' => 1]),
            static fn () => new PlanLimit('bogus'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid configuration or usage must be rejected.');
            } catch (InvalidArgumentException) {
                // Every invalid case is fail-closed.
            }
        }
    }

    public function test_unknown_usage_and_zero_are_distinct(): void
    {
        $catalog = new PlanCatalog(['a' => [
            'storage_bytes' => 0, 'traffic_bytes' => 0,
            'queued_jobs' => 0, 'api_calls' => 0,
        ]]);
        $unknown = $catalog->evaluate('org', 'a', []);
        self::assertNull($unknown['within_limits']);
        self::assertNull($unknown['limits']['storage_bytes']['used']);
        $zero = $catalog->evaluate('org', 'a', array_fill_keys(PlanCatalog::DIMENSIONS, 0));
        self::assertTrue($zero['within_limits']);
        $over = $catalog->evaluate('org', 'a', ['storage_bytes' => 1]);
        self::assertFalse($over['within_limits']);
        self::assertSame('unknown', $over['limits']['traffic_bytes']['status']);
    }
}
