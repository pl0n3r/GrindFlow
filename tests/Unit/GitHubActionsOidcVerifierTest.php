<?php

namespace Tests\Unit;

use App\Support\Deployment\GitHubActionsOidcVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GitHubActionsOidcVerifierTest extends TestCase
{
    private mixed $privateKey;

    /** @var array<string, string> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        self::assertNotFalse($this->privateKey);

        $details = openssl_pkey_get_details($this->privateKey);
        self::assertIsArray($details);

        $this->jwk = [
            'kty' => 'RSA',
            'kid' => 'grindflow-test-key',
            'n' => $this->base64Url((string) $details['rsa']['n']),
            'e' => $this->base64Url((string) $details['rsa']['e']),
        ];

        Cache::flush();

        Http::fake([
            'https://token.actions.githubusercontent.com/.well-known/jwks' => Http::response([
                'keys' => [$this->jwk],
            ]),
        ]);
    }

    public function test_it_accepts_only_the_exact_main_production_smoke_workflow_identity(): void
    {
        $sha = str_repeat('a', 40);
        $token = $this->token($sha);

        $claims = (new GitHubActionsOidcVerifier)->verify($token, $sha);

        self::assertSame($sha, $claims['sha']);
        self::assertSame('pl0n3r/GrindFlow', $claims['repository']);
    }

    public function test_it_rejects_a_validly_signed_token_for_another_sha(): void
    {
        $this->expectException(RuntimeException::class);

        $token = $this->token(str_repeat('a', 40));

        (new GitHubActionsOidcVerifier)->verify($token, str_repeat('b', 40));
    }

    public function test_it_rejects_a_validly_signed_token_for_another_workflow(): void
    {
        $this->expectException(RuntimeException::class);

        $sha = str_repeat('a', 40);
        $token = $this->token($sha, [
            'workflow_ref' => 'pl0n3r/GrindFlow/.github/workflows/grindflow-ci.yml@refs/heads/main',
        ]);

        (new GitHubActionsOidcVerifier)->verify($token, $sha);
    }

    public function test_it_rejects_tampered_signature(): void
    {
        $sha = str_repeat('a', 40);
        $parts = explode('.', $this->token($sha));
        $parts[2] = $this->base64Url(str_repeat("\0", 256));

        $this->expectException(RuntimeException::class);

        (new GitHubActionsOidcVerifier)->verify(implode('.', $parts), $sha);
    }

    public function test_it_rejects_expired_token(): void
    {
        $sha = str_repeat('a', 40);
        $now = time();

        $this->expectException(RuntimeException::class);

        (new GitHubActionsOidcVerifier)->verify($this->token($sha, [
            'iat' => $now - 600,
            'nbf' => $now - 600,
            'exp' => $now - 120,
        ]), $sha);
    }

    public function test_it_rejects_invalid_audience(): void
    {
        $sha = str_repeat('a', 40);

        $this->expectException(RuntimeException::class);

        (new GitHubActionsOidcVerifier)->verify($this->token($sha, [
            'aud' => 'another-audience',
        ]), $sha);
    }

    public function test_it_rejects_pull_request_event(): void
    {
        $sha = str_repeat('a', 40);

        $this->expectException(RuntimeException::class);

        (new GitHubActionsOidcVerifier)->verify($this->token($sha, [
            'event_name' => 'pull_request',
        ]), $sha);
    }

    public function test_it_rejects_unknown_signing_key(): void
    {
        $sha = str_repeat('a', 40);

        $this->expectException(RuntimeException::class);

        (new GitHubActionsOidcVerifier)->verify(
            $this->token($sha, [], ['kid' => 'unknown-key']),
            $sha,
        );
    }

    public function test_it_rejects_replay_of_an_already_consumed_jti(): void
    {
        $sha = str_repeat('a', 40);
        $token = $this->token($sha, ['jti' => 'fixed-replay-jti']);

        (new GitHubActionsOidcVerifier)->verify($token, $sha);

        $this->expectException(RuntimeException::class);

        (new GitHubActionsOidcVerifier)->verify($token, $sha);
    }

    /**
     * @param  array<string, mixed>  $claimOverrides
     * @param  array<string, mixed>  $headerOverrides
     */
    private function token(
        string $sha,
        array $claimOverrides = [],
        array $headerOverrides = [],
    ): string {
        $now = time();
        $header = array_replace([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'grindflow-test-key',
        ], $headerOverrides);
        $claims = array_replace([
            'iss' => 'https://token.actions.githubusercontent.com',
            'aud' => 'grindflow-production-smoke-bootstrap',
            'sub' => 'repo:pl0n3r@64439547/GrindFlow@1377610265:ref:refs/heads/main',
            'repository' => 'pl0n3r/GrindFlow',
            'repository_id' => '1377610265',
            'repository_owner_id' => '64439547',
            'ref' => 'refs/heads/main',
            'ref_type' => 'branch',
            'sha' => $sha,
            'workflow_ref' => 'pl0n3r/GrindFlow/.github/workflows/production-smoke.yml@refs/heads/main',
            'workflow_sha' => $sha,
            'runner_environment' => 'github-hosted',
            'event_name' => 'push',
            'jti' => 'oidc-test-'.bin2hex(random_bytes(8)),
            'iat' => $now - 5,
            'nbf' => $now - 5,
            'exp' => $now + 300,
        ], $claimOverrides);

        $encodedHeader = $this->base64Url((string) json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->base64Url((string) json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedClaims;
        $signature = '';

        self::assertTrue(openssl_sign(
            $signingInput,
            $signature,
            $this->privateKey,
            OPENSSL_ALGO_SHA256,
        ));

        return $signingInput.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
