<?php

declare(strict_types=1);

namespace GrindFlow\Http;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final readonly class AssetManifest
{
    public function __construct(private string $projectDir)
    {
    }

    /** @return array{js: string, css: list<string>} */
    public function preview(): array
    {
        return $this->entry('frontend/admin/main.tsx');
    }

    /** @return array{js: string, css: list<string>} */
    public function admin(): array
    {
        return $this->entry('frontend/admin/admin.tsx');
    }

    /** @return array{js: string, css: list<string>} */
    private function entry(string $source): array
    {
        $path = $this->projectDir.'/public/build/.vite/manifest.json';
        if (!is_file($path)) {
            throw new ServiceUnavailableHttpException(null, 'La vista previa todavía no está compilada.');
        }

        $json = json_decode((string) file_get_contents($path), true);
        $entry = is_array($json) ? ($json[$source] ?? null) : null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || !$this->isAllowedFile($entry['file'], 'js')) {
            throw new ServiceUnavailableHttpException(null, 'El manifiesto de la vista previa es inválido.');
        }

        $css = $entry['css'] ?? [];
        if (!is_array($css)) {
            throw new ServiceUnavailableHttpException(null, 'El manifiesto de estilos es inválido.');
        }

        // With multiple Vite entries, shared CSS can be emitted as its own
        // manifest asset instead of living in either entry's css array.
        if ($css === []) {
            foreach ($json as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $file = $asset['file'] ?? null;
                if (is_string($file) && $this->isAllowedFile($file, 'css')) {
                    $css[] = $file;
                }
                foreach ($asset['css'] ?? [] as $style) {
                    $css[] = $style;
                }
            }
        }

        if ($css === []) {
            throw new ServiceUnavailableHttpException(null, 'La hoja de estilos aún no está compilada.');
        }

        foreach ($css as $file) {
            if (!is_string($file) || !$this->isAllowedFile($file, 'css')) {
                throw new ServiceUnavailableHttpException(null, 'El manifiesto de estilos es inválido.');
            }
        }

        $css = array_values(array_unique($css));

        return [
            'js' => '/build/'.$entry['file'],
            'css' => array_map(static fn (string $file): string => '/build/'.$file, $css),
        ];
    }

    private function isAllowedFile(string $file, string $extension): bool
    {
        return preg_match('/\Aassets\/[A-Za-z0-9._-]+\.'.preg_quote($extension, '/').'\z/D', $file) === 1;
    }
}
