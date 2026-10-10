<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Scheduling\Horizon\GapDetector;
use GrindFlow\Scheduling\Horizon\HorizonReplenisher;
use PHPUnit\Framework\TestCase;

final class SchedulingHorizonTest extends TestCase
{
    public function testGapDetectionSkipsBlockedAndForeignTenant(): void
    {
        $result = (new GapDetector())->detect('tenant-a', 'America/Bogota', '2026-10-12', 3, 1, [
            ['tenant_id' => 'tenant-a', 'date' => '2026-10-12', 'slot' => 1],
            ['tenant_id' => 'tenant-b', 'date' => '2026-10-14', 'slot' => 1],
        ], ['2026-10-13']);
        self::assertSame('ok', $result['status']);
        self::assertSame([['date' => '2026-10-14', 'slot' => 1]], $result['gaps']);
    }

    public function testModesNeverWriteAndNeverUseForeignContent(): void
    {
        $planner = new HorizonReplenisher();
        $args = ['tenant-a', 'America/Bogota', '2026-10-12', 2, 1, [], [
            ['id' => 'safe-asset', 'tenant_id' => 'tenant-a', 'eligible' => true],
            ['id' => 'foreign-asset', 'tenant_id' => 'tenant-b', 'eligible' => true],
        ]];
        $manual = $planner->plan(...[...$args, 'manual']);
        $pilot = $planner->plan(...[...$args, 'pilot']);
        self::assertSame('notify', $manual['action']);
        self::assertSame([], $manual['proposals']);
        self::assertSame('pilot_preview', $pilot['action']);
        self::assertCount(1, $pilot['proposals']);
        self::assertSame('safe-asset', $pilot['proposals'][0]['asset_id']);
        self::assertSame(1, $pilot['unfilled_count']);
    }

    public function testUnauthorizedOrAmbiguousStateFailsClosed(): void
    {
        $detector = new GapDetector();
        self::assertSame('rejected', $detector->detect('tenant-a', 'UTC', '2026-10-12', 1, 1, [], [], [], false)['status']);
        self::assertSame('uncertain_schedule', $detector->detect('tenant-a', 'UTC', '2026-10-12', 1, 1, [
            ['tenant_id' => 'tenant-a', 'date' => '2026-10-12', 'slot' => 1, 'status' => 'ambiguous'],
        ])['reason']);
    }
}
