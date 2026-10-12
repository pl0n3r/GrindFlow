<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Scheduling\WeeklySlotCalculator;
use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Scheduling\PublishingAutomationRuleScheduler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Deterministic offline contract for time expansion. HTTP, database and
 * authorization are independently gated by the S4 integration suite.
 */
final class PublishingAutomationRuleTest extends KernelTestCase
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

    public function testSpringForwardMissingWallClockTimeIsNotSilentlyMoved(): void
    {
        // 2026-03-29 02:30 does not exist in Europe/Berlin.
        $rule = [
            'timezone' => 'Europe/Berlin',
            'weekdays' => 'sun',
            'local_time' => '02:30',
            'local_times' => ['02:30'],
            'max_per_day' => 1,
        ];
        $clock = new \DateTimeImmutable('2026-03-28T12:00:00Z');
        self::assertSame([], (new WeeklySlotCalculator())->upcoming($rule, $clock));
    }

    public function testFallBackRepeatedLocalTimeDoesNotCreateDuplicateDraftSlots(): void
    {
        // 2026-10-25 02:30 occurs twice; one configured local time is one slot.
        $rule = [
            'timezone' => 'Europe/Berlin',
            'weekdays' => 'sun',
            'local_time' => '02:30',
            'local_times' => ['02:30'],
            'max_per_day' => 1,
        ];
        $clock = new \DateTimeImmutable('2026-10-24T12:00:00Z');
        $slots = (new WeeklySlotCalculator())->upcoming($rule, $clock);
        self::assertCount(1, $slots);
        self::assertSame('02:30', $slots[0]['local_time']);
        self::assertContains($slots[0]['scheduled_at_utc'], [
            '2026-10-25T00:30:00Z',
            '2026-10-25T01:30:00Z',
        ]);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /** MySQL-backed safety gates: no cron, no credentials, no remote I/O. */
    public function testMaterializerCannotReserveForUnknownOrUnconfiguredTenant(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        $service = static::getContainer()->get(PublishingAutomationRuleScheduler::class);
        $tenant = Uuid::v7()->toRfc4122();
        $before = (int) $db->fetchOne('SELECT COUNT(*) FROM gf_schedule_drafts');

        self::assertSame('paused', $service->materialize($tenant, true)['status']);

        // The rule is active only as a synthetic fixture. Without an
        // allowlisted provider and tenant configuration, even --apply
        // must stop before constructing a draft or contacting the network.
        $db->insert('gf_content_rules', [
            'organization_id' => $tenant,
            'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '10:00',
            'max_per_day' => 2,
            'mode' => 'review_only',
            'updated_by' => Uuid::v7()->toRfc4122(),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'automation_state' => 'active',
            'automation_local_times' => '["10:00","11:00"]',
            'automation_destination_id' => '123456789',
        ]);
        try {
            self::assertSame('destination_unavailable', $service->materialize($tenant, true)['status']);
            $db->update('gf_content_rules', ['automation_state' => 'paused'], ['organization_id' => $tenant]);
            self::assertSame('paused', $service->materialize($tenant, true)['status']);
            self::assertSame($before, (int) $db->fetchOne('SELECT COUNT(*) FROM gf_schedule_drafts'));
        } finally {
            $db->delete('gf_content_rules', ['organization_id' => $tenant]);
        }
    }
}
