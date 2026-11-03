<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Backblaze B2 S3 configuration loaded exclusively from operator-owned files.
 *
 * A missing, unsafe or incomplete source disables direct upload. Never log
 * exceptions with values, and do not infer readiness from this configuration.
 */
final class PrivateMediaS3Config
{
    private const MAX_FILE_BYTES = 4096;
    private const FILE_SUFFIXES = [
        'MEDIA_STORAGE_ENDPOINT_FILE' => 'endpoint',
        'MEDIA_STORAGE_BUCKET_FILE' => 'bucket',
        'MEDIA_STORAGE_REGION_FILE' => 'region',
        'MEDIA_STORAGE_ACCESS_KEY_ID_FILE' => 'accessKeyId',
        'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE' => 'secretAccessKey',
    ];

    // No instance properties containing B2 endpoint, bucket or credentials:
    // __debugInfo() is NOT consulted by var_export() or serialize().
    // A WeakMap keeps private runtime material out of ordinary object dumps,
    // and entries disappear when their configuration object is collected.
    /** @var \WeakMap<self,array{endpoint:string,bucket:string,region:string,accessKeyId:string,secretAccessKey:string}>|null */
    private static ?\WeakMap $privateValues = null;

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $pathVariables Only explicit *_FILE paths are read.
     * Missing or unsafe data returns null; no AWS/R2 fallback, network or logging.
     */
    public static function fromPathVariables(array $pathVariables): ?self
    {
        $values = [];
        foreach (self::FILE_SUFFIXES as $variable => $field) {
            $path = $pathVariables[$variable] ?? null;
            if (!is_string($path) || $path === '') {
                return null;
            }
            $value = self::readPrivateFile($path);
            if ($value === null) {
                return null;
            }
            $values[$field] = $value;
        }

        $region = $values['region'];
        if (preg_match('/\A(?:us|eu|ap|ca|au|sa)-[a-z0-9-]+-\d{3}\z/D', $region) !== 1) {
            return null;
        }
        // A fixed B2 S3 endpoint avoids userinfo, alternate ports, URL paths,
        // cloud-metadata targets and other SSRF surfaces.
        if ($values['endpoint'] !== 'https://s3.'.$region.'.backblazeb2.com') {
            return null;
        }
        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])\z/D', $values['bucket']) !== 1
            || preg_match('/\A[A-Za-z0-9]{12,128}\z/D', $values['accessKeyId']) !== 1
            || preg_match('/\A[A-Za-z0-9+\/=:_-]{20,256}\z/D', $values['secretAccessKey']) !== 1) {
            return null;
        }

        $config = new self();
        self::$privateValues ??= new \WeakMap();
        self::$privateValues[$config] = $values;
        return $config;
    }

    /** @return array{endpoint:string,bucket:string,region:string,accessKeyId:string,secretAccessKey:string} */
    public function clientOptions(): array
    {
        // Explicit server-only call. No value is an instance property and a
        // cloned/unserialized config is unconfigured (fail closed).
        $values = self::$privateValues[$this] ?? null;
        if (!is_array($values)) {
            throw new \RuntimeException('Private media storage configuration is unavailable.');
        }
        return $values;
    }

    /** Keep secrets out of exception/debug object dumps. */
    public function __debugInfo(): array
    {
        return ['provider' => 'backblaze-b2', 'configured' => self::$privateValues !== null && isset(self::$privateValues[$this])];
    }

    private static function readPrivateFile(string $path): ?string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")
            || str_contains($path, '\\') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D', $path) === 1
            || str_contains($path, '//') || str_ends_with($path, '/')) {
            return null;
        }
        // PHP has no portable openat(O_NOFOLLOW) walk. Never open a secret
        // through an ancestor that an unrelated user can rename: a writable
        // ancestor enables a symlink-swap TOCTOU even with lstat/fstat on
        // the final file. Root-owned sticky directories (e.g. /tmp) are the
        // sole exception: sticky bit prevents other UIDs from renaming the
        // operator-owned private child. Same-UID processes remain trusted.
        $directories = ['/'];
        $cursor = '';
        foreach (explode('/', ltrim(dirname($path), '/')) as $part) {
            if ($part === '') {
                return null;
            }
            $cursor .= '/'.$part;
            $directories[] = $cursor;
        }
        $parents = [];
        foreach ($directories as $directory) {
            clearstatcache(true, $directory);
            $entry = @lstat($directory);
            if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0040000) {
                return null;
            }
            $worldOrGroupWritable = ($entry['mode'] & 0022) !== 0;
            $rootOwnedSticky = $entry['uid'] === 0 && ($entry['mode'] & 01000) !== 0;
            if ($worldOrGroupWritable && !$rootOwnedSticky) {
                return null;
            }
            $parents[$directory] = $entry;
        }
        // The immediate directory is private even when a trusted ancestor
        // permits traversal. Its owner must be the owner of the secret.
        $directory = dirname($path);
        $directoryStat = $parents[$directory];
        if (($directoryStat['mode'] & 0077) !== 0) {
            return null;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0077) !== 0
            || $stat['size'] < 1 || $stat['size'] > self::MAX_FILE_BYTES + 1
            || $directoryStat['uid'] !== $stat['uid']) {
            return null;
        }
        // An attacker-owned ancestor can rename the child even without
        // group/world write on the directory itself. Restrict all ancestors
        // to the secret owner or trusted root.
        foreach ($parents as $entry) {
            if ($entry['uid'] !== 0 && $entry['uid'] !== $stat['uid']) {
                return null;
            }
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || $opened['ino'] !== $stat['ino']
                || $opened['dev'] !== $stat['dev'] || ($opened['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0077) !== 0) {
                return null;
            }
            // Detect any ancestor replacement after opening. This is a
            // defense-in-depth check, not a substitute for non-mutable parents.
            foreach ($parents as $directory => $before) {
                clearstatcache(true, $directory);
                $after = @lstat($directory);
                if (!is_array($after) || $after['ino'] !== $before['ino']
                    || $after['dev'] !== $before['dev']
                    || ($after['mode'] & 0170000) !== 0040000) {
                    return null;
                }
            }
            $value = @fread($handle, self::MAX_FILE_BYTES + 2);
            if (!is_string($value) || $value === '' || strlen($value) > self::MAX_FILE_BYTES + 1) {
                return null;
            }
            // Only one conventional final LF/CRLF is permitted, not embedded
            // newlines, control characters, or whitespace trimming of secrets.
            if (str_ends_with($value, "\r\n")) {
                $value = substr($value, 0, -2);
            } elseif (str_ends_with($value, "\n")) {
                $value = substr($value, 0, -1);
            }
            if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                return null;
            }
            return $value;
        } finally {
            fclose($handle);
        }
    }
}
