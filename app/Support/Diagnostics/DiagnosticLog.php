<?php

namespace App\Support\Diagnostics;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DiagnosticLog
{
    private const MAX_FILE_BYTES = 2_000_000;

    private const MESSAGE_LIMIT = 1500;

    /**
     * Record a sanitized server-side failure and return its incident ID.
     */
    public function record(Throwable $exception, ?Request $request = null): string
    {
        $incidentId = (string) Str::uuid();

        if ($request instanceof Request) {
            $request->attributes->set('incident_id', $incidentId);
        }

        $entry = [
            'timestamp' => now()->utc()->toIso8601String(),
            'incident_id' => $incidentId,
            'status' => $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500,
            'exception' => $exception::class,
            'message' => $this->sanitize($exception->getMessage()),
            'location' => $this->relativeLocation($exception->getFile(), $exception->getLine()),
            'request' => $this->requestContext($request),
            'trace' => $this->trace($exception),
        ];

        try {
            $path = $this->currentPath();
            $this->rotateIfNeeded($path);

            $encoded = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            file_put_contents($path, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $loggingFailure) {
            error_log(sprintf(
                'GrindFlow diagnostic logging failed for incident %s: %s',
                $incidentId,
                $loggingFailure->getMessage(),
            ));
        }

        return $incidentId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $paths = glob(storage_path('logs/diagnostics-*.jsonl*')) ?: [];

        usort(
            $paths,
            fn (string $left, string $right): int => (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0),
        );

        $entries = [];

        foreach ($paths as $path) {
            foreach ($this->lastLines($path, $limit - count($entries)) as $line) {
                try {
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                    if (is_array($decoded)) {
                        $entries[] = $decoded;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            if (count($entries) >= $limit) {
                break;
            }
        }

        usort(
            $entries,
            fn (array $left, array $right): int => strcmp(
                (string) ($right['timestamp'] ?? ''),
                (string) ($left['timestamp'] ?? ''),
            ),
        );

        return array_slice($entries, 0, $limit);
    }

    private function currentPath(): string
    {
        $directory = storage_path('logs');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory.'/diagnostics-'.now()->utc()->format('Y-m-d').'.jsonl';
    }

    private function rotateIfNeeded(string $path): void
    {
        if (! is_file($path) || (filesize($path) ?: 0) < self::MAX_FILE_BYTES) {
            return;
        }

        rename($path, $path.'.'.now()->utc()->format('His'));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(?Request $request): array
    {
        if (! $request instanceof Request) {
            return ['source' => 'console'];
        }

        $user = $request->user();
        $tenantContext = app(TenantContext::class);

        return [
            'source' => 'http',
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'user_id' => is_object($user) && method_exists($user, 'getAuthIdentifier')
                ? (string) $user->getAuthIdentifier()
                : null,
            'organization_id' => $tenantContext->organizationId(),
        ];
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    private function trace(Throwable $exception): array
    {
        $frames = [];

        foreach (array_slice($exception->getTrace(), 0, 18) as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : null;
            $line = isset($frame['line']) ? (int) $frame['line'] : null;
            $call = trim(
                (string) ($frame['class'] ?? '')
                .(string) ($frame['type'] ?? '')
                .(string) ($frame['function'] ?? ''),
            );

            $frames[] = [
                'file' => $file !== null ? $this->relativePath($file) : null,
                'line' => $line,
                'call' => $call !== '' ? $call : null,
            ];
        }

        return $frames;
    }

    private function relativeLocation(string $file, int $line): string
    {
        return $this->relativePath($file).':'.$line;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $base)) {
            return '[app]/'.str_replace('\\', '/', substr($path, strlen($base)));
        }

        return basename($path);
    }

    private function sanitize(string $message): string
    {
        $message = str_replace(base_path(), '[app]', $message);
        $message = preg_replace(
            '/\b(mysql|mariadb|postgres(?:ql)?):\/\/[^@\s]+@/i',
            '$1://[redacted]@',
            $message,
        ) ?? $message;
        $message = preg_replace(
            '/\b(password|passwd|secret|token|api[_-]?key|authorization)(\s*[=:]\s*)([^\s,;]+)/i',
            '$1$2[redacted]',
            $message,
        ) ?? $message;

        return Str::limit($message, self::MESSAGE_LIMIT, '...');
    }

    /**
     * @return array<int, string>
     */
    private function lastLines(string $path, int $limit): array
    {
        if ($limit <= 0 || ! is_readable($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $lines = [];

        for ($line = $lastLine; $line >= 0 && count($lines) < $limit; $line--) {
            $file->seek($line);
            $value = trim((string) $file->current());

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines;
    }
}
