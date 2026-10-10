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

    public function testNumericTagsRetainStringsAfterConfirmation(): void
    {
        $asset = self::asset();
        $proposal = ['category' => 'image', 'tags' => ['2026', '42', '0', '2026', 'MÚSICA']];
        $confirmed = $this->service->confirmSuggestion('tenant-a', $asset, $proposal, true);
        self::assertSame(['2026', '42', '0', 'música'], $confirmed['content_tags']);
        self::assertSame(
            '["2026","42","0","música"]',
            json_encode($confirmed['content_tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        self::assertSame(['Marca', 'Otoño'], $asset['content_tags'], 'No mutar el original.');
        self::assertSame(['asset-1'], $this->service->search('tenant-a', [$confirmed], '', ['2026']));

        try {
            $this->service->confirmSuggestion('tenant-a', $asset, [
                'category' => 'image', 'tags' => [2026],
            ], true);
            self::fail('No se admiten etiquetas numéricas sin tipo string.');
        } catch (\InvalidArgumentException $expected) {
            self::assertSame('Etiqueta inválida.', $expected->getMessage());
        }
    }

    public function testTagListsRejectAssociativeShapeAndKeepStringIds(): void
    {
        $asset = self::asset();
        $baseline = $asset;
        $valid = $this->service->confirmSuggestion('tenant-a', $asset, [
            'category' => 'image', 'tags' => ['2026', '42'],
        ], true);
        self::assertSame(['2026', '42'], $valid['content_tags']);
        $empty = $this->service->confirmSuggestion('tenant-a', $asset, [
            'category' => 'image', 'tags' => [],
        ], true);
        self::assertSame([], $empty['content_tags']);
        self::assertSame($baseline, $asset, 'La confirmación no muta el original.');

        foreach ([
            fn () => $this->service->confirmSuggestion('tenant-a', $asset, [
                'category' => 'image', 'tags' => ['unexpected' => 'marca'],
            ], true),
            fn () => $this->service->searchPage('tenant-a', [$asset], '', [
                'unexpected' => 'marca',
            ]),
            fn () => $this->service->searchPage('tenant-a', [
                [...$asset, 'content_tags' => ['unexpected' => 'marca']],
            ]),
        ] as $case) {
            try {
                $case();
                self::fail('La lista asociativa no puede pasar el contrato.');
            } catch (\InvalidArgumentException $expected) {
                self::assertSame('Etiquetas inválidas.', $expected->getMessage());
            }
        }
    }

    public function testNetworkSlugXAndInvalidIdentifiers(): void
    {
        $signals = ['performance' => 80, 'fit' => 70, 'freshness' => 90, 'saturation' => 30];
        $asset = self::asset();
        $x = $this->service->score('tenant-a', $asset, 'x', $signals);
        self::assertSame('x', $x['network']);
        self::assertSame(77, $x['base_score']);
        self::assertFalse($x['publication_authorized']);
        foreach (['', 'X', 'x/', str_repeat('z', 33)] as $invalid) {
            try {
                $this->service->score('tenant-a', $asset, $invalid, $signals);
                self::fail('El slug inválido no puede puntuarse.');
            } catch (\InvalidArgumentException $expected) {
                self::assertSame('Red inválida.', $expected->getMessage());
            }
        }
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

    public function testSearchUnicodeCasefoldPreservesTenantIsolation(): void
    {
        $mine = self::asset('tenant-a', 'CAMPAÑA.JPG');
        $mine['title'] = 'EXHIBICIÓN';
        $mine['content_tags'] = ['MÚSICA'];
        $mine['campaign'] = 'MÚSICA';
        $foreign = $mine;
        $foreign['id'] = 'private-foreign';
        $foreign['tenant_id'] = 'tenant-b';

        self::assertSame(['asset-1'], $this->service->search(
            'tenant-a', [$mine, $foreign], 'campaña', ['música'], ['campaign' => 'música']
        ));
        self::assertSame(['asset-1'], $this->service->search(
            'tenant-a', [self::asset('tenant-a', 'campaña.jpg')], 'CAMPAÑA'
        ));
        self::assertSame([], $this->service->search(
            'tenant-b', [$mine], 'campaña', ['música']
        ));
    }

    public function testSearchPagesBeyondOneHundredAndRejectsUnrelatedCursor(): void
    {
        $assets = [];
        for ($number = 101; $number >= 1; --$number) {
            $asset = self::asset();
            $asset['id'] = sprintf('asset-%03d', $number);
            $assets[] = $asset;
        }
        $foreign = self::asset('tenant-b');
        $foreign['id'] = 'foreign-999';
        $assets[] = $foreign;

        $first = $this->service->searchPage('tenant-a', $assets, 'otoño', ['marca']);
        self::assertCount(100, $first['ids']);
        self::assertSame('asset-001', $first['ids'][0]);
        self::assertSame('asset-100', $first['ids'][99]);
        self::assertTrue($first['has_more']);
        self::assertSame('asset-100', $first['next_cursor']);
        self::assertSame($first['ids'], $this->service->search('tenant-a', $assets, 'otoño', ['marca']));

        $second = $this->service->searchPage(
            'tenant-a', $assets, 'otoño', ['marca'], [], $first['next_cursor']
        );
        self::assertSame(['asset-101'], $second['ids']);
        self::assertFalse($second['has_more']);
        self::assertNull($second['next_cursor']);
        self::assertCount(101, array_unique([...$first['ids'], ...$second['ids']]));
        self::assertNotContains('foreign-999', [...$first['ids'], ...$second['ids']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->searchPage('tenant-a', $assets, 'otoño', ['marca'], [], 'foreign-999');
    }

    public function testNumericAssetIdsRemainStringsInSearchPage(): void
    {
        $asset = self::asset();
        $asset['id'] = '42';
        $page = $this->service->searchPage('tenant-a', [$asset], '', ['marca']);
        self::assertSame(['42'], $page['ids']);
        self::assertSame('["42"]', json_encode($page['ids'], JSON_THROW_ON_ERROR));
        self::assertFalse($page['has_more']);
        self::assertNull($page['next_cursor']);
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
        // Un metric desconocido no puede ser ignorado silenciosamente.
        $shuffled = array_reverse($signals, true);
        self::assertSame($first, $this->service->score('tenant-a', $a, 'instagram', $shuffled));
        foreach ([
            [...$signals, 'extra_metric' => 100],
            ['performance' => 80, 'fit' => 70, 'freshness' => 90],
        ] as $invalidSignals) {
            try {
                $this->service->score('tenant-a', $a, 'instagram', $invalidSignals);
                self::fail('Score debe rechazar métricas adicionales o ausentes.');
            } catch (\InvalidArgumentException $expected) {
                self::assertSame('Componentes de score inválidos.', $expected->getMessage());
            }
        }
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
        self::assertArrayNotHasKey('expired', $tired);
        $expiredAsset = self::asset();
        $expiredAsset['expired'] = true;
        $expiredScore = $this->service->score('tenant-a', $expiredAsset, 'instagram', $signals, 3, 0);
        self::assertArrayNotHasKey('expired', $expiredScore);
        self::assertTrue($expiredAsset['expired'], 'La puntuación no debe alterar la vigencia real.');
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
