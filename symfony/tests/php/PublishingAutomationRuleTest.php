<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Scheduling\WeeklySlotCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic offline contract for time expansion. HTTP, database and
 * authorization are independently gated by the S4 integration suite.
 */
final class PublishingAutomationRuleTest extends TestCase
{
    public function testMultipleLocalTimesUseCanonicalUtcSlotsWithoutPublishing(): void
    {
        $rule = [
            'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '10:00',
            'local_times' => ['10:00', '11:00'],
            'max_per_day' => 2,
        ];
        $slots = (new WeeklySlotCalculator())->upcoming($rule);
        self::assertGreaterThanOrEqual(12, count($slots));
        self::assertLessThanOrEqual(14, count($slots));
        foreach ($slots as $slot) {
            self::assertContains($slot['local_time'], ['10:00', '11:00']);
            self::assertSame('UTC', $slot['timezone']);
            self::assertSame(2, $slot['capacity']);
            self::assertMatchesRegularExpression('/Z$/', $slot['scheduled_at_utc']);
        }
        $keys = array_column($slots, 'scheduled_at_utc');
        self::assertSame(count($keys), count(array_unique($keys)));
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testLegacyReviewOnlyRuleRetainsOneTimePerWeekday(): void
    {
        $rule = [
            'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '10:00',
            'max_per_day' => 1,
        ];
        $slots = (new WeeklySlotCalculator())->upcoming($rule);
        self::assertGreaterThanOrEqual(6, count($slots));
        self::assertLessThanOrEqual(7, count($slots));
        foreach ($slots as $slot) {
            self::assertSame('10:00', $slot['local_time']);
        }
    }

    public function testInvalidLocalTimesFailClosed(): void
    {
        $rule = [
            'timezone' => 'UTC', 'weekdays' => 'mon',
            'local_time' => '10:00', 'max_per_day' => 1,
            'local_times' => ['25:61'],
        ];
        self::assertSame([], (new WeeklySlotCalculator())->upcoming($rule));
    }
}
