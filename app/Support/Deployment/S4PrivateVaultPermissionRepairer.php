<?php

namespace App\Support\Deployment;

use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

class S4PrivateVaultPermissionRepairer
{
    /** @var list<string> */
    public const SUCCESS_CODES = ['tightened', 'already_private'];

    /** @var list<string> */
    public const PUBLIC_FAILURE_CODES = [
        'root_unavailable',
        'missing',
        'unreadable',
        'permissions_unavailable',
        'permissions_repair_failed',
    ];

    public function __construct(
        private readonly ?string $symfonyRoot = null,
        private readonly ?string $phpBinary = null,
    ) {}

    /** @return array{ok:bool,code:string} */
    public function repair(): array
    {
        $root = $this->symfonyRoot ?? base_path('symfony');
        $php = $this->phpBinary ?? trim((string) config('grindflow.s4_smoke.php_cli_binary', ''));
        $console = $root.'/bin/console';

        if (! $this->runtimeAvailable($root, $console, $php)) {
            return $this->failure('runtime_unavailable');
        }

        try {
            $process = new Process(
                [
                    $php,
                    $console,
                    'grindflow:s4:repair-private-vault-permissions',
                    '--env=prod',
                    '--no-interaction',
                    '--no-ansi',
                ],
                $root,
            );
            $process->setTimeout(30.0);
            $process->run();
        } catch (Throwable) {
            return $this->failure('process_failed');
        }

        $payload = $this->decodePayload($process);
        if ($payload === null) {
            return $this->failure('output_invalid');
        }

        $code = $payload['code'];
        if (
            $process->isSuccessful()
            && $payload['status'] === 'ok'
            && in_array($code, self::SUCCESS_CODES, true)
        ) {
            return ['ok' => true, 'code' => $code];
        }

        if (
            ! $process->isSuccessful()
            && $payload['status'] === 'error'
            && in_array($code, self::PUBLIC_FAILURE_CODES, true)
        ) {
            return $this->failure($code);
        }

        return $this->failure('output_invalid');
    }

    private function runtimeAvailable(string $root, string $console, string $php): bool
    {
        return $php !== ''
            && is_file($php)
            && is_executable($php)
            && is_dir($root)
            && is_file($console)
            && is_readable($console);
    }

    /** @return array{status:string,code:string}|null */
    private function decodePayload(Process $process): ?array
    {
        $output = trim($process->getOutput());
        if ($output === '') {
            return null;
        }

        try {
            $payload = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($payload)
            || array_keys($payload) !== ['status', 'code']
            || ! is_string($payload['status'])
            || ! is_string($payload['code'])
        ) {
            return null;
        }

        return [
            'status' => $payload['status'],
            'code' => $payload['code'],
        ];
    }

    /** @return array{ok:false,code:string} */
    private function failure(string $code): array
    {
        return ['ok' => false, 'code' => $code];
    }
}
