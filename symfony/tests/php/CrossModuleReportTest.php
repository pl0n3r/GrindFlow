<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use GrindFlow\Http\Reports\CrossModuleReportController;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class CrossModuleReportTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCrossModuleCountsExcludeForeignTenantsAndOrphanedDeliveryKeys(): void
    {
        static::bootKernel();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        [$mine, $foreign] = $this->seed($db);

        $mineReport = CrossModuleReportController::report($db, $mine, '2026-10-01', '2026-10-08');
        self::assertTrue($mineReport['ready']);
        self::assertSame(
            ['assets' => 1, 'scheduled' => 1, 'delivery_attempts' => 2, 'published' => 1],
            $mineReport['totals'],
        );
        self::assertFalse($mineReport['provider_calls']);
        self::assertArrayNotHasKey('tenant_id', $mineReport);
        self::assertStringNotContainsString($foreign, json_encode($mineReport, JSON_THROW_ON_ERROR));

        $foreignReport = CrossModuleReportController::report($db, $foreign, '2026-10-01', '2026-10-08');
        self::assertTrue($foreignReport['ready']);
        self::assertSame(
            ['assets' => 1, 'scheduled' => 1, 'delivery_attempts' => 1, 'published' => 1],
            $foreignReport['totals'],
        );
        self::assertNotSame($mineReport['totals'], $foreignReport['totals']);
    }

    public function testEmptyPeriodIsZeroNotAnErrorAndUpperBoundIsExclusive(): void
    {
        static::bootKernel();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        [$mine] = $this->seed($db);

        $report = CrossModuleReportController::report($db, $mine, '2026-10-08', '2026-10-09');
        self::assertTrue($report['ready']);
        self::assertSame(
            ['assets' => 0, 'scheduled' => 0, 'delivery_attempts' => 0, 'published' => 0],
            $report['totals'],
        );
        self::assertSame(
            ['from_utc' => '2026-10-08', 'to_exclusive_utc' => '2026-10-09'],
            $report['period'],
        );
    }

    public function testInvalidTenantOrUnboundedPeriodFailsBeforeReadingAnyRows(): void
    {
        /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchOne');
        foreach ([
            ['', '2026-10-01', '2026-10-08'],
            ['tenant-a', '2026-02-30', '2026-03-01'],
            ['tenant-a', '2026-10-08', '2026-10-01'],
            ['tenant-a', '2026-10-01', '2026-10-01'],
            ['tenant-a', '2026-10-01', '2026-11-02'],
            ['tenant-a', '2026-10-01T00:00:00', '2026-10-08'],
        ] as [$tenant, $from, $to]) {
            try {
                CrossModuleReportController::report($db, $tenant, $from, $to);
                self::fail('The report must reject an invalid tenant or period.');
            } catch (\InvalidArgumentException) {
                // Expected: invalid input never widens the tenant/date scope.
            }
        }
    }

    public function testDataSourceErrorNeverMasqueradesAsEmptyReport(): void
    {
        /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willThrowException(new DbalException('synthetic query failure'));

        $report = CrossModuleReportController::report($db, 'tenant-a', '2026-10-01', '2026-10-08');
        self::assertFalse($report['ready']);
        self::assertNull($report['totals']);
        self::assertSame('report_source_unavailable', $report['reason']);
        self::assertFalse($report['provider_calls']);
        self::assertStringNotContainsString('synthetic query failure', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @return array{string, string} */
    private function seed(Connection $db): array
    {
        $user = Uuid::v7()->toRfc4122();
        $mine = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $mineAsset = Uuid::v7()->toRfc4122();
        $foreignAsset = Uuid::v7()->toRfc4122();
        $mineDraft = Uuid::v7()->toRfc4122();
        $historicalDraft = Uuid::v7()->toRfc4122();
        $foreignDraft = Uuid::v7()->toRfc4122();
        $now = '2026-10-02 12:00:00';

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Test sintético',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('synthetic-password-only', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([$mine, $foreign] as $org) {
            $db->insert('gf_identity_organizations', [
                'id' => $org,
                'name' => 'Organización sintética',
                'slug' => 'report-'.substr($org, 0, 26),
                'type' => 'independent',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([[$mine, $mineAsset], [$foreign, $foreignAsset]] as [$org, $asset]) {
            $db->insert('gf_vault_assets', [
                'id' => $asset,
                'organization_id' => $org,
                'uploaded_by' => $user,
                'original_name' => 'synthetic.png',
                'mime_type' => 'image/png',
                'size_bytes' => 10,
                'sha256' => hash('sha256', $asset),
                'storage_key' => $asset,
                'usage_scope' => 'needs_review',
                'created_at' => $now,
            ]);
        }
        foreach ([
            [$mine, $mineDraft, $mineAsset, '2026-10-03 10:00:00'],
            [$mine, $historicalDraft, $mineAsset, '2026-09-30 10:00:00'],
            [$foreign, $foreignDraft, $foreignAsset, '2026-10-03 10:00:00'],
        ] as [$org, $draft, $asset, $scheduled]) {
            $db->insert('gf_schedule_drafts', [
                'id' => $draft,
                'organization_id' => $org,
                'asset_id' => $asset,
                'created_by' => $user,
                'scheduled_at_utc' => $scheduled,
                'timezone' => 'UTC',
                'local_date' => substr($scheduled, 0, 10),
                'local_time' => '10:00',
                'status' => 'draft',
                'delivery_provider' => 'facebook_page',
                'delivery_destination_id' => 'synthetic-destination',
                'created_at' => $now,
            ]);
        }

        foreach ([
            [$mine, $mineDraft, 'published'],
            [$mine, $historicalDraft, 'rejected'],
            [$foreign, $foreignDraft, 'published'],
            // Own-tenant event pointing to another tenant's draft must not count.
            [$mine, $foreignDraft, 'ambiguous'],
        ] as [$org, $draft, $status]) {
            $db->insert('gf_external_publication_attempts', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $org,
                'provider' => 'facebook_page',
                'idempotency_key' => 'schedule-draft:'.$draft.':facebook_page:v1',
                'request_fingerprint' => hash('sha256', $org.$draft),
                'status' => $status,
                'external_publication_id' => $status === 'published' ? 'synthetic-publication-'.$draft : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [$mine, $foreign];
    }
}
