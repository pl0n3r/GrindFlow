<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

final readonly class FacebookPageConfiguration
{
    public function __construct(
        private string $organizationId,
        private string $pageId,
        private string $accessToken,
        private string $graphVersion,
    ) {}

    public function assertAvailableFor(string $organizationId): void
    {
        if (
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->organizationId)
            || !preg_match('/^\d{1,32}$/', $this->pageId)
            || trim($this->accessToken) === ''
            || strlen($this->accessToken) > 16384
            || !preg_match('/^v\d{1,3}\.\d{1,3}$/', $this->graphVersion)
        ) {
            throw DistributionProviderException::configuration();
        }

        if (!hash_equals(strtolower($this->organizationId), strtolower($organizationId))) {
            throw DistributionProviderException::configuration();
        }
    }

    public function pageId(): string
    {
        return $this->pageId;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function graphVersion(): string
    {
        return $this->graphVersion;
    }
}
