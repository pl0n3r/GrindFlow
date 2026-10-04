<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
use GrindFlow\Scheduling\WeeklySlotCalculator;
use PHPUnit\Framework\TestCase;

final class ScheduleDraftApplicationServiceTest extends TestCase
{
    public function testRevokedOrganizationFailsBeforeDraftQueriesOrMutation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(
                static fn (\Closure $operation): array => $operation($db),
            );
        $db->expects(self::once())
            ->method('fetchOne')
            ->with(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => '00000000-0000-0000-0000-000000000001'],
            )
            ->willReturn(false);
        $db->expects(self::never())->method('fetchAssociative');
        $db->expects(self::never())->method('insert');

        $service = new ScheduleDraftApplicationService(
            $db,
            new WeeklySlotCalculator(),
        );

        self::assertSame(
            ['status' => 'revoked'],
            $service->reserve(
                '00000000-0000-0000-0000-000000000001',
                '00000000-0000-0000-0000-000000000002',
                '00000000-0000-0000-0000-000000000003',
                '2030-01-01T00:00:00Z',
            ),
        );
    }
}
