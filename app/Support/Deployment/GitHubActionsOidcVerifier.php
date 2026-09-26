<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubActionsOidcVerifier
{
    private const string ISSUER = 'https://token.actions.githubusercontent.com';

    private const string JWKS_URL = self::ISSUER.'/.well-known/jwks';

    private const string AUDIENCE = 'grindflow-production-smoke-bootstrap';

    private const string REPOSITORY = 'pl0n3r/GrindFlow';

    private const string REPOSITORY_ID = '1377610265';

    private const string OWNER_ID = '64439547';

    private const string REF = 'refs/heads/main';

    private const string WORKFLOW_REF = 'pl0n3r/GrindFlow/.github/workflows/production-smoke.yml@refs/heads/main';

    /**
     * @return array<string, mixed>
     */
    public function verify(string $jwt, string $expectedSha): array
    {
        $this->ensurePersistentReplayCache();

        if (preg_match('/^[0-9a-f]{40}$/', $expectedSha) !== 1) {
            throw new RuntimeException('Invalid expected deployment SHA.');
        }

        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed OIDC token.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->decodeJsonSegment($encodedHeader);
        $claims = $this->decodeJsonSegment($encodedClaims);
        $signature = $this->base64UrlDecode($encodedSignature);

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new RuntimeException('Unsupported OIDC token header.');
        }

        $this->assertClaims($claims, $expectedSha);
        $publicKey = $this->publicKeyFor((string) $header['kid']);

        $verified = openssl_verify(
            $encodedHeader.'.'.$encodedClaims,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new RuntimeException('OIDC token signature verification failed.');
        }

        $this->consumeJti($claims);

        return $claims;
    }

    private function ensurePersistentReplayCache(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $defaultStore = (string) config('cache.default');
        $defaultDriver = config("cache.stores.{$defaultStore}.driver");

        // NullStore discards replay IDs and undefined stores cannot enforce one-time tokens.
        if ($defaultStore === 'null' || $defaultDriver === null || $defaultDriver === 'null') {
            throw new RuntimeException('A persistent cache store is required for production OIDC replay protection.');
        }

        if ($defaultDriver !== 'array') {
            return;
        }

        $fileDriver = config('cache.stores.file.driver');
        $filePath = config('cache.stores.file.path');

        if (
            $fileDriver !== 'file'
            || ! is_string($filePath)
            || $filePath === ''
            || ! is_dir($filePath)
            || ! is_writable($filePath)
        ) {
            throw new RuntimeException('A persistent cache store is required for production OIDC replay protection.');
        }

        config(['cache.default' => 'file']);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function consumeJti(array $claims): void
    {
        $jti = (string) ($claims['jti'] ?? '');
        $exp = $this->integerClaim($claims, 'exp');
        $ttl = max(1, $exp + 60 - time());
        $key = 'production-smoke-oidc-jti:'.hash('sha256', $jti);

        if (! Cache::add($key, true, $ttl)) {
            throw new RuntimeException('OIDC token was already used.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaims(array $claims, string $expectedSha): void
    {
        $now = time();
        $leeway = 60;

        $this->assertExact($claims, 'iss', self::ISSUER);

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (! in_array(self::AUDIENCE, $audiences, true)) {
            throw new RuntimeException('OIDC audience mismatch.');
        }

        $this->assertExact($claims, 'repository', self::REPOSITORY);
        $this->assertExact($claims, 'repository_id', self::REPOSITORY_ID);
        $this->assertExact($claims, 'repository_owner_id', self::OWNER_ID);
        $this->assertExact($claims, 'ref', self::REF);
        $this->assertExact($claims, 'ref_type', 'branch');
        $this->assertExact($claims, 'sha', $expectedSha);
        $this->assertExact($claims, 'workflow_ref', self::WORKFLOW_REF);
        $this->assertExact($claims, 'workflow_sha', $expectedSha);
        $this->assertExact($claims, 'runner_environment', 'github-hosted');

        if (! in_array($claims['event_name'] ?? null, ['push', 'workflow_dispatch'], true)) {
            throw new RuntimeException('OIDC event is not authorized.');
        }

        $exp = $this->integerClaim($claims, 'exp');
        $nbf = $this->integerClaim($claims, 'nbf');
        $iat = $this->integerClaim($claims, 'iat');

        if ($exp < $now - $leeway || $nbf > $now + $leeway || $iat > $now + $leeway) {
            throw new RuntimeException('OIDC token is outside its validity window.');
        }

        if ($exp - $iat > 900) {
            throw new RuntimeException('OIDC token validity window is unexpectedly long.');
        }

        if (! is_string($claims['jti'] ?? null) || trim((string) $claims['jti']) === '') {
            throw new RuntimeException('OIDC token has no identifier.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertExact(array $claims, string $key, string $expected): void
    {
        $actual = $claims[$key] ?? null;

        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException("OIDC claim {$key} mismatch.");
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function integerClaim(array $claims, string $key): int
    {
        $value = $claims[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("OIDC claim {$key} is invalid.");
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonSegment(string $segment): array
    {
        $decoded = json_decode($this->base64UrlDecode($segment), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OIDC token contains invalid JSON.');
        }

        return $decoded;
    }

    private function publicKeyFor(string $kid): string
    {
        $response = Http::acceptJson()
            ->timeout(5)
            ->get(self::JWKS_URL);

        if (! $response->successful()) {
            throw new RuntimeException('GitHub OIDC signing keys are unavailable.');
        }

        $keys = $response->json('keys');

        if (! is_array($keys)) {
            throw new RuntimeException('GitHub OIDC signing keys are invalid.');
        }

        foreach ($keys as $key) {
            if (
                is_array($key)
                && ($key['kid'] ?? null) === $kid
                && ($key['kty'] ?? null) === 'RSA'
                && is_string($key['n'] ?? null)
                && is_string($key['e'] ?? null)
            ) {
                return $this->rsaJwkToPem($key['n'], $key['e']);
            }
        }

        throw new RuntimeException('GitHub OIDC signing key was not found.');
    }

    private function rsaJwkToPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = $this->derSequence(
            $this->derInteger($this->base64UrlDecode($modulus))
            .$this->derInteger($this->base64UrlDecode($exponent)),
        );

        $rsaEncryptionAlgorithm = hex2bin('300d06092a864886f70d0101010500');

        if ($rsaEncryptionAlgorithm === false) {
            throw new RuntimeException('Unable to build RSA algorithm identifier.');
        }

        $subjectPublicKeyInfo = $this->derSequence(
            $rsaEncryptionAlgorithm
            ."\x03".$this->derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey,
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $value): string
    {
        return "\x30".$this->derLength(strlen($value)).$value;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '') {
            $value = "\x00";
        }

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derLength(strlen($value)).$value;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $encoded = '';

        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value.str_repeat('=', $padding), '-_', '+/'),
            true,
        );

        if ($decoded === false) {
            throw new RuntimeException('OIDC token contains invalid base64url.');
        }

        return $decoded;
    }
}
