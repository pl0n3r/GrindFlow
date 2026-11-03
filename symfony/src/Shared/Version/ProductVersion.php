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

        // Read one byte beyond the longest permitted marker (40 hex + CRLF).
        // Reject extra content, arbitrary whitespace and non-regular paths.
        $contents = @file_get_contents($file, false, null, 0, 43);
        if (!is_string($contents) ||
            preg_match('/\A([a-fA-F0-9]{40})(?:\r?\n)?\z/D', $contents, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }
}
