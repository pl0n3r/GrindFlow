<?php

declare(strict_types=1);

namespace GrindFlow\ContentIntelligence;

/**
 * Puerto para una sugerencia asistiva; no concede permisos ni publica.
 * Única implementación admitida en este slice: fake offline determinista.
 */
interface ContentSuggestionPort
{
    /** @return array{category:string,tags:list<string>,explanation:string} */
    public function suggest(string $filename): array;
}

final class OfflineSuggestionFake implements ContentSuggestionPort
{
    public function suggest(string $filename): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return ['category' => 'image', 'tags' => ['imagen'], 'explanation' => 'Extensión de imagen (fake offline)'];
        }
        if (in_array($extension, ['mp4', 'webm'], true)) {
            return ['category' => 'video', 'tags' => ['video'], 'explanation' => 'Extensión de video (fake offline)'];
        }

        return ['category' => 'unclassified', 'tags' => [], 'explanation' => 'Sin señal suficiente (fake offline)'];
    }
}

/**
 * Núcleo puro de Library: no DB, proveedor remoto, reloj del sistema ni I/O.
 * Los registros se suministran ya autorizados por el caller y se vuelven
 * a filtrar por tenant, sin suponer que un score habilita publicación.
 */
final class ContentInsightsService
{
    private readonly ContentSuggestionPort $suggestionPort;

    public function __construct()
    {
        $this->suggestionPort = new OfflineSuggestionFake();
    }

    /** @param array<string,mixed> $asset
     *  @return array{category:string,tags:list<string>,explanation:string,requires_confirmation:bool,applied:bool,publication_authorized:bool}
     */
    public function suggestClassification(string $tenantId, array $asset): array
    {
        $this->assertTenant($tenantId, $asset);
        $name = $asset['original_name'] ?? '';
        if (!is_string($name) || strlen($name) > 255) {
            throw new \InvalidArgumentException('Nombre de archivo inválido.');
        }
        $suggestion = $this->suggestionPort->suggest($name);

        return [...$suggestion,
            'requires_confirmation' => true,
            'applied' => false,
            'publication_authorized' => false,
        ];
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed> Copia: nunca persiste ni concede permisos.
     */
    public function confirmSuggestion(
        string $tenantId,
        array $asset,
        array $suggestion,
        bool $confirmed,
    ): array {
        $this->assertTenant($tenantId, $asset);
        if (!$confirmed) {
            return $asset;
        }
        $category = $suggestion['category'] ?? null;
        if (!is_string($category) || !in_array($category, ['image', 'video', 'unclassified'], true)) {
            throw new \InvalidArgumentException('Categoría sugerida inválida.');
        }
        $tags = $this->normalizeTags($suggestion['tags'] ?? []);
        $copy = $asset;
        $copy['content_category'] = $category;
        $copy['content_tags'] = $tags;
        return $copy;
    }

    /**
     * Intersección determinista de texto, etiquetas y metadata allowlisted.
     * @param list<array<string,mixed>> $assets
     * @param list<string> $requiredTags
     * @param array<string,string> $requiredMetadata
     * @return list<string> Solo IDs, sin bytes privados ni metadata ajena.
     */
    public function search(
        string $tenantId,
        array $assets,
        string $text = '',
        array $requiredTags = [],
        array $requiredMetadata = [],
    ): array {
        $this->assertTenantId($tenantId);
        $text = $this->foldSearchText(trim($text));
        if (strlen($text) > 80) {
            throw new \InvalidArgumentException('Búsqueda demasiado larga.');
        }
        $words = $text === '' ? [] : preg_split('/\s+/', $text);
        if (!is_array($words)) {
            throw new \InvalidArgumentException('Búsqueda inválida.');
        }
        $tags = $this->normalizeTags($requiredTags);
        $allowedMetadata = ['mime_type', 'usage_scope', 'campaign'];
        foreach ($requiredMetadata as $key => $value) {
            if (!in_array($key, $allowedMetadata, true) || !is_string($value) || strlen($value) > 80) {
                throw new \InvalidArgumentException('Filtro de metadata inválido.');
            }
        }

        $ids = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['tenant_id'] ?? null) !== $tenantId) {
                continue;
            }
            $id = $asset['id'] ?? null;
            if (!is_string($id) || $id === '' || strlen($id) > 128) {
                continue;
            }
            $originalTags = $this->normalizeTags($asset['content_tags'] ?? []);
            if (array_diff($tags, $originalTags) !== []) {
                continue;
            }
            $haystack = $this->foldSearchText(implode(' ', array_filter([
                is_string($asset['original_name'] ?? null) ? $asset['original_name'] : '',
                is_string($asset['title'] ?? null) ? $asset['title'] : '',
                is_string($asset['description'] ?? null) ? $asset['description'] : '',
                implode(' ', $originalTags),
            ], 'is_string')));
            if (array_filter($words, static fn (string $word): bool => !str_contains($haystack, $word)) !== []) {
                continue;
            }
            $matches = true;
            foreach ($requiredMetadata as $key => $value) {
                if (!isset($asset[$key]) || !is_string($asset[$key])
                    || $this->foldSearchText($asset[$key]) !== $this->foldSearchText($value)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $ids[$id] = true;
            }
        }
        $result = array_keys($ids);
        sort($result, SORT_STRING);
        return array_slice($result, 0, 100);
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,int> $signals performance,fit,freshness,saturation: 0..100.
     * @return array<string,mixed> Explicable, por red; jamás decisión editorial.
     */
    public function score(
        string $tenantId,
        array $asset,
        string $network,
        array $signals,
        int $recentUses = 0,
        int $restHours = 0,
    ): array {
        $this->assertTenant($tenantId, $asset);
        if (preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/D', $network) !== 1) {
            throw new \InvalidArgumentException('Red inválida.');
        }
        if ($recentUses < 0 || $restHours < 0) {
            throw new \InvalidArgumentException('Métricas de uso inválidas.');
        }
        $components = [];
        foreach (['performance', 'fit', 'freshness', 'saturation'] as $key) {
            $value = $signals[$key] ?? null;
            if (!is_int($value) || $value < 0 || $value > 100) {
                throw new \InvalidArgumentException('Componente de score inválido: '.$key);
            }
            $components[$key] = $value;
        }
        // El descanso reduce gradualmente la penalización, sin expiración.
        $fatigue = max(0, min(80, $recentUses * 20) - intdiv($restHours, 24) * 20);
        $base = intdiv(
            $components['performance'] * 35 + $components['fit'] * 35
            + $components['freshness'] * 20 + (100 - $components['saturation']) * 10,
            100,
        );

        return [
            'network' => $network,
            'components' => $components,
            'base_score' => $base,
            'fatigue_penalty' => $fatigue,
            'priority' => max(0, $base - $fatigue),
            'rest_recommended' => $fatigue > 0,
            'publication_authorized' => false,
        ];
    }

