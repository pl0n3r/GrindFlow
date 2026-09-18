<?php

namespace App\Services\Media\Connections;

use App\Services\Media\Connectors\MediaConnectorException;

class GoogleOAuthClient
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const DRIVE_READONLY_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    public function __construct(
        private readonly OAuthTokenEndpointClient $tokens,
    ) {}

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $clientId = $this->clientId();

        if ($redirectUri === '' || $state === '') {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => self::DRIVE_READONLY_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(
        string $code,
        string $redirectUri,
    ): OAuthAuthorizationTokens {
        [$clientId, $clientSecret] = $this->credentials();

        return $this->tokens->exchangeAuthorizationCode(
            self::TOKEN_URL,
            $clientId,
            $clientSecret,
            $code,
            $redirectUri,
            self::DRIVE_READONLY_SCOPE,
        );
    }

    public function refresh(string $refreshToken): RefreshedAccessToken
    {
        [$clientId, $clientSecret] = $this->credentials();

        return $this->tokens->refresh(
            self::TOKEN_URL,
            $clientId,
            $clientSecret,
            $refreshToken,
        );
    }

    /**
     * @return array{string, string}
     */
    private function credentials(): array
    {
        $clientId = $this->clientId();
        $clientSecret = (string) config(
            'grindflow.connectors.google_drive.client_secret',
            '',
        );

        if ($clientSecret === '') {
            throw MediaConnectorException::oauthNotConfigured();
        }

        return [$clientId, $clientSecret];
    }

    private function clientId(): string
    {
        $clientId = (string) config(
            'grindflow.connectors.google_drive.client_id',
            '',
        );

        if ($clientId === '') {
            throw MediaConnectorException::oauthNotConfigured();
        }

        return $clientId;
    }
}
