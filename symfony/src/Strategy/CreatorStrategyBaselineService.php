<?php

declare(strict_types=1);

namespace GrindFlow\Strategy;

/**
 * Baseline offline y no vinculante. El caller debe autorizar la obtención de datos;
 * el servicio filtra de nuevo por tenant y nunca realiza I/O ni publica.
 */
final class CreatorStrategyBaselineService
{
    private const NETWORKS = ['instagram', 'tiktok', 'youtube', 'facebook', 'x'];

    /**
     * @param list<array<string,mixed>> $publications Entradas sintéticas/autorizadas.
     * @return array<string,mixed> Métricas agregadas sin IDs ni contenido privado.
     */
    public function summarize(string $tenantId, string $creatorId, array $publications): array
    {
        if ($tenantId === '' || strlen($tenantId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $tenantId)) {
            throw new \InvalidArgumentException('Tenant inválido.');
        }
        if ($creatorId === '' || strlen($creatorId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $creatorId)) {
            throw new \InvalidArgumentException('Creador inválido.');
        }
        if (!array_is_list($publications) || count($publications) > 10000) {
            throw new \InvalidArgumentException('Lote inválido.');
        }

        $seen = [];
        $groups = [];
        foreach ($publications as $publication) {
            if (!is_array($publication) || ($publication['tenant_id'] ?? null) !== $tenantId
                || ($publication['creator_id'] ?? null) !== $creatorId) {
                continue;
            }
            $id = $publication['id'] ?? null;
            $network = $publication['network'] ?? null;
            $format = $publication['format'] ?? null;
            $impressions = $publication['impressions'] ?? null;
            $interactions = $publication['interactions'] ?? null;
            if (!is_string($id) || $id === '' || strlen($id) > 128
                || !is_string($network) || !in_array($network, self::NETWORKS, true)
                || !is_string($format) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $format) !== 1
                || !is_int($impressions) || $impressions < 0
                || !is_int($interactions) || $interactions < 0) {
                throw new \InvalidArgumentException('Registro propio inválido.');
            }
            $signature = [$network, $format, $impressions, $interactions];
            $idKey = 'id:' . $id;
            if (isset($seen[$idKey])) {
                if ($seen[$idKey] !== $signature) {
                    throw new \InvalidArgumentException('Publicación duplicada inconsistente.');
                }
                continue;
            }
            $seen[$idKey] = $signature;
            $key = $network . "\0" . $format;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'network' => $network, 'format' => $format, 'posts' => 0,
                    'impressions' => 0, 'interactions' => 0,
                ];
            }
            if ($groups[$key]['impressions'] > PHP_INT_MAX - $impressions
                || $groups[$key]['interactions'] > PHP_INT_MAX - $interactions) {
                throw new \InvalidArgumentException('Métricas fuera de rango.');
            }
            ++$groups[$key]['posts'];
            $groups[$key]['impressions'] += $impressions;
            $groups[$key]['interactions'] += $interactions;
        }

        ksort($groups, SORT_STRING);
        $baseline = [];
        foreach ($groups as $group) {
            $group['interactions_per_1000_impressions'] = $group['impressions'] > 0
                ? round($group['interactions'] * 1000 / $group['impressions'], 2)
                : null;
            $baseline[] = $group;
        }
        $sampleCount = count($seen);
        $confidence = $sampleCount === 0 ? 'no_data'
            : ($sampleCount < 10 ? 'limited' : ($sampleCount < 30 ? 'tentative' : 'developing'));

        return [
            'baseline' => $baseline,
            'sample_count' => $sampleCount,
            'cold_start' => $sampleCount === 0,
            'confidence' => $confidence,
            'confidence_explanation' => 'Tramos orientativos por número de publicaciones propias; no son una prueba estadística.',
            'evidence_source' => 'creator_own_records',
            'next_action' => $sampleCount < 10 ? 'collect_more_evidence' : 'compare_patterns_as_experiment',
            'publication_authorized' => false,
        ];
    }
}
