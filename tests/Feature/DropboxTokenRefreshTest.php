<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connections\MediaConnectionScanner;
use App\Support\Security\SecretCipher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DropboxTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'grindflow.security.encryption_master_key' => str_repeat('ab', 32),
            'grindflow.connectors.dropbox.app_key' => 'dropbox-app-key',
            'grindflow.connectors.dropbox.app_secret' => 'dropbox-app-secret',
            'grindflow.connectors.dropbox.refresh_margin_seconds' => 300,
        ]);

        Queue::fake();
    }

    public function test_expiring_dropbox_access_token_is_refreshed_before_scan(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.dropboxapi.com/oauth2/token') {
                return Http::response([
                    'access_token' => 'fresh-access-token',
                    'expires_in' => 14_400,
                    'scope' => 'files.content.read files.metadata.read',
                ]);
            }

            if ($request->url() === 'https://api.dropboxapi.com/2/files/list_folder') {
                return Http::response([
                    'entries' => [],
                    'cursor' => 'cursor-after-refresh',
                    'has_more' => false,
                ]);
            }

            return Http::response(status: 404);
        });

        [$user, $organization, $connection] = $this->connection(
            accessToken: 'expiring-access-token',
            refreshToken: 'stable-refresh-token',
            expiresAt: now()->addMinute(),
        );

        $oldCiphertext = $connection->access_ciphertext;

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user, $oldCiphertext): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertNotSame($oldCiphertext, $fresh->access_ciphertext);
                $this->assertSame(
                    'fresh-access-token',
                    app(SecretCipher::class)->decrypt(
                        $fresh->access_ciphertext,
                        $fresh->cryptoContext(),
                    ),
                );
                $this->assertSame('cursor-after-refresh', $fresh->cursor);
                $this->assertSame([
                    'files.content.read',
                    'files.metadata.read',
                ], $fresh->scopes);
                $this->assertNotNull($fresh->token_expires_at);
                $this->assertTrue(
                    $fresh->token_expires_at->isAfter(now()->addHours(3)),
                );
            },
        );

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.dropboxapi.com/oauth2/token') {
                return false;
            }

            return $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'stable-refresh-token'
                && $request['client_id'] === 'dropbox-app-key'
                && $request['client_secret'] === 'dropbox-app-secret';
        });

        Http::assertSentCount(2);
    }

    public function test_non_expiring_access_token_does_not_call_refresh_endpoint(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [],
                'cursor' => 'cursor-current-token',
                'has_more' => false,
            ]),
        ]);

        [$user, $organization, $connection] = $this->connection(
            accessToken: 'current-access-token',
            refreshToken: 'refresh-token',
            expiresAt: now()->addHours(2),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn () => app(MediaConnectionScanner::class)->scan(
                MediaConnection::query()->findOrFail($connection->getKey()),
                $user,
            ),
        );

        Http::assertNotSent(
            fn (Request $request): bool => $request->url() === 'https://api.dropboxapi.com/oauth2/token',
        );
        Http::assertSentCount(1);
    }

    public function test_invalid_refresh_grant_marks_connection_for_reconnect(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(
                ['error' => 'invalid_grant'],
                400,
            ),
        ]);

        [$user, $organization, $connection] = $this->connection(
            accessToken: 'expired-access-token',
            refreshToken: 'revoked-refresh-token',
            expiresAt: now()->subMinute(),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame(
                    MediaConnection::STATUS_NEEDS_RECONNECT,
                    $fresh->status,
                );
                $this->assertSame('connector_refresh_rejected', $fresh->last_error);
                $this->assertNull($fresh->next_scan_at);
            },
        );

        Http::assertSentCount(1);
    }

    public function test_expired_token_without_refresh_token_requires_reconnect_without_http(): void
    {
        Http::fake();

        [$user, $organization, $connection] = $this->connection(
            accessToken: 'expired-access-token',
            refreshToken: null,
            expiresAt: now()->subMinute(),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame(
                    MediaConnection::STATUS_NEEDS_RECONNECT,
                    $fresh->status,
                );
                $this->assertSame(
                    'connector_refresh_unavailable',
                    $fresh->last_error,
                );
            },
        );

        Http::assertNothingSent();
    }

    public function test_refresh_rate_limit_defers_without_spending_failure_budget(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(
                ['error' => 'too_many_requests'],
                429,
                ['Retry-After' => '180'],
            ),
        ]);

        [$user, $organization, $connection] = $this->connection(
            accessToken: 'expired-access-token',
            refreshToken: 'refresh-token',
            expiresAt: now()->subMinute(),
        );

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection, $user): void {
                app(MediaConnectionScanner::class)->scan(
                    MediaConnection::query()->findOrFail($connection->getKey()),
                    $user,
                );

                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame(MediaConnection::STATUS_ACTIVE, $fresh->status);
                $this->assertSame('connector_rate_limited', $fresh->last_error);
                $this->assertSame(0, $fresh->consecutive_failures);
                $this->assertNotNull($fresh->next_scan_at);
                $this->assertTrue($fresh->next_scan_at->isFuture());
            },
        );
    }

    /**
     * @return array{User, Organization, MediaConnection}
     */
    private function connection(
        string $accessToken,
        ?string $refreshToken,
        \DateTimeInterface $expiresAt,
    ): array {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)->connectDropbox(
                $user,
                $accessToken,
                'Dropbox Refresh Test',
                null,
                15,
                $refreshToken,
                $expiresAt,
            ),
        );

        return [$user, $organization, $connection];
    }
}
