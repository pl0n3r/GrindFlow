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
     * Public readiness stays coarse-grained: callers learn whether the runtime
     * satisfies the contract, never its exact PHP version, SAPI or extension
     * inventory.
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
        return $this->supports(PHP_VERSION_ID, get_loaded_extensions());
    }

    /**
     * Pure contract evaluation keeps incompatible runtimes testable without
     * altering the process environment.
     *
     * @param list<string> $loadedExtensions
     */
    public function supports(int $phpVersionId, array $loadedExtensions): bool
    {
        if ($phpVersionId < self::MINIMUM_PHP_VERSION_ID || $phpVersionId >= self::MAXIMUM_PHP_VERSION_ID) {
            return false;
        }

        $loaded = array_fill_keys(array_map('strtolower', $loadedExtensions), true);
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!isset($loaded[$extension])) {
                return false;
            }
        }

        return true;
    }
}
