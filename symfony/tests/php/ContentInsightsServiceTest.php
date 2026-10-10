<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\ContentIntelligence\ContentInsightsService;
use PHPUnit\Framework\TestCase;

final class ContentInsightsServiceTest extends TestCase
{
    private ContentInsightsService $service;

    protected function setUp(): void
    {
        $this->service = new ContentInsightsService();
    }

    /** @return array<string,mixed> */
    private static function asset(string $tenant = 'tenant-a', string $name = 'campaña.jpg'): array
    {
        return [
            'id' => 'asset-1',
            'tenant_id' => $tenant,
            'original_name' => $name,
            'title' => 'Campaña de otoño',
            'description' => 'Producto azul',
            'content_tags' => ['Marca', 'Otoño'],
            'mime_type' => 'image/jpeg',
            'usage_scope' => 'internal_only',
            'campaign' => 'lanzamiento',
        ];
    }

    public function testSuggestionsRequireExplicitConfirmationAndStayEditable(): void
    {
        $asset = self::asset();
        $original = $asset;
        $suggestion = $this->service->suggestClassification('tenant-a', $asset);
        self::assertSame('image', $suggestion['category']);
        self::assertTrue($suggestion['requires_confirmation']);
        self::assertFalse($suggestion['applied']);
        self::assertFalse($suggestion['publication_authorized']);
        self::assertSame($original, $asset);
        self::assertSame($original, $this->service->confirmSuggestion('tenant-a', $asset, $suggestion, false));

        $proposal = ['category' => 'video', 'tags' => ['Editado', 'editado']];
        $updated = $this->service->confirmSuggestion('tenant-a', $asset, $proposal, true);
        self::assertSame('video', $updated['content_category']);
        self::assertSame(['editado'], $updated['content_tags']);
        self::assertSame($original, $asset, 'El método puro no persiste cambios.');
        self::assertArrayNotHasKey('publication_authorized', $updated);
    }

    public function testSearchIntersectsTextTagsMetadataAndTenant(): void
    {
        $a = self::asset();
        $b = self::asset('tenant-b');
        $b['id'] = 'private-foreign';
        $c = self::asset();
        $c['id'] = 'not-tagged';
        $c['content_tags'] = ['otra'];
        $d = self::asset();
        $d['id'] = 'different-mime';
        $d['mime_type'] = 'video/mp4';
        $result = $this->service->search('tenant-a', [$a, $b, $c, $d], 'otoño', ['marca'], [
            'mime_type' => 'image/jpeg', 'campaign' => 'lanzamiento',
        ]);
        self::assertSame(['asset-1'], $result);
        self::assertSame([], $this->service->search('tenant-b', [$a], 'campaña'));
        self::assertNotContains('private-foreign', $result);
    }

    public function testScoreIsDeterministicExplainablePerNetwork(): void
    {
        $a = self::asset();
        $signals = ['performance' => 80, 'fit' => 70, 'freshness' => 90, 'saturation' => 30];
        $first = $this->service->score('tenant-a', $a, 'instagram', $signals);
        $second = $this->service->score('tenant-a', $a, 'instagram', $signals);
        self::assertSame($first, $second);
        self::assertSame('instagram', $first['network']);
        self::assertSame($signals, $first['components']);
        self::assertSame(77, $first['base_score']);
        self::assertFalse($first['publication_authorized']);
        $other = $this->service->score('tenant-a', $a, 'facebook', [
            'performance' => 0, 'fit' => 0, 'freshness' => 0, 'saturation' => 100,
        ]);
        self::assertSame(0, $other['priority']);
    }

    public function testFatigueDropsPriorityWithoutExpiryAndRecovers(): void
    {
        $signals = ['performance' => 80, 'fit' => 70, 'freshness' => 90, 'saturation' => 30];
        $normal = $this->service->score('tenant-a', self::asset(), 'instagram', $signals);
        $tired = $this->service->score('tenant-a', self::asset(), 'instagram', $signals, 3, 0);
        $rested = $this->service->score('tenant-a', self::asset(), 'instagram', $signals, 3, 72);
        self::assertLessThan($normal['priority'], $tired['priority']);
        self::assertSame($normal['priority'], $rested['priority']);
        self::assertTrue($tired['rest_recommended']);
        self::assertFalse($rested['rest_recommended']);
        self::assertFalse($tired['expired']);
    }

    public function testOfflineFakeDeniesForeignTenantAndInvalidSignals(): void
    {
        $asset = self::asset('other-tenant');
        try {
            $this->service->suggestClassification('tenant-a', $asset);
            self::fail('No debe clasificar assets ajenos.');
        } catch (\DomainException $expected) {
            self::assertSame('Asset ajeno al tenant.', $expected->getMessage());
        }

        try {
            $this->service->score('tenant-a', $asset, 'instagram', [
                'performance' => 80, 'fit' => 70, 'freshness' => 90, 'saturation' => 30,
            ]);
            self::fail('No debe puntuar assets ajenos.');
        } catch (\DomainException $expected) {
            self::assertSame('Asset ajeno al tenant.', $expected->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service->score('tenant-a', self::asset(), 'instagram', [
            'performance' => 101, 'fit' => 70, 'freshness' => 90, 'saturation' => 30,
        ]);
    }
}
