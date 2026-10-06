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

    public function __construct(
        private readonly ?string $symfonyRoot = null,
        private readonly ?string $phpBinary = null,
    ) {}

    /** @return array{ok:bool,code:string} */
    public function repair(): array
    {
        $result = (new S4SymfonyCommandRunner($this->symfonyRoot, $this->phpBinary))->run(
            'grindflow:s4:repair-private-vault-permissions',
        );

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
