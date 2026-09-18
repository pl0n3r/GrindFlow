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
        private readonly MediaConnectionManager $connections,
    ) {}

    public function accessToken(
        MediaConnection $connection,
        User $actor,
    ): string {
        if ($connection->provider !== MediaConnection::PROVIDER_DROPBOX) {
            throw MediaConnectorException::requestFailed();
        }

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
        $refreshed = $this->dropboxOAuth->refresh($refreshToken);

        $updated = $this->connections->replaceDropboxTokens(
            $connection,
            $actor,
            $refreshed->accessToken,
            null,
            now()->addSeconds($refreshed->expiresInSeconds),
            $refreshed->scopes === [] ? null : $refreshed->scopes,
        );

        return $this->cipher->decrypt(
            $updated->access_ciphertext,
            $updated->cryptoContext(),
        );
    }

    private function needsRefresh(MediaConnection $connection): bool
    {
        if ($connection->token_expires_at === null) {
            return false;
        }

        $margin = max(60, min(
            (int) config(
                'grindflow.connectors.dropbox.refresh_margin_seconds',
                300,
            ),
            3600,
        ));

        return $connection->token_expires_at->timestamp
            <= now()->addSeconds($margin)->timestamp;
    }
}
