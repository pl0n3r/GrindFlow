<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\Connections\MediaConnectionManager;
use App\Services\Media\Connections\MediaConnectionTokenProvider;
use App\Support\Security\SecretCipher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveOAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'grindflow.security.encryption_master_key' => str_repeat('ab', 32),
            'grindflow.connectors.google_drive.client_id' => 'google-client-id',
            'grindflow.connectors.google_drive.client_secret' => 'google-client-secret',
            'grindflow.connectors.google_drive.oauth_state_ttl_seconds' => 600,
            'grindflow.connectors.google_drive.refresh_margin_seconds' => 300,
        ]);
    }

    public function test_manager_can_start_google_drive_offline_authorization(): void
    {
        [$user, $organization] = $this->manager();

        $response = $this->actingAs($user)->get(
            route('organizations.connections.google-drive.authorize', [
                'organizationId' => $organization->getKey(),
            ]),
        );

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertIsString($location);
        $this->assertStringStartsWith(
            'https://accounts.google.com/o/oauth2/v2/auth?',
            $location,
        );

        $queryString = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($queryString);

        parse_str($queryString, $query);

        $this->assertSame('google-client-id', $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('offline', $query['access_type'] ?? null);
        $this->assertSame('consent', $query['prompt'] ?? null);
        $this->assertSame('true', $query['include_granted_scopes'] ?? null);
        $this->assertSame(
            'https://www.googleapis.com/auth/drive.readonly',
            $query['scope'] ?? null,
        );
        $this->assertSame(
            route('connections.google-drive.callback'),
            $query['redirect_uri'] ?? null,
        );

        $state = $query['state'] ?? null;

        $this->assertIsString($state);
        $this->assertSame(64, strlen($state));

        $response->assertSessionHas(
            'oauth.google_drive.pending',
            function (mixed $pending) use ($state, $organization, $user): bool {
                if (is_array($pending) === false) {
                    return false;
                }

                return ($pending['state_hash'] ?? null) === hash('sha256', $state)
                    && ($pending['organization_id'] ?? null)
                        === (string) $organization->getKey()
                    && ($pending['user_id'] ?? null)
                        === (string) $user->getKey();
            },
        );
    }

    public function test_callback_creates_encrypted_paused_google_drive_connection(): void
    {
        [$user, $organization] = $this->manager();
        $state = $this->startAuthorization($user, $organization);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/drive.readonly',
                'token_type' => 'Bearer',
            ]),
        ]);

        $callback = route('connections.google-drive.callback', [
            'code' => 'google-authorization-code',
            'state' => $state,
        ]);

        $this->actingAs($user)
            ->get($callback)
            ->assertRedirect(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertSessionHas('status', 'Google Drive conectado correctamente.');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): void {
                $connection = MediaConnection::query()->firstOrFail();

                $this->assertSame(
                    MediaConnection::PROVIDER_GOOGLE_DRIVE,
                    $connection->provider,
                );
                $this->assertSame(MediaConnection::STATUS_PAUSED, $connection->status);
                $this->assertNull($connection->next_scan_at);
                $this->assertSame([
                    'https://www.googleapis.com/auth/drive.readonly',
                ], $connection->scopes);
                $this->assertSame(
                    'google-access-token',
                    app(SecretCipher::class)->decrypt(
                        $connection->access_ciphertext,
                        $connection->cryptoContext(),
                    ),
                );
                $this->assertSame(
                    'google-refresh-token',
                    app(SecretCipher::class)->decrypt(
                        (string) $connection->refresh_ciphertext,
                        $connection->cryptoContext(),
                    ),
                );
            },
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'google-authorization-code'
                && $request['redirect_uri'] === route('connections.google-drive.callback')
                && $request['client_id'] === 'google-client-id'
                && $request['client_secret'] === 'google-client-secret';
        });

        $this->actingAs($user)
            ->get($callback)
            ->assertStatus(419);

        Http::assertSentCount(1);
    }

    public function test_expiring_google_access_token_refreshes_and_remains_paused(): void
    {
        [$user, $organization] = $this->manager();

        $connection = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): MediaConnection => app(MediaConnectionManager::class)
                ->connectGoogleDrive(
                    $user,
                    'expiring-google-access',
                    'Google Drive',
                    null,
                    null,
                    'stable-google-refresh',
                    now()->addMinute(),
                    ['https://www.googleapis.com/auth/drive.readonly'],
                ),
        );

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-google-access',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $token = app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): string => app(MediaConnectionTokenProvider::class)->accessToken(
                MediaConnection::query()->findOrFail($connection->getKey()),
                $user,
            ),
        );

        $this->assertSame('fresh-google-access', $token);

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function () use ($connection): void {
                $fresh = MediaConnection::query()->findOrFail($connection->getKey());

                $this->assertSame(MediaConnection::STATUS_PAUSED, $fresh->status);
                $this->assertNull($fresh->next_scan_at);
                $this->assertSame([
                    'https://www.googleapis.com/auth/drive.readonly',
                ], $fresh->scopes);
                $this->assertSame(
                    'stable-google-refresh',
                    app(SecretCipher::class)->decrypt(
                        (string) $fresh->refresh_ciphertext,
                        $fresh->cryptoContext(),
                    ),
                );
            },
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'stable-google-refresh'
                && $request['client_id'] === 'google-client-id'
                && $request['client_secret'] === 'google-client-secret';
        });
    }

    /**
     * @return array{User, Organization}
     */
    private function manager(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Studio,
        ]);

        return [$user, $organization];
    }

    private function startAuthorization(
        User $user,
        Organization $organization,
    ): string {
        $response = $this->actingAs($user)->get(
            route('organizations.connections.google-drive.authorize', [
                'organizationId' => $organization->getKey(),
            ]),
        );

        $location = $response->headers->get('Location');

        $this->assertIsString($location);
        $queryString = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($queryString);

        parse_str($queryString, $query);

        $state = $query['state'] ?? null;

        $this->assertIsString($state);
        $this->assertNotSame('', $state);

        return $state;
    }
}
