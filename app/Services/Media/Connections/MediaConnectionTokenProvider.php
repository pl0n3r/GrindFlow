<?php

namespace App\Services\Media\Connections;

use App\Models\MediaConnection;
use App\Models\User;
use App\Services\Media\Connectors\MediaConnectorException;
use App\Support\Security\SecretCipher;

class MediaConnectionTokenProvider
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly DropboxOAuthClient $dropboxOAuth,
        private readonly GoogleOAuthClient $googleOAuth,
        private readonly MediaConnectionManager $connections,
    ) {}

    public function accessToken(
        MediaConnection $connection,
        User $actor,
    ): string {
        $this->assertSupportedProvider($connection);

        $accessCiphertext = $connection->access_ciphertext;

        if ($accessCiphertext === '') {
            throw MediaConnectorException::refreshUnavailable();
        }

        $context = $connection->cryptoContext();
        $accessToken = $this->cipher->decrypt($accessCiphertext, $context);

        if ($this->needsRefresh($connection) === false) {
            return $accessToken;
        }

        $refreshCiphertext = $connection->refresh_ciphertext;

        if (is_string($refreshCiphertext) === false || $refreshCiphertext === '') {
            throw MediaConnectorException::refreshUnavailable();
        }

        $refreshToken = $this->cipher->decrypt(
            $refreshCiphertext,
            $context,
        );

        $refreshed = match ($connection->provider) {
            MediaConnection::PROVIDER_DROPBOX => $this->dropboxOAuth->refresh($refreshToken),
            MediaConnection::PROVIDER_GOOGLE_DRIVE => $this->googleOAuth->refresh($refreshToken),
            default => throw MediaConnectorException::requestFailed(),
        };

        $updated = match ($connection->provider) {
            MediaConnection::PROVIDER_DROPBOX => $this->connections->replaceDropboxTokens(
                $connection,
                $actor,
                $refreshed->accessToken,
                null,
                now()->addSeconds($refreshed->expiresInSeconds),
                $refreshed->scopes === [] ? null : $refreshed->scopes,
            ),
            MediaConnection::PROVIDER_GOOGLE_DRIVE => $this->connections->replaceGoogleDriveTokens(
                $connection,
                $actor,
                $refreshed->accessToken,
                null,
                now()->addSeconds($refreshed->expiresInSeconds),
                $refreshed->scopes === [] ? null : $refreshed->scopes,
            ),
            default => throw MediaConnectorException::requestFailed(),
        };

        return $this->cipher->decrypt(
            $updated->access_ciphertext,
            $updated->cryptoContext(),
        );
    }

    private function assertSupportedProvider(MediaConnection $connection): void
    {
        if (
            in_array($connection->provider, [
                MediaConnection::PROVIDER_DROPBOX,
                MediaConnection::PROVIDER_GOOGLE_DRIVE,
            ], true) === false
        ) {
            throw MediaConnectorException::requestFailed();
        }
    }

    private function needsRefresh(MediaConnection $connection): bool
    {
        $expiresAt = $connection->getAttribute('token_expires_at');

        if ($expiresAt === null) {
            return false;
        }

        if ($expiresAt instanceof \DateTimeInterface === false) {
            throw MediaConnectorException::requestFailed();
        }

        $margin = max(60, min(
            (int) config(
                $this->refreshMarginConfigKey($connection),
                300,
            ),
            3600,
        ));

        return $expiresAt->getTimestamp()
            <= now()->addSeconds($margin)->getTimestamp();
    }

    private function refreshMarginConfigKey(MediaConnection $connection): string
    {
        return match ($connection->provider) {
            MediaConnection::PROVIDER_DROPBOX =>
                'grindflow.connectors.dropbox.refresh_margin_seconds',
            MediaConnection::PROVIDER_GOOGLE_DRIVE =>
                'grindflow.connectors.google_drive.refresh_margin_seconds',
            default => throw MediaConnectorException::requestFailed(),
        };
    }
}
