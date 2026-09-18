<?php

namespace App\Services\Media\Connections;

final readonly class RefreshedAccessToken
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public int $expiresInSeconds,
        public array $scopes = [],
    ) {}
}