    /** @param array<string,mixed> $asset */
    private function assertTenant(string $tenantId, array $asset): void
    {
        $this->assertTenantId($tenantId);
        if (($asset['tenant_id'] ?? null) !== $tenantId) {
            throw new \DomainException('Asset ajeno al tenant.');
        }
    }

    private function assertTenantId(string $tenantId): void
    {
        if ($tenantId === '' || strlen($tenantId) > 128) {
            throw new \InvalidArgumentException('Tenant inválido.');
        }
    }

    /**
     * Case-fold acotado para búsqueda Library en castellano y latín habitual.
     * No requiere ext-mbstring; no cambia tildes ni normaliza grafías distintas.
     * Mantener consulta, tags, campos y filtros con exactamente la misma función.
     */
    private function foldSearchText(string $value): string
    {
        return strtolower(strtr($value, [
            'Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú',
            'Ü' => 'ü', 'Ñ' => 'ñ', 'À' => 'à', 'È' => 'è', 'Ì' => 'ì',
            'Ò' => 'ò', 'Ù' => 'ù', 'Â' => 'â', 'Ê' => 'ê', 'Î' => 'î',
            'Ô' => 'ô', 'Û' => 'û', 'Ä' => 'ä', 'Ë' => 'ë', 'Ï' => 'ï',
            'Ö' => 'ö', 'Ÿ' => 'ÿ', 'Ã' => 'ã', 'Õ' => 'õ', 'Ç' => 'ç',
        ]));
    }

    /** @return list<string> */
    private function normalizeTags(mixed $input): array
    {
        if (!is_array($input) || count($input) > 16) {
            throw new \InvalidArgumentException('Etiquetas inválidas.');
        }
        $tags = [];
        foreach ($input as $value) {
            if (!is_string($value) || strlen($value) > 40 || $value === '') {
                throw new \InvalidArgumentException('Etiqueta inválida.');
            }
            $tag = $this->foldSearchText(trim($value));
            if ($tag === '') {
                throw new \InvalidArgumentException('Etiqueta vacía.');
            }
            $tags[$tag] = true;
        }
        return array_keys($tags);
    }
}
