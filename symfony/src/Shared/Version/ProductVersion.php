<?php

declare(strict_types=1);

namespace GrindFlow\Shared\Version;

final readonly class ProductVersion
{
    public function __construct(private string $repositoryDir)
    {
    }

    public function human(): string
    {
        $file = $this->repositoryDir.'/config/version.php';
        if (!is_file($file)) {
            return 'unavailable';
        }
        $release = require $file;

        return is_array($release) && is_string($release['number'] ?? null)
            && preg_match('/^\d+\.\d+\.\d+$/D', $release['number']) === 1
            ? $release['number']
            : 'unavailable';
    }

    public function releaseSha(): ?string
    {
        $file = $this->repositoryDir.'/.release-sha';
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $sha = trim((string) file_get_contents($file));

        return preg_match('/^[0-9a-f]{40}$/D', $sha) === 1 ? $sha : null;
    }
}
