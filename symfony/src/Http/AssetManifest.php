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
        $path = $this->projectDir.'/public/build/.vite/manifest.json';
        if (!is_file($path)) {
            throw new ServiceUnavailableHttpException(null, 'La vista previa todavía no está compilada.');
        }

        $json = json_decode((string) file_get_contents($path), true);
        $entry = is_array($json) ? ($json['frontend/admin/main.tsx'] ?? null) : null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || !$this->isAllowedFile($entry['file'], 'js')) {
            throw new ServiceUnavailableHttpException(null, 'El manifiesto de la vista previa es inválido.');
        }

        $css = $entry['css'] ?? [];
        if (!is_array($css)) {
            throw new ServiceUnavailableHttpException(null, 'El manifiesto de estilos es inválido.');
        }

        foreach ($css as $file) {
            if (!is_string($file) || !$this->isAllowedFile($file, 'css')) {
                throw new ServiceUnavailableHttpException(null, 'El manifiesto de estilos es inválido.');
            }
        }

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
