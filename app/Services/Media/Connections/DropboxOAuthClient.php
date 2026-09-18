<?php

namespace App\Services\Media\Connections;

use App\Services\Media\Connectors\MediaConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DropboxOAuthClient
{
    private const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';

    private const TOKEN_URL = 'https://api.dropbox.com/oauth2/token';

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
    ): DropboxAuthorizationTokens {
        [$appKey, $appSecret] = $this->credentials();

        if ($code === '' || $redirectUri === '') {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $appKey,
                    'client_secret' => $appSecret,
                ]);
        } catch (ConnectionException) {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        $this->assertAuthorizationSuccessful($response);

        $payload = $response->json();

        if (is_array($payload) === false) {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (
            is_string($accessToken) === false
            || $accessToken === ''
            || is_string($refreshToken) === false
            || $refreshToken === ''
            || is_numeric($expiresIn) === false
        ) {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        $accountIdentifier = $payload['account_id'] ?? null;

        return new DropboxAuthorizationTokens(
            $accessToken,
            $refreshToken,
            $this->boundedExpiry($expiresIn),
            $this->scopes($payload['scope'] ?? ''),
            is_string($accountIdentifier) && $accountIdentifier !== ''
                ? $accountIdentifier
                : null,
        );
    }

    public function refresh(string $refreshToken): RefreshedAccessToken
    {
        [$appKey, $appSecret] = $this->credentials();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $appKey,
                    'client_secret' => $appSecret,
                ]);
        } catch (ConnectionException) {
            throw MediaConnectorException::requestFailed();
        }

        $this->assertRefreshSuccessful($response);

        $payload = $response->json();

        if (is_array($payload) === false) {
            throw MediaConnectorException::requestFailed();
        }

        $accessToken = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (
            is_string($accessToken) === false
            || $accessToken === ''
            || is_numeric($expiresIn) === false
        ) {
            throw MediaConnectorException::requestFailed();
        }

        return new RefreshedAccessToken(
            $accessToken,
            $this->boundedExpiry($expiresIn),
            $this->scopes($payload['scope'] ?? ''),
        );
    }

    private function assertAuthorizationSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            throw MediaConnectorException::rateLimited(
                $this->retryAfterSeconds($response),
            );
        }

        throw MediaConnectorException::oauthExchangeFailed();
    }

    private function assertRefreshSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            throw MediaConnectorException::rateLimited(
                $this->retryAfterSeconds($response),
            );
        }

        $payload = $response->json();
        $error = is_array($payload) && is_string($payload['error'] ?? null)
            ? $payload['error']
            : null;

        if ($response->status() === 400 && $error === 'invalid_grant') {
            throw MediaConnectorException::refreshRejected();
        }

        throw MediaConnectorException::requestFailed();
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

    /**
     * @return list<string>
     */
    private function scopes(mixed $scope): array
    {
        if (is_string($scope) === false) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', trim($scope)) ?: [],
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function boundedExpiry(mixed $expiresIn): int
    {
        if (is_numeric($expiresIn) === false) {
            throw MediaConnectorException::requestFailed();
        }

        return max(60, min((int) $expiresIn, 86_400));
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '' || ctype_digit($header) === false) {
            return null;
        }

        return max(60, min((int) $header, 3600));
    }
}
