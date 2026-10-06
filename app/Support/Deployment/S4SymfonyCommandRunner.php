<?php

namespace App\Support\Deployment;

use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

final class S4SymfonyCommandRunner
{
    /** @var list<string> */
    public const FAILURE_CODES = [
        'runtime_unavailable',
        'process_failed',
        'output_invalid',
    ];

    public function __construct(
        private readonly ?string $symfonyRoot = null,
        private readonly ?string $phpBinary = null,
    ) {}

    /**
     * @param array<string, string> $environment
     * @param list<string> $sensitiveValues
     * @return array{ok:false,code:string}|array{ok:true,successful:bool,status:string,code:string}
     */
    public function run(
        string $command,
        array $environment = [],
        array $sensitiveValues = [],
    ): array {
        $root = $this->symfonyRoot ?? base_path('symfony');
        $php = $this->phpBinary ?? trim((string) config(
            'grindflow.s4_smoke.php_cli_binary',
            '',
        ));
        $console = $root.'/bin/console';

        if (
            $php === ''
            || ! is_file($php)
            || ! is_executable($php)
            || ! is_dir($root)
            || ! is_file($console)
            || ! is_readable($console)
        ) {
            return ['ok' => false, 'code' => 'runtime_unavailable'];
        }

        try {
            $process = new Process(
                [
                    $php,
                    $console,
                    $command,
                    '--env=prod',
                    '--no-interaction',
                    '--no-ansi',
                ],
                $root,
                $environment === [] ? null : $environment,
            );
            $process->setTimeout(30.0);
            $process->run();
        } catch (Throwable) {
            return ['ok' => false, 'code' => 'process_failed'];
        }

        $stdout = trim($process->getOutput());
        $stderr = $process->getErrorOutput();
        if ($stdout === '') {
            return ['ok' => false, 'code' => 'output_invalid'];
        }

        foreach ($sensitiveValues as $sensitiveValue) {
            if (
                $sensitiveValue !== ''
                && (
                    str_contains($stdout, $sensitiveValue)
                    || str_contains($stderr, $sensitiveValue)
                )
            ) {
                return ['ok' => false, 'code' => 'output_invalid'];
            }
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

        return [
            'ok' => true,
            'successful' => $process->isSuccessful(),
            'status' => $payload['status'],
            'code' => $payload['code'],
        ];
    }
}
