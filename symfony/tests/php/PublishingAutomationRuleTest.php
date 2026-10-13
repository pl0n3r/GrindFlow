<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Scheduling\WeeklySlotCalculator;
use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Scheduling\PublishingAutomationRuleScheduler;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
use GrindFlow\Distribution\FacebookPageConfiguration;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPageTransport;
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

    /**
     * Regresión (CodeRabbit #455): más de 100 assets antiguos bloqueados no deben
     * impedir que un asset elegible más nuevo se reserve.
     */
    public function testEligibleAssetBeyondBlockedFirstPageIsStillReserved(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $organization = Uuid::v7()->toRfc4122();
        $user = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $old = gmdate('Y-m-d H:i:s', time() - 86400 * 30);
        $eligible = Uuid::v7()->toRfc4122();

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'Automatización', 'email' => $user.'@example.test',
            'password_hash' => 'x', 'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $db->insert('gf_identity_organizations', [
            'id' => $organization, 'name' => 'Cola larga', 'slug' => 'cola-'.substr($organization, 0, 20),
            'type' => 'independent', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user, 'organization_id' => $organization,
            'role' => 'admin', 'created_at' => $at, 'updated_at' => $at,
        ]);
        // 105 assets antiguos sin clasificar (bloqueados) y uno elegible y más nuevo.
        for ($i = 0; $i < 105; ++$i) {
            $id = Uuid::v7()->toRfc4122();
            $db->insert('gf_vault_assets', [
                'id' => $id, 'organization_id' => $organization, 'uploaded_by' => $user,
                'original_name' => $id.'.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
                'sha256' => hash('sha256', $id), 'storage_key' => $id,
                'usage_scope' => 'unclassified', 'created_at' => $old,
            ]);
        }
        $db->insert('gf_vault_assets', [
            'id' => $eligible, 'organization_id' => $organization, 'uploaded_by' => $user,
            'original_name' => 'elegible.png', 'mime_type' => 'image/png', 'size_bytes' => 10,
            'sha256' => hash('sha256', $eligible), 'storage_key' => $eligible,
            'usage_scope' => 'needs_review', 'created_at' => $at,
        ]);
        $db->insert('gf_content_review_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $organization, 'asset_id' => $eligible,
            'actor_id' => $user, 'action' => 'approve', 'created_at' => $at,
        ]);
        $db->insert('gf_distribution_authorization_events', [
            'id' => Uuid::v7()->toRfc4122(), 'organization_id' => $organization, 'asset_id' => $eligible,
            'actor_id' => $user, 'action' => 'grant', 'created_at' => $at,
        ]);
        $db->insert('gf_content_rules', [
            'organization_id' => $organization, 'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun', 'local_time' => '10:00', 'max_per_day' => 1,
            'mode' => 'review_only', 'updated_by' => $user, 'created_at' => $at, 'updated_at' => $at,
            'automation_state' => 'active', 'automation_local_times' => '["10:00"]',
            'automation_destination_id' => '1234567890',
        ]);

        $transport = new class implements FacebookPageTransport {
            public function postFeed(string $graphVersion, string $pageId, string $accessToken, array $payload): array
            {
                throw new \RuntimeException('La automatización local nunca publica.');
            }

            public function postPhoto(string $graphVersion, string $pageId, string $accessToken, string $caption, string $filePath, string $mimeType): array
            {
                throw new \RuntimeException('La automatización local nunca publica.');
            }
        };
        $provider = new FacebookPageProvider(
            new FacebookPageConfiguration($organization, '1234567890', 'token-sintetico', 'v26.0'),
            $transport,
        );
        $scheduler = new PublishingAutomationRuleScheduler(
            $db,
            new WeeklySlotCalculator(),
            new ScheduleDraftApplicationService($db, new WeeklySlotCalculator()),
            $provider,
        );

        // Las tablas de eventos son append-only; las pruebas usan una BD desechable
        // y organizaciones con UUID únicos, igual que el resto de la suite.
        $result = $scheduler->materialize($organization, true);
        self::assertNotSame('selection_incomplete', $result['status']);
        self::assertGreaterThanOrEqual(1, $result['created']);
        self::assertSame(
            $eligible,
            $db->fetchOne('SELECT asset_id FROM gf_schedule_drafts WHERE organization_id = :o ORDER BY scheduled_at_utc LIMIT 1', ['o' => $organization]),
        );
    }
}
