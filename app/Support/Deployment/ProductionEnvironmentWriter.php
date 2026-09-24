<?php

namespace App\Support\Deployment;

use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductionEnvironmentWriter
{
    /**
     * @param  Closure(): void  $afterPersist
     */
    public function withSmokePassword(
        string $password,
        Closure $afterPersist,
        ?string $environmentPath = null,
    ): void {
        if (
            $password === ''
            || strlen($password) > 4096
            || preg_match('/[\x00\r\n]/', $password) === 1
        ) {
            throw new RuntimeException('Synthetic smoke password format is invalid.');
        }

        $path = $environmentPath ?? base_path('.env');

        if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('Production environment file is unavailable.');
        }

        $lockPath = $path.'.grindflow.lock';
        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException('Unable to open production environment lock.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock production environment.');
            }

            $original = file_get_contents($path);

            if ($original === false) {
                throw new RuntimeException('Unable to read production environment.');
            }

            $updated = $this->upsert(
                $original,
                'SMOKE_USER_PASSWORD',
                $this->quoted($password),
            );
            $updated = $this->upsert(
                $updated,
                'CACHE_STORE',
                $this->quoted('file'),
            );
            $changed = ! hash_equals($original, $updated);

            if ($changed) {
                $this->backup($original);
                $this->atomicWrite($path, $updated);
            }

            try {
                $afterPersist();
            } catch (Throwable $exception) {
                if ($changed) {
                    $this->atomicWrite($path, $original);
                }

                throw $exception;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function upsert(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            $updated = preg_replace_callback(
                $pattern,
                static fn (): string => $line,
                $contents,
                1,
            );

            if (! is_string($updated)) {
                throw new RuntimeException('Unable to update production environment.');
            }

            return $updated;
        }

        return rtrim($contents)."\n".$line."\n";
    }

    private function quoted(string $value): string
    {
        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
        ]).'"';
    }

    private function backup(string $contents): void
    {
        $encrypted = Crypt::encryptString($contents);
        $relativePath = 'operations/environment-backups/'
            .now('UTC')->format('Ymd\\THis\\Z')
            .'-'.Str::uuid().'.env.enc';

        $disk = Storage::disk('local');

        if ($disk->put($relativePath, $encrypted) !== true) {
            throw new RuntimeException('Unable to write production environment backup.');
        }

        if (@chmod($disk->path($relativePath), 0600) === false) {
            $disk->delete($relativePath);

            throw new RuntimeException('Unable to secure production environment backup.');
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.grindflow-env-');

        if ($temporary === false) {
            throw new RuntimeException('Unable to stage production environment update.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write staged production environment.');
            }

            if (@chmod($temporary, 0600) === false) {
                throw new RuntimeException('Unable to secure staged production environment.');
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish production environment update.');
            }

            clearstatcache(true, $path);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
