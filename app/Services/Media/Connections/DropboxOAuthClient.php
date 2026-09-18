<?php

namespace App\Services\Media\Connections;

use App\Services\Media\Connectors\MediaConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DropboxOAuthClient
{
    private const TOKEN_URL = 'https://api.dropbox.com/oauth2/token';

    public function refresh(string $refreshToken): RefreshedAccessToken
    {
        $appKey = (string) config('grindflow.connectors.dropbox.app_key', '');
        $appSecret = (string) config('grindflow.connectors.dropbox.app_secret', '');

        if ($appKey === '' || $appSecret === '') {
            throw MediaConnectorException::oauthNotConfigured();
        }

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

        $this->assertSuccessful($response);

        $payload = $response->json();

        if (is_array($payload) === false) {
            throw MediaConnectorException::requestFailed();
        }

        $accessToken = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? 14_400;

        if (
            is_string($accessToken) === false
            || $accessToken === ''
            || is_numeric($expiresIn) === false
        ) {
            throw MediaConnectorException::requestFailed();
        }

        $expiresInSeconds = max(60, min((int) $expiresIn, 86_400));
        $scope = $payload['scope'] ?? '';
        $scopes = is_string($scope)
            ? array_values(array_filter(
                preg_split('/\s+/', trim($scope)) ?: [],
                static fn (string $value): bool => $value !== '',
            ))
            : [];

        return new RefreshedAccessToken(
            $accessToken,
            $expiresInSeconds,
            $scopes,
        );
    }

    private function assertSuccessful(Response $response): void
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

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if (is_string($header) === false || ctype_digit($header) === false) {
            return null;
        }

        return max(60, min((int) $header, 3600));
    }
}
