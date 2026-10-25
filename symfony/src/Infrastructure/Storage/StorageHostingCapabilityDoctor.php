<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * Offline evaluation of caller-supplied, synthetic hosting contracts.
 *
 * This class performs no I/O, never verifies a hosting provider and never
 * exposes signed URLs, tenant identifiers or provider configuration.
 */
final class StorageHostingCapabilityDoctor
{
    /**
     * @param array<string, mixed> $readiness Sanitized DirectUploadReadiness::publicSummary().
     * @param array<string, mixed> $put Synthetic signed PUT contract, not a provider response.
     * @param array<string, mixed> $cors Synthetic bucket CORS policy and expected origin.
     * @param array<string, mixed> $tools Caller-reported ffmpeg/ffprobe presence.
     * @return array{status: string, storage_configured: bool, cors_contract_valid: bool, signed_put_contract_valid: bool, media_tools_reported: bool, hosting_verified: false, production_ready: false}
     */
    public function inspect(array $readiness, array $put, array $cors, array $tools): array
    {
        $configured = ($readiness['configured'] ?? null) === true;
        $result = [
            'status' => 'UNKNOWN',
            'storage_configured' => $configured,
            'cors_contract_valid' => false,
            'signed_put_contract_valid' => false,
            'media_tools_reported' => false,
            'hosting_verified' => false,
            'production_ready' => false,
        ];

        if (($readiness['configured'] ?? null) === false) {
            $result['status'] = 'NOT_CONFIGURED';
            return $result;
        }

        if (!$configured
            || !is_string($readiness['disk'] ?? null)
            || trim($readiness['disk']) === ''
            || !is_string($readiness['driver'] ?? null)
            || trim($readiness['driver']) === ''
            || !is_int($readiness['max_bytes'] ?? null)
            || $readiness['max_bytes'] < 1
        ) {
            return $result;
        }

        if (!$put || !$cors || !array_key_exists('ffmpeg', $tools) || !array_key_exists('ffprobe', $tools)) {
            $result['status'] = 'CHECKS_PENDING';
            return $result;
        }

        $result['cors_contract_valid'] = $this->validCors($cors);
        $result['signed_put_contract_valid'] = $this->validPut($put);
        $result['media_tools_reported'] = ($tools['ffmpeg'] === 'present' && $tools['ffprobe'] === 'present');

        if ($result['cors_contract_valid']
            && $result['signed_put_contract_valid']
            && $result['media_tools_reported']
        ) {
            // Even fully valid fixtures cannot establish a provider's readiness.
            $result['status'] = 'CANDIDATE_ONLY';
        }

        return $result;
    }

    /** @param array<string, mixed> $cors */
    private function validCors(array $cors): bool
    {
        $origin = $cors['expected_origin'] ?? null;
        $origins = $cors['allowed_origins'] ?? null;
        $methods = $cors['allowed_methods'] ?? null;

        if (!is_string($origin) || !$this->isHttpsOrigin($origin)
            || !is_array($origins) || !$origins || !array_is_list($origins)
            || !is_array($methods) || !$methods || !array_is_list($methods)
        ) {
            return false;
        }

        foreach ($origins as $allowed) {
            if (!is_string($allowed) || !$this->isHttpsOrigin($allowed)) {
                return false;
            }
        }
        foreach ($methods as $method) {
            if (!is_string($method) || $method === '*' || strtoupper($method) !== $method) {
                return false;
            }
        }

        return in_array($origin, $origins, true) && in_array('PUT', $methods, true);
    }

    private function isHttpsOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || str_contains($parts['host'], '*')
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            return false;
        }

        return array_diff(array_keys($parts), ['scheme', 'host', 'port']) === [];
    }

    /** @param array<string, mixed> $put */
    private function validPut(array $put): bool
    {
        $tenant = $put['tenant_id'] ?? null;
        $expectedTenant = $put['expected_tenant_id'] ?? null;
        $ttl = $put['ttl_seconds'] ?? null;
        $issuedAt = $put['issued_at'] ?? null;
        $expiresAt = $put['expires_at'] ?? null;
        $url = $put['url'] ?? null;

        if (($put['method'] ?? null) !== 'PUT'
            || ($put['signature_present'] ?? null) !== true
            || !is_string($tenant) || !is_string($expectedTenant)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tenant)
            || $tenant !== $expectedTenant
            || !is_int($ttl) || $ttl < 1 || $ttl > 3600
            || !is_int($issuedAt) || $issuedAt < 1
            || !is_int($expiresAt) || $issuedAt > PHP_INT_MAX - $ttl
            || $expiresAt !== $issuedAt + $ttl
            || !is_string($url)
        ) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !is_string($parts['query'] ?? null) || $parts['query'] === ''
            || !is_string($parts['path'] ?? null)
        ) {
            return false;
        }

        // Preserve the leading slash: trimming it would hide a double-slash path.
        $path = $parts['path'];
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        $segments = explode('/', substr($path, 1));
        foreach ($segments as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '' || $decoded === '.' || $decoded === '..'
                || str_contains($decoded, '/') || str_contains($decoded, '\\')
            ) {
                return false;
            }
        }

        return count($segments) >= 3
            && $segments[0] === 'tenant'
            && rawurldecode($segments[1]) === $tenant
            && $segments[2] !== '';
    }
}
