<?php

namespace Tests\Unit;

use App\Services\Media\Connections\OAuthTokenEndpointClient;
use App\Services\Media\Connectors\MediaConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OAuthTokenEndpointClientTest extends TestCase
{
    private const TOKEN_URL = 'https://oauth.example.test/token';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function testAuthorizationExchangePostsOnceAndNormalizesPayload(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'access-from-fixture',
                'refresh_token' => 'refresh-from-fixture',
                'expires_in' => '30',
                'scope' => "  media.read   media.write\n",
                'account_id' => 'provider-account',
            ]),
        ]);

        $tokens = $this->client()->exchangeAuthorizationCode(
            self::TOKEN_URL, 'example-client', 'fixture-secret',
            'one-use-code', 'https://app.example.test/callback',
            'fallback.read', 'account_id',
        );

        $this->assertSame('access-from-fixture', $tokens->accessToken);
        $this->assertSame('refresh-from-fixture', $tokens->refreshToken);
        $this->assertSame(60, $tokens->expiresInSeconds);
        $this->assertSame(['media.read', 'media.write'], $tokens->scopes);
        $this->assertSame('provider-account', $tokens->accountIdentifier);
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === self::TOKEN_URL
            && $request->method() === 'POST'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_id'] === 'example-client'
            && $request['client_secret'] === 'fixture-secret'
            && $request['code'] === 'one-use-code'
            && $request['redirect_uri'] === 'https://app.example.test/callback'
            && ($request->options()['timeout'] ?? null) === 20
        );
        Http::assertSentCount(1);
    }

    public function testAuthorizationUsesDefaultScopesAndIgnoresInvalidOptionalAccount(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_in' => 100_000,
                'account_id' => ['untrusted' => 'value'],
            ]),
        ]);

        $tokens = $this->exchange(defaultScope: 'read  write', accountField: 'account_id');
        $this->assertSame(86_400, $tokens->expiresInSeconds);
        $this->assertSame(['read', 'write'], $tokens->scopes);
        $this->assertNull($tokens->accountIdentifier);
        Http::assertSentCount(1);
    }

    public function testMissingAuthorizationParametersFailBeforeNetwork(): void
    {
        Http::fake();

        $this->assertSafeError(
            fn () => $this->exchange(code: ''),
            'connector_oauth_exchange_failed',
        );
        $this->assertSafeError(
            fn () => $this->exchange(redirectUri: ''),
            'connector_oauth_exchange_failed',
        );
        Http::assertNothingSent();
    }

    public function testAuthorizationRejectsProviderErrorsAndMalformedResponsesWithoutLeakingSecrets(): void
    {
        foreach ([
            Http::response(['error_description' => 'fixture-secret exposed by provider'], 401),
            Http::response(['error' => 'server_error'], 503),
            Http::response('not-json', 200),
            Http::response(['access_token' => 'only-access', 'expires_in' => 3600], 200),
            Http::response(['access_token' => '', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
            Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 'invalid'], 200),
        ] as $response) {
            Http::fake([self::TOKEN_URL => $response]);
            $this->assertSafeError(fn () => $this->exchange(), 'connector_oauth_exchange_failed');
        }
    }

    public function testAuthorizationRateLimitClampsRetryAfterWithoutRetrying(): void
    {
        foreach ([['2', 60], ['99999', 3600], ['tomorrow', null]] as [$header, $expected]) {
            Http::fake([
                self::TOKEN_URL => Http::response(
                    ['error_description' => 'fixture-secret provider detail'],
                    429,
                    ['Retry-After' => $header],
                ),
            ]);
            $this->assertSafeError(
                fn () => $this->exchange(),
                'connector_rate_limited',
                $expected,
            );
        }
    }

    public function testAuthorizationConnectionTimeoutIsSafeAndIsNotRetried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('fixture-secret in transport failure');
        });

        $this->assertSafeError(fn () => $this->exchange(), 'connector_oauth_exchange_failed');
        $this->assertSame(1, $attempts);
    }

    public function testRefreshPostsOnceWithBoundedExpiryAndParsedScopes(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'new-access',
                'expires_in' => 200_000,
                'scope' => 'files.read   files.write',
            ]),
        ]);

        $result = $this->refresh();
        $this->assertSame('new-access', $result->accessToken);
        $this->assertSame(86_400, $result->expiresInSeconds);
        $this->assertSame(['files.read', 'files.write'], $result->scopes);
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'fixture-refresh-secret'
            && $request['client_id'] === 'example-client'
            && $request['client_secret'] === 'fixture-secret'
            && ($request->options()['timeout'] ?? null) === 20
        );
        Http::assertSentCount(1);
    }

    public function testRefreshRejectsInvalidGrantAndOtherHttpErrorsUsingOnlySafeCodes(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'fixture-secret must never surface',
            ], 400),
        ]);
        $this->assertSafeError(
            fn () => $this->refresh(),
            'connector_refresh_rejected',
            needsReconnect: true,
        );

        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);
        $this->assertSafeError(fn () => $this->refresh(), 'connector_request_failed');
    }

    public function testRefreshRejectsInvalidPayloadAndBoundsShortExpiry(): void
    {
        foreach ([
            Http::response('not-json', 200),
            Http::response(['expires_in' => 3600], 200),
            Http::response(['access_token' => ['invalid'], 'expires_in' => 3600], 200),
            Http::response(['access_token' => 'access', 'expires_in' => 'not-a-number'], 200),
        ] as $response) {
            Http::fake([self::TOKEN_URL => $response]);
            $this->assertSafeError(fn () => $this->refresh(), 'connector_request_failed');
        }

        Http::fake([self::TOKEN_URL => Http::response([
            'access_token' => 'access',
            'expires_in' => 0,
            'scope' => ['bad-scope'],
        ])]);
        $result = $this->refresh();
        $this->assertSame(60, $result->expiresInSeconds);
        $this->assertSame([], $result->scopes);
    }

    public function testRefreshRateLimitHasSafeBoundedRetryAfterAndOneRequest(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(
                ['error_description' => 'fixture-refresh-secret'],
                429,
                ['Retry-After' => '240'],
            ),
        ]);
        $this->assertSafeError(fn () => $this->refresh(), 'connector_rate_limited', 240);
        Http::assertSentCount(1);
    }

    public function testRefreshConnectionTimeoutIsSafeAndIsNotRetried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('fixture-refresh-secret in transport failure');
        });

        $this->assertSafeError(fn () => $this->refresh(), 'connector_request_failed');
        $this->assertSame(1, $attempts);
    }

    private function client(): OAuthTokenEndpointClient
    {
        return new OAuthTokenEndpointClient();
    }

    private function exchange(
        string $code = 'one-use-code',
        string $redirectUri = 'https://app.example.test/callback',
        ?string $defaultScope = null,
        ?string $accountField = null,
    ): mixed {
        return $this->client()->exchangeAuthorizationCode(
            self::TOKEN_URL, 'example-client', 'fixture-secret',
            $code, $redirectUri, $defaultScope, $accountField,
        );
    }

    private function refresh(): mixed
    {
        return $this->client()->refresh(
            self::TOKEN_URL, 'example-client', 'fixture-secret', 'fixture-refresh-secret',
        );
    }

    private function assertSafeError(
        callable $operation,
        string $safeCode,
        ?int $retryAfter = null,
        bool $needsReconnect = false,
    ): void {
        try {
            $operation();
            $this->fail('Expected a sanitized connector error');
        } catch (MediaConnectorException $exception) {
            $this->assertSame($safeCode, $exception->getMessage());
            $this->assertSame($retryAfter, $exception->retryAfterSeconds);
            $this->assertSame($needsReconnect, $exception->needsReconnect);
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-refresh-secret', $exception->getMessage());
        }
    }
}
