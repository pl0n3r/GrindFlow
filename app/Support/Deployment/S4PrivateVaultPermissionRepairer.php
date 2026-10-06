<?php

namespace App\Support\Deployment;

use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

class S4PrivateVaultPermissionRepairer
{
    /** @var list<string> */
    public const SUCCESS_CODES = [
        'tightened',
        'already_private',
    ];

    /** @var list<string> */
    public const PUBLIC_FAILURE_CODES = [
        'root_unavailable',
        'missing',
        'unreadable',
        'permissions_unavailable',
        'permissions_repair_failed',
    ];

    /** @var list<string> */
    private const TRANSPORT_FAILURE_CODES = [
        'runtime_unavailable',
        'process_failed',
        'output_invalid',
    ];

    public function __construct(
        private readonly ?string $symfonyRoot = null,
        private readonly ?string $phpBinary = null,
    ) {}

    /** @return array{ok:bool,code:string} */
    public function repair(): array
    {
        $root = $this->symfonyRoot ?? base_path('symfony');
        $console = $root.'/bin/console';
        $php = $this->phpBinary ?? trim((string) config(
            'grindflow.s4_smoke.php_cli_binary',
            '',
        ));

        if (
            $php === ''
            || ! is_file($php)
            || ! is_executable($php)
            || ! is_file($console)
            || ! is_readable($console)
            || ! is_dir($root)
        ) {
            return ['ok' => false, 'code' => 'runtime_unavailable'];
        }

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

        try {
            $process->run();
        } catch (Throwable) {
            return ['ok' => false, 'code' => 'process_failed'];
        }

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            return ['ok' => false, 'code' => 'output_invalid'];
        }

        try {
            $payload = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ok' => false, 'code' => 'output_invalid'];
        }

        if (
            ! is_array($payload)
            || count($payload) !== 2
            || ! array_key_exists('status', $payload)
            || ! array_key_exists('code', $payload)
            || ! is_string($payload['status'])
            || ! is_string($payload['code'])
        ) {
            return ['ok' => false, 'code' => 'output_invalid'];
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
            return ['ok' => false, 'code' => $code];
        }

        return ['ok' => false, 'code' => 'output_invalid'];
    }
}
