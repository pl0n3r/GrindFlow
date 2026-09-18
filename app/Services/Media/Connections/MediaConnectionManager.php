<?php

namespace App\Services\Media\Connections;

use App\Models\MediaConnection;
use App\Models\User;
use App\Support\Security\SecretCipher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class MediaConnectionManager
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SecretCipher $cipher,
    ) {}

    /**
     * @param  list<string>  $scopes
     */
    public function connectDropbox(
        User $actor,
        string $accessToken,
        string $label = 'Dropbox',
        ?string $rootPath = null,
        ?int $scanIntervalMinutes = null,
        ?string $refreshToken = null,
        ?\DateTimeInterface $tokenExpiresAt = null,
        array $scopes = [],
        ?string $accountIdentifier = null,
    ): MediaConnection {
        return $this->connectProvider(
            $actor,
            MediaConnection::PROVIDER_DROPBOX,
            $accessToken,
            $label,
            $rootPath,
            $scanIntervalMinutes,
            $refreshToken,
            $tokenExpiresAt,
            $scopes,
            $accountIdentifier,
            true,
        );
    }

    /**
     * @param  list<string>  $scopes
     */
    public function connectGoogleDrive(
        User $actor,
        string $accessToken,
        string $label = 'Google Drive',
        ?string $rootPath = null,
        ?int $scanIntervalMinutes = null,
        ?string $refreshToken = null,
        ?\DateTimeInterface $tokenExpiresAt = null,
        array $scopes = [],
        ?string $accountIdentifier = null,
    ): MediaConnection {
        return $this->connectProvider(
            $actor,
            MediaConnection::PROVIDER_GOOGLE_DRIVE,
            $accessToken,
            $label,
            $rootPath,
            $scanIntervalMinutes,
            $refreshToken,
            $tokenExpiresAt,
            $scopes,
            $accountIdentifier,
            true,
        );
    }

    /**
     * @param  list<string>|null  $scopes
     */
    public function replaceDropboxTokens(
        MediaConnection $connection,
        User $actor,
        string $accessToken,
        ?string $refreshToken = null,
        ?\DateTimeInterface $tokenExpiresAt = null,
        ?array $scopes = null,
    ): MediaConnection {
        return $this->replaceProviderTokens(
            $connection,
            $actor,
            MediaConnection::PROVIDER_DROPBOX,
            $accessToken,
            $refreshToken,
            $tokenExpiresAt,
            $scopes,
        );
    }

    /**
     * @param  list<string>|null  $scopes
     */
    public function replaceGoogleDriveTokens(
        MediaConnection $connection,
        User $actor,
        string $accessToken,
        ?string $refreshToken = null,
        ?\DateTimeInterface $tokenExpiresAt = null,
        ?array $scopes = null,
    ): MediaConnection {
        return $this->replaceProviderTokens(
            $connection,
            $actor,
            MediaConnection::PROVIDER_GOOGLE_DRIVE,
            $accessToken,
            $refreshToken,
            $tokenExpiresAt,
            $scopes,
        );
    }

    public function pause(MediaConnection $connection, User $actor): MediaConnection
    {
        $this->assertConnectionAccess($connection, $actor);

        $connection->forceFill([
            'status' => MediaConnection::STATUS_PAUSED,
            'next_scan_at' => null,
        ])->save();

        return $connection->refresh();
    }

    public function resume(MediaConnection $connection, User $actor): MediaConnection
    {
        $this->assertConnectionAccess($connection, $actor);

        $connection->forceFill([
            'authorized_by_user_id' => $actor->getKey(),
            'status' => MediaConnection::STATUS_ACTIVE,
            'next_scan_at' => now(),
            'last_error' => null,
        ])->save();

        return $connection->refresh();
    }

    /**
     * @param  list<string>  $scopes
     */
    private function connectProvider(
        User $actor,
        string $provider,
        string $accessToken,
        string $label,
        ?string $rootPath,
        ?int $scanIntervalMinutes,
        ?string $refreshToken,
        ?\DateTimeInterface $tokenExpiresAt,
        array $scopes,
        ?string $accountIdentifier,
        bool $scheduleImmediately,
    ): MediaConnection {
        $organizationId = $this->organizationId($actor);
        $this->assertText($accessToken, 16_384, 'Access token');
        $this->assertText($label, 191, 'Connection label');
        $this->assertProvider($provider);

        if ($refreshToken !== null) {
            $this->assertText($refreshToken, 16_384, 'Refresh token');
        }

        if ($rootPath !== null) {
            $this->assertText($rootPath, 1024, 'Provider root path');
        }

        if ($accountIdentifier !== null) {
            $this->assertText($accountIdentifier, 191, 'Account identifier');
        }

        $context = sprintf(
            'grindflow:cloud:%s:%s',
            $organizationId,
            $provider,
        );

        $connection = new MediaConnection;
        $connection->forceFill([
            'authorized_by_user_id' => $actor->getKey(),
            'provider' => $provider,
            'label' => $label,
            'account_identifier' => $accountIdentifier,
            'access_ciphertext' => $this->cipher->encrypt($accessToken, $context),
            'refresh_ciphertext' => $refreshToken === null
                ? null
                : $this->cipher->encrypt($refreshToken, $context),
            'token_expires_at' => $tokenExpiresAt,
            'scopes' => $this->normalizedScopes($scopes),
            'root_path' => $rootPath,
            'status' => $scheduleImmediately
                ? MediaConnection::STATUS_ACTIVE
                : MediaConnection::STATUS_PAUSED,
            'scan_interval_minutes' => $this->interval($scanIntervalMinutes),
            'next_scan_at' => $scheduleImmediately ? now() : null,
            'last_error' => null,
            'consecutive_failures' => 0,
        ]);
        $connection->save();

        if ((string) $connection->organization_id !== $organizationId) {
            throw new AuthorizationException('Connection tenant mismatch.');
        }

        return $connection->refresh();
    }

    /**
     * @param  list<string>|null  $scopes
     */
    private function replaceProviderTokens(
        MediaConnection $connection,
        User $actor,
        string $provider,
        string $accessToken,
        ?string $refreshToken,
        ?\DateTimeInterface $tokenExpiresAt,
        ?array $scopes,
    ): MediaConnection {
        $this->assertConnectionAccess($connection, $actor);
        $this->assertText($accessToken, 16_384, 'Access token');

        if ($refreshToken !== null) {
            $this->assertText($refreshToken, 16_384, 'Refresh token');
        }

        if ($connection->provider !== $provider) {
            throw new InvalidArgumentException('Connection provider mismatch.');
        }

        $context = $connection->cryptoContext();

        $connection->forceFill([
            'authorized_by_user_id' => $actor->getKey(),
            'access_ciphertext' => $this->cipher->encrypt($accessToken, $context),
            'refresh_ciphertext' => $refreshToken === null
                ? $connection->refresh_ciphertext
                : $this->cipher->encrypt($refreshToken, $context),
            'token_expires_at' => $tokenExpiresAt,
            'scopes' => $scopes === null
                ? $connection->scopes
                : $this->normalizedScopes($scopes),
            'status' => $connection->status,
            'next_scan_at' => $connection->next_scan_at,
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->save();

        return $connection->refresh();
    }

    private function organizationId(User $actor): string
    {
        $organizationId = $this->tenantContext->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException(
                'Tenant context is required to manage media connections.',
            );
        }

        if ($actor->canManageOrganization($organizationId) === false) {
            throw new AuthorizationException(
                'The user cannot manage media connections for this organization.',
            );
        }

        return $organizationId;
    }

    private function assertConnectionAccess(
        MediaConnection $connection,
        User $actor,
    ): void {
        $organizationId = $this->organizationId($actor);

        if (hash_equals((string) $connection->organization_id, $organizationId) === false) {
            throw new AuthorizationException('The active tenant does not match this connection.');
        }
    }

    private function assertProvider(string $provider): void
    {
        if (
            in_array($provider, [
                MediaConnection::PROVIDER_DROPBOX,
                MediaConnection::PROVIDER_GOOGLE_DRIVE,
            ], true) === false
        ) {
            throw new InvalidArgumentException('Unsupported media connection provider.');
        }
    }

    private function interval(?int $minutes): int
    {
        $configured = $minutes ?? (int) config(
            'grindflow.media.connector_scan_interval_minutes',
            15,
        );

        return max(5, min($configured, 1440));
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function normalizedScopes(array $scopes): array
    {
        return array_values(array_filter(
            $scopes,
            static fn (string $scope): bool => $scope !== '',
        ));
    }

    private function assertText(
        string $value,
        int $maxLength,
        string $field,
    ): void {
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "{$field} must contain between 1 and {$maxLength} characters.",
            );
        }
    }
}
