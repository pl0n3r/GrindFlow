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
}
