<?php

namespace App\Support\Deployment;

class S4SmokeIdentityProvisioner
{
    /** @var list<string> */
    public const SUCCESS_CODES = [
        'created',
        'already_ready',
        'rotated',
        'role_upgraded',
        'role_upgraded_rotated',
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

    private readonly S4SymfonyCommandRunner $runner;

    public function __construct(
        ?string $symfonyRoot = null,
        ?string $phpBinary = null,
        ?S4SymfonyCommandRunner $runner = null,
    ) {
        $this->runner = $runner ?? new S4SymfonyCommandRunner($symfonyRoot, $phpBinary);
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

        $result = $this->runner->run(
            'grindflow:s4:provision-smoke-identity',
            ['GRINDFLOW_S4_SMOKE_PASSWORD' => $password],
            [$password],
        );

        if ($result['ok'] !== true) {
            return [
                'ok' => false,
                'code' => match ($result['code']) {
                    'runtime_unavailable' => 's4-runtime-unavailable',
                    'process_failed' => 's4-process-failed',
                    default => 's4-output-invalid',
                },
            ];
        }

        $code = $result['code'];
        if (
            $result['successful']
            && $result['status'] === 'ok'
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
            ! $result['successful']
            && $result['status'] === 'error'
            && is_string($mapped)
        ) {
            return ['ok' => false, 'code' => $mapped];
        }

        return ['ok' => false, 'code' => 's4-output-invalid'];
    }
}
