<?php

namespace App\Services\Media\Connections;

final readonly class OAuthAuthorizationTokens
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresInSeconds,
        public array $scopes = [],
        public ?string $accountIdentifier = null,
    ) {}
}
