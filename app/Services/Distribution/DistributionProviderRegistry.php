<?php

namespace App\Services\Distribution;

use App\Contracts\DistributionProvider;
use InvalidArgumentException;

class DistributionProviderRegistry
{
    /**
     * @var array<string, DistributionProvider>
     */
    private array $providers = [];

    public function register(
        string $provider,
        DistributionProvider $implementation,
    ): void {
        $provider = trim($provider);

        if ($provider === '') {
            throw new InvalidArgumentException(
                'Distribution provider name cannot be empty.',
            );
        }

        $this->providers[$provider] = $implementation;
    }

    public function resolve(string $provider): ?DistributionProvider
    {
        return $this->providers[$provider] ?? null;
    }
}
