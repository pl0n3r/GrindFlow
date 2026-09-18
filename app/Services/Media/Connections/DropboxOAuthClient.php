<?php

namespace App\Services\Media\Connections;

use App\Services\Media\Connectors\MediaConnectorException;

class DropboxOAuthClient
{
    private const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';

    private const TOKEN_URL = 'https://api.dropbox.com/oauth2/token';

    public function __construct(
        private readonly OAuthTokenEndpointClient $tokens,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $appKey = $this->appKey();

        if ($redirectUri === '' || $state === '') {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $appKey,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'token_access_type' => 'offline',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(
        string $code,
        string $redirectUri,
    ): OAuthAuthorizationTokens {
        [$appKey, $appSecret] = $this->credentials();

        return $this->tokens->exchangeAuthorizationCode(
            self::TOKEN_URL,
            $appKey,
            $appSecret,
            $code,
            $redirectUri,
            null,
            'account_id',
        );
    }

    public function refresh(string $refreshToken): RefreshedAccessToken
    {
        [$appKey, $appSecret] = $this->credentials();

        return $this->tokens->refresh(
            self::TOKEN_URL,
            $appKey,
            $appSecret,
            $refreshToken,
        );
    }

    /**
     * @return array{string, string}
     */
    private function credentials(): array
    {
        $appKey = $this->appKey();
        $appSecret = (string) config('grindflow.connectors.dropbox.app_secret', '');

        if ($appSecret === '') {
            throw MediaConnectorException::oauthNotConfigured();
        }

        return [$appKey, $appSecret];
    }

    private function appKey(): string
    {
        $appKey = (string) config('grindflow.connectors.dropbox.app_key', '');

        if ($appKey === '') {
            throw MediaConnectorException::oauthNotConfigured();
        }

        return $appKey;
    }
}
