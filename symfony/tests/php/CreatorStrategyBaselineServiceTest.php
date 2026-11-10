<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Strategy\CreatorStrategyBaselineService;
use PHPUnit\Framework\TestCase;

final class CreatorStrategyBaselineServiceTest extends TestCase
{
    private static function post(string $id, string $tenant = 'a', string $network = 'instagram', string $format = 'short_video'): array
    {
        return [
            'id' => $id, 'tenant_id' => $tenant, 'network' => $network,
            'format' => $format, 'impressions' => 100, 'interactions' => 7,
            'private_note' => 'NO EXPOSE',
        ];
    }

    public function testTenantIsolationAndPureInput(): void
    {
        $rows = [self::post('same'), self::post('foreign', 'b')];
        $before = $rows;
        $result = (new CreatorStrategyBaselineService())->summarize('a', $rows);
        self::assertSame($before, $rows);
        self::assertSame(1, $result['sample_count']);
        self::assertCount(1, $result['baseline']);
        self::assertFalse($result['publication_authorized']);
        self::assertStringNotContainsString('NO EXPOSE', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(0, (new CreatorStrategyBaselineService())->summarize('b', [self::post('other')])['sample_count']);
    }

    public function testDeterministicGroupingAndDuplicateConflicts(): void
    {
        $service = new CreatorStrategyBaselineService();
        $rows = [self::post('b', 'a', 'x', 'image'), self::post('a'), self::post('a')];
        $result = $service->summarize('a', $rows);
        self::assertSame(2, $result['sample_count']);
        self::assertSame(['instagram', 'x'], array_column($result['baseline'], 'network'));
        self::assertSame(70.0, $result['baseline'][0]['interactions_per_1000_impressions']);
        $conflict = [...$rows, [...self::post('a'), 'interactions' => 8]];
        $this->expectException(\InvalidArgumentException::class);
        $service->summarize('a', $conflict);
    }

    public function testColdStartConfidenceAndNoPublication(): void
    {
        $service = new CreatorStrategyBaselineService();
        $empty = $service->summarize('a', []);
        self::assertTrue($empty['cold_start']);
        self::assertSame('no_data', $empty['confidence']);
        self::assertSame('collect_more_evidence', $empty['next_action']);
        self::assertFalse($empty['publication_authorized']);
        $rows = [];
        for ($i = 0; $i < 30; ++$i) {
            $rows[] = self::post('id-'.$i);
        }
        self::assertSame('limited', $service->summarize('a', array_slice($rows, 0, 9))['confidence']);
        self::assertSame('tentative', $service->summarize('a', array_slice($rows, 0, 10))['confidence']);
        self::assertSame('developing', $service->summarize('a', $rows)['confidence']);
        self::assertFalse($service->summarize('a', $rows)['publication_authorized']);
    }

    public function testInvalidOwnedSignalsFailClosed(): void
    {
        $service = new CreatorStrategyBaselineService();
        $this->expectException(\InvalidArgumentException::class);
        $service->summarize('a', [[...self::post('id'), 'interactions' => -1]]);
    }
}
