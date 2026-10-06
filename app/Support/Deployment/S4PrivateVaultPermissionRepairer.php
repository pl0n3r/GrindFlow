<?php

namespace App\Support\Deployment;

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

    private readonly S4SymfonyCommandRunner $runner;

    public function __construct(
        ?string $symfonyRoot = null,
        ?string $phpBinary = null,
        ?S4SymfonyCommandRunner $runner = null,
    ) {
        $this->runner = $runner ?? new S4SymfonyCommandRunner($symfonyRoot, $phpBinary);
    }

    /** @return array{ok:bool,code:string} */
    public function repair(): array
    {
        $result = $this->runner->run('grindflow:s4:repair-private-vault-permissions');

        if ($result['ok'] !== true) {
            return $this->failure($result['code']);
        }

        $code = $result['code'];
        if (
            $result['successful']
            && $result['status'] === 'ok'
            && in_array($code, self::SUCCESS_CODES, true)
        ) {
            return ['ok' => true, 'code' => $code];
        }

        if (
            ! $result['successful']
            && $result['status'] === 'error'
            && in_array($code, self::PUBLIC_FAILURE_CODES, true)
        ) {
            return $this->failure($code);
        }

        return $this->failure('output_invalid');
    }

    /** @return array{ok:false,code:string} */
    private function failure(string $code): array
    {
        return ['ok' => false, 'code' => $code];
    }
}
