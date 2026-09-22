<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Runtime;

final class RuntimeReadiness
{
    private const MINIMUM_PHP_VERSION_ID = 80300;
    private const MAXIMUM_PHP_VERSION_ID = 90000;
    private const CONTRACT = 'symfony-mariadb-v1';

    /**
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'ctype',
        'iconv',
        'pdo',
        'pdo_mysql',
    ];

    /**
     * Public readiness must stay coarse-grained: callers learn whether the
     * runtime satisfies the contract, never the exact PHP version, SAPI or
     * installed extension inventory.
     *
     * @return array{compatible: bool, contract: string}
     */
    public function publicSummary(): array
    {
        return [
            'compatible' => $this->isCompatible(),
            'contract' => self::CONTRACT,
        ];
    }

    public function isCompatible(): bool
    {
        if (PHP_VERSION_ID < self::MINIMUM_PHP_VERSION_ID || PHP_VERSION_ID >= self::MAXIMUM_PHP_VERSION_ID) {
            return false;
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                return false;
            }
        }

        return true;
    }
}
