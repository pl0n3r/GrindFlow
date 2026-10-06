<?php

namespace App\Support\Deployment;

use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

class S4SmokeIdentityProvisioner
{
    /** @var list<string> */
    public const SUCCESS_CODES = [
        'created',
        'already_ready',
        'rotated',
    ];

    /** @var list<string> */
    public const FAILURE_CODES = [
        's4-password-invalid',
        's4-runtime-unavailable',
        's4-process-failed',
        's4-output-invalid',
        's4-secret-missing',
        's4-schema-missing',
        's4-identity-conflict',
        's4-transaction-failed',
    ];

    public function __construct(
        private readonly ?string $symfonyRoot = null,
        private readonly ?string $phpBinary = null,
    ) {
    }

    /** @return array{ok:bool,code:string} */
    public function reconcile(string $password): array
    {
        if (
            $password === ''
            || strlen($password) > 4096
            || preg_match('/[\x00\r\n]/', $password) === 1
        ) {
            return ['ok' => false, 'code' => 's4-password-invalid'];
        }

        $root = $this->symfonyRoot ?? base_path('symfony');
        $console = $root.'/bin/console';
        $php = $this->phpBinary ?? PHP_BINARY;

        if (
            $php === ''
            || ! is_file($console)
            || ! is_readable($console)
            || ! is_dir($root)
        ) {
            return ['ok' => false, 'code' => 's4-runtime-unavailable'];
        }

        $process = new Process(
            [
                $php,
                $console,
                'grindflow:s4:provision-smoke-identity',
                '--env=prod',
                '--no-interaction',
                '--no-ansi',
            ],
            $root,
            ['GRINDFLOW_S4_SMOKE_PASSWORD' => $password],
        );
        $process->setTimeout(30.0);

        try {
            $process->run();
        } catch (Throwable) {
            return ['ok' => false, 'code' => 's4-process-failed'];
        }

        $stdout = trim($process->getOutput());
        $stderr = $process->getErrorOutput();

        // Child output is untrusted and never leaves this class. A secret echo
        // invalidates the result without reflecting the payload to logs/HTTP.
        if (
            $stdout === ''
            || str_contains($stdout, $password)
            || str_contains($stderr, $password)
        ) {
            return ['ok' => false, 'code' => 's4-output-invalid'];
        }

        try {
            $payload = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ok' => false, 'code' => 's4-output-invalid'];
        }

        if (
            ! is_array($payload)
            || count($payload) !== 2
            || ! array_key_exists('status', $payload)
            || ! array_key_exists('code', $payload)
            || ! is_string($payload['status'])
            || ! is_string($payload['code'])
        ) {
            return ['ok' => false, 'code' => 's4-output-invalid'];
        }

        $code = $payload['code'];
        if (
            $process->isSuccessful()
            && $payload['status'] === 'ok'
            && in_array($code, self::SUCCESS_CODES, true)
        ) {
            return ['ok' => true, 'code' => $code];
        }

        $failureMap = [
            'secret_missing' => 's4-secret-missing',
            'schema_missing' => 's4-schema-missing',
            'identity_conflict' => 's4-identity-conflict',
            'transaction_failed' => 's4-transaction-failed',
        ];
        $mapped = $failureMap[$code] ?? null;

        if (
            ! $process->isSuccessful()
            && $payload['status'] === 'error'
            && is_string($mapped)
            && in_array($mapped, self::FAILURE_CODES, true)
        ) {
            return ['ok' => false, 'code' => $mapped];
        }

        return ['ok' => false, 'code' => 's4-output-invalid'];
    }
}
