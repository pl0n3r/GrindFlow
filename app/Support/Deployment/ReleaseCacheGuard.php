<?php

namespace App\Support\Deployment;

use RuntimeException;

class ReleaseCacheGuard
{
    /**
     * Files whose contents determine whether Laravel's cached bootstrap state
     * must be invalidated after a Git-only deployment.
     *
     * @var list<string>
     */
    private const FINGERPRINT_PATHS = [
        'bootstrap/app.php',
        'composer.lock',
        'routes/console.php',
        'routes/web.php',
    ];

    /**
     * Clear stale Laravel caches once when deployment-sensitive source changes.
     */
    public function refreshIfNeeded(
        ?string $basePathOverride = null,
        ?string $storagePathOverride = null,
    ): bool {
        $basePath = $basePathOverride ?? base_path();
        $storagePath = $storagePathOverride ?? storage_path();
        $storageFramework = $this->join($storagePath, 'framework');

        if (
            is_dir($storageFramework) === false
            && mkdir($storageFramework, 0775, true) === false
            && is_dir($storageFramework) === false
        ) {
            throw new RuntimeException('Unable to create Laravel framework storage directory.');
        }

        $lockPath = $this->join($storageFramework, 'grindflow-release.lock');
        $markerPath = $this->join($storageFramework, 'grindflow-release.sha256');

        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException('Unable to open deployment cache lock.');
        }

        try {
            if (flock($lock, LOCK_EX) === false) {
                throw new RuntimeException('Unable to acquire deployment cache lock.');
            }

            $fingerprint = $this->fingerprint($basePath);
            $current = is_file($markerPath)
                ? trim((string) file_get_contents($markerPath))
                : null;

            if ($current === $fingerprint) {
                return false;
            }

            $this->clearBootstrapCaches($basePath);
            $this->clearCompiledViews($storagePath);

            $temporaryMarker = $markerPath.'.tmp';

            if (file_put_contents($temporaryMarker, $fingerprint.PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write deployment cache marker.');
            }

            if (rename($temporaryMarker, $markerPath) === false) {
                if (is_file($temporaryMarker)) {
                    unlink($temporaryMarker);
                }

                throw new RuntimeException('Unable to publish deployment cache marker.');
            }

            clearstatcache();

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function fingerprint(string $basePath): string
    {
        $hash = hash_init('sha256');

        foreach (self::FINGERPRINT_PATHS as $relativePath) {
            $path = $this->join($basePath, $relativePath);

            hash_update($hash, $relativePath."\0");

            if (is_file($path) === false) {
                hash_update($hash, '[missing]');

                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Unable to read deployment fingerprint source: {$relativePath}");
            }

            hash_update($hash, $contents);
        }

        foreach (glob($this->join($basePath, 'config/*.php')) ?: [] as $configPath) {
            hash_update($hash, basename($configPath)."\0");

            $contents = file_get_contents($configPath);

            if ($contents === false) {
                throw new RuntimeException('Unable to read a configuration file for deployment fingerprinting.');
            }

            hash_update($hash, $contents);
        }

        return hash_final($hash);
    }

    private function clearBootstrapCaches(string $basePath): void
    {
        foreach (glob($this->join($basePath, 'bootstrap/cache/*.php')) ?: [] as $path) {
            if (is_file($path) && unlink($path) === false) {
                throw new RuntimeException('Unable to clear a Laravel bootstrap cache file.');
            }
        }
    }

    private function clearCompiledViews(string $storagePath): void
    {
        foreach (glob($this->join($storagePath, 'framework/views/*.php')) ?: [] as $path) {
            if (is_file($path) && unlink($path) === false) {
                throw new RuntimeException('Unable to clear a compiled Laravel view.');
            }
        }
    }

    private function join(string $base, string $path): string
    {
        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }
}
