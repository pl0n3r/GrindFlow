<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

final readonly class InstagramReelConfiguration
{
    public function __construct(
        private string $organizationId,
        private string $instagramUserId,
        private string $accessToken,
        private string $graphVersion,
        private string $publishPermission,
        private bool $enabled = false,
        private string $vaultRoot = '',
    ) {}

    public function assertAvailableFor(string $organizationId): void
    {
        if (
            !$this->enabled
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->organizationId)
            || !preg_match('/^\d{1,32}$/D', $this->instagramUserId)
            || trim($this->accessToken) === ''
            || strlen($this->accessToken) > 16384
            || !preg_match('/^v\d{1,3}\.\d{1,3}$/D', $this->graphVersion)
            || !in_array($this->publishPermission, ['instagram_content_publish', 'instagram_business_content_publish'], true)
            || !hash_equals(strtolower($this->organizationId), strtolower($organizationId))
            || $this->vaultRoot === ''
            || !is_dir($this->vaultRoot)
        ) {
            throw DistributionProviderException::configuration();
        }
    }

    public function vaultRoot(): string
    {
        return $this->vaultRoot;
    }

    public function destinationId(): string
    {
        return $this->instagramUserId;
    }

    public function token(): string
    {
        return $this->accessToken;
    }

    public function graphVersion(): string
    {
        return $this->graphVersion;
    }
}
