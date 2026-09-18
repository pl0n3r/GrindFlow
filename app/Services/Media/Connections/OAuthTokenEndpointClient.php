<?php

namespace App\Services\Media\Connections;

use App\Services\Media\Connectors\MediaConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OAuthTokenEndpointClient
{
    public function exchangeAuthorizationCode(
        string $tokenUrl,
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        ?string $defaultScope = null,
        ?string $accountIdentifierField = null,
    ): OAuthAuthorizationTokens {
        if ($code === '' || $redirectUri === '') {
            throw MediaConnectorException::oauthExchangeFailed();
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($tokenUrl, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
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

        $scope = $payload['scope'] ?? $defaultScope ?? '';
        $accountIdentifier = $accountIdentifierField === null
            ? null
            : ($payload[$accountIdentifierField] ?? null);

        return new OAuthAuthorizationTokens(
            $accessToken,
            $refreshToken,
            $this->boundedExpiry($expiresIn),
            $this->scopes($scope),
            is_string($accountIdentifier) && $accountIdentifier !== ''
                ? $accountIdentifier
                : null,
        );
    }

    public function refresh(
        string $tokenUrl,
        string $clientId,
        string $clientSecret,
        string $refreshToken,
    ): RefreshedAccessToken {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($tokenUrl, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
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
