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

    public function __construct(
        private readonly ?string $basePathOverride = null,
        private readonly ?string $storagePathOverride = null,
    ) {
    }

    /**
     * Clear stale Laravel caches once when deployment-sensitive source changes.
     */
    public function refreshIfNeeded(): bool
    {
        $storageFramework = $this->storagePath('framework');

        if (is_dir($storageFramework) === false && mkdir($storageFramework, 0775, true) === false && is_dir($storageFramework) === false) {
            throw new RuntimeException('Unable to create Laravel framework storage directory.');
        }

        $lockPath = $storageFramework.'/grindflow-release.lock';
        $markerPath = $storageFramework.'/grindflow-release.sha256';

        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException('Unable to open deployment cache lock.');
        }

        try {
            if (flock($lock, LOCK_EX) === false) {
                throw new RuntimeException('Unable to acquire deployment cache lock.');
            }

            $fingerprint = $this->fingerprint();
            $current = is_file($markerPath)
                ? trim((string) file_get_contents($markerPath))
                : null;

            if ($current === $fingerprint) {
                return false;
            }

            $this->clearBootstrapCaches();
            $this->clearCompiledViews();

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

    private function fingerprint(): string
    {
        $hash = hash_init('sha256');

        foreach (self::FINGERPRINT_PATHS as $relativePath) {
            $path = $this->basePath($relativePath);

            hash_update($hash, $relativePath."\0");

            if (! is_file($path)) {
                hash_update($hash, '[missing]');
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Unable to read deployment fingerprint source: {$relativePath}");
            }

            hash_update($hash, $contents);
        }

        foreach (glob($this->basePath('config/*.php')) ?: [] as $configPath) {
            hash_update($hash, basename($configPath)."\0");

            $contents = file_get_contents($configPath);

            if ($contents === false) {
                throw new RuntimeException('Unable to read a configuration file for deployment fingerprinting.');
            }

            hash_update($hash, $contents);
        }

        return hash_final($hash);
    }

    private function clearBootstrapCaches(): void
    {
        foreach (glob($this->basePath('bootstrap/cache/*.php')) ?: [] as $path) {
            if (is_file($path) && unlink($path) === false) {
                throw new RuntimeException('Unable to clear a Laravel bootstrap cache file.');
            }
        }
    }

    private function clearCompiledViews(): void
    {
        foreach (glob($this->storagePath('framework/views/*.php')) ?: [] as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Unable to clear a compiled Laravel view.');
            }
        }
    }

    private function basePath(string $path = ''): string
    {
        $base = $this->basePathOverride ?? base_path();

        return $path === ''
            ? $base
            : rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    private function storagePath(string $path = ''): string
    {
        $base = $this->storagePathOverride ?? storage_path();

        return $path === ''
            ? $base
            : rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }
}
