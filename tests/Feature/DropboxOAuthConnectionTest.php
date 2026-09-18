<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Security\SecretCipher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DropboxOAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'grindflow.security.encryption_master_key' => str_repeat('ab', 32),
            'grindflow.connectors.dropbox.app_key' => 'dropbox-app-key',
            'grindflow.connectors.dropbox.app_secret' => 'dropbox-app-secret',
            'grindflow.connectors.dropbox.oauth_state_ttl_seconds' => 600,
        ]);
    }

    public function test_manager_can_start_offline_dropbox_authorization(): void
    {
        [$user, $organization] = $this->manager();

        $response = $this->actingAs($user)->get(
            route('organizations.connections.dropbox.authorize', [
                'organizationId' => $organization->getKey(),
            ]),
        );

        $response->assertRedirect();

        $query = $this->redirectQuery($response->headers->get('Location'));

        $this->assertSame('dropbox-app-key', $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('offline', $query['token_access_type'] ?? null);
        $this->assertSame(
            route('connections.dropbox.callback'),
            $query['redirect_uri'] ?? null,
        );
        $this->assertIsString($query['state'] ?? null);
        $this->assertSame(64, strlen((string) $query['state']));

        $response->assertSessionHas(
            'oauth.dropbox.pending',
            function (mixed $pending) use ($query, $organization, $user): bool {
                if (is_array($pending) === false) {
                    return false;
                }

                return ($pending['state_hash'] ?? null)
                        === hash('sha256', (string) $query['state'])
                    && ($pending['organization_id'] ?? null)
                        === (string) $organization->getKey()
                    && ($pending['user_id'] ?? null)
                        === (string) $user->getKey();
            },
        );
    }

    public function test_callback_exchanges_code_and_creates_encrypted_tenant_connection(): void
    {
        [$user, $organization] = $this->manager();
        $state = $this->startAuthorization($user, $organization);

        Http::fake([
            'https://api.dropbox.com/oauth2/token' => Http::response([
                'access_token' => 'authorized-access-token',
                'refresh_token' => 'authorized-refresh-token',
                'expires_in' => 14_400,
                'scope' => 'files.content.read files.metadata.read',
                'account_id' => 'dbid:account-123',
                'token_type' => 'bearer',
            ]),
        ]);

        $callback = route('connections.dropbox.callback', [
            'code' => 'authorization-code',
            'state' => $state,
        ]);

        $this->actingAs($user)
            ->get($callback)
            ->assertRedirect(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertSessionHas('status', 'Dropbox conectado correctamente.');

        app(TenantContext::class)->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            function (): void {
                $connection = MediaConnection::query()->firstOrFail();

                $this->assertSame(MediaConnection::PROVIDER_DROPBOX, $connection->provider);
                $this->assertSame('dbid:account-123', $connection->account_identifier);
                $this->assertSame([
                    'files.content.read',
                    'files.metadata.read',
                ], $connection->scopes);
                $this->assertNotNull($connection->token_expires_at);
                $this->assertSame(
                    'authorized-access-token',
                    app(SecretCipher::class)->decrypt(
                        $connection->access_ciphertext,
                        $connection->cryptoContext(),
                    ),
                );
                $this->assertSame(
                    'authorized-refresh-token',
                    app(SecretCipher::class)->decrypt(
                        (string) $connection->refresh_ciphertext,
                        $connection->cryptoContext(),
                    ),
                );
            },
        );

        $raw = DB::table('media_connections')->first();

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(
            'authorized-access-token',
            (string) $raw->access_ciphertext,
        );
        $this->assertStringNotContainsString(
            'authorized-refresh-token',
            (string) $raw->refresh_ciphertext,
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.dropbox.com/oauth2/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'authorization-code'
                && $request['redirect_uri'] === route('connections.dropbox.callback')
                && $request['client_id'] === 'dropbox-app-key'
                && $request['client_secret'] === 'dropbox-app-secret';
        });
        Http::assertSentCount(1);

        $this->actingAs($user)
            ->get($callback)
            ->assertStatus(419);

        Http::assertSentCount(1);
    }

    public function test_invalid_state_fails_before_provider_io(): void
    {
        [$user, $organization] = $this->manager();

        $this->startAuthorization($user, $organization);
        Http::fake();

        $this->actingAs($user)
            ->get(route('connections.dropbox.callback', [
                'code' => 'authorization-code',
                'state' => str_repeat('0', 64),
            ]))
            ->assertStatus(419);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('media_connections')->count());
    }

    public function test_provider_denial_is_safe_and_does_not_exchange_code(): void
    {
        [$user, $organization] = $this->manager();
        $state = $this->startAuthorization($user, $organization);
        Http::fake();

        $this->actingAs($user)
            ->get(route('connections.dropbox.callback', [
                'error' => 'access_denied',
                'error_description' => 'provider secret details',
                'state' => $state,
            ]))
            ->assertRedirect(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertSessionHasErrors('dropbox');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('media_connections')->count());
    }

    public function test_non_manager_member_cannot_start_dropbox_authorization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => UserRole::Model,
        ]);

        $this->actingAs($user)
            ->get(route('organizations.connections.dropbox.authorize', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertForbidden();
    }

    public function test_malformed_token_response_fails_without_persisting_provider_body(): void
    {
        [$user, $organization] = $this->manager();
        $state = $this->startAuthorization($user, $organization);

        Http::fake([
            'https://api.dropbox.com/oauth2/token' => Http::response([
                'error' => 'malformed',
                'raw_secret' => 'must-never-persist',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('connections.dropbox.callback', [
                'code' => 'authorization-code',
                'state' => $state,
            ]))
            ->assertRedirect(route('organizations.vault.index', [
                'organizationId' => $organization->getKey(),
            ]))
            ->assertSessionHasErrors('dropbox');

        $this->assertSame(0, DB::table('media_connections')->count());
        $this->assertDatabaseMissing('media_connections', [
            'last_error' => 'must-never-persist',
        ]);
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
            route('organizations.connections.dropbox.authorize', [
                'organizationId' => $organization->getKey(),
            ]),
        );

        $response->assertRedirect();

        $query = $this->redirectQuery($response->headers->get('Location'));
        $state = $query['state'] ?? null;

        $this->assertIsString($state);
        $this->assertNotSame('', $state);

        return $state;
    }

    /**
     * @return array<string, string>
     */
    private function redirectQuery(?string $location): array
    {
        $this->assertIsString($location);
        $this->assertStringStartsWith(
            'https://www.dropbox.com/oauth2/authorize?',
            $location,
        );

        $queryString = parse_url($location, PHP_URL_QUERY);

        $this->assertIsString($queryString);

        parse_str($queryString, $query);

        return array_filter(
            $query,
            static fn (mixed $value): bool => is_string($value),
        );
    }
}
