<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Request;

final class StaffOpsRequestAuthenticator
{
    private const CLOCK_SKEW = 300;
    private const NONCE_TTL = 600;
    private const RATE_WINDOW = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly string $allowlist,
        private readonly int $rateLimit = 60,
    ) {
    }

    public function enabled(): bool
    {
        return $this->keyId !== ''
            && $this->secret !== ''
            && trim($this->allowlist) !== '';
    }

    public function authenticate(Request $request): string
    {
        if (!$this->enabled()) {
            throw new StaffOpsAuthException(404, 'ops_not_found');
        }
        if (!$request->isSecure()) {
            throw new StaffOpsAuthException(404, 'ops_not_found');
        }

        $sourceIp = (string) ($request->getClientIp() ?? '');
        $allowed = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $this->allowlist),
        )));
        if ($sourceIp === '' || !in_array($sourceIp, $allowed, true)) {
            throw new StaffOpsAuthException(403, 'source_not_allowed');
        }

        $keyId = trim((string) $request->headers->get('X-Factory-Key-Id', ''));
        $timestamp = trim((string) $request->headers->get('X-Factory-Timestamp', ''));
        $nonce = trim((string) $request->headers->get('X-Factory-Nonce', ''));
        $signature = trim((string) $request->headers->get('X-Factory-Signature', ''));

        if ($keyId !== $this->keyId
            || preg_match('/\A[0-9]{10,13}\z/D', $timestamp) !== 1
            || !self::validNonce($nonce)
            || preg_match('/\A[0-9a-f]{64}\z/D', $signature) !== 1) {
            throw new StaffOpsAuthException(401, 'invalid_signature');
        }

        $epoch = (int) $timestamp;
        if (abs(time() - $epoch) > self::CLOCK_SKEW) {
            throw new StaffOpsAuthException(401, 'stale_request');
        }

        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        $canonicalQuery = self::canonicalQuery($rawQuery);
        $path = $request->getPathInfo().($canonicalQuery === '' ? '' : '?'.$canonicalQuery);
        $bodyHash = hash('sha256', (string) $request->getContent());
        $canonical = self::canonicalRequest(
            $keyId,
            strtoupper($request->getMethod()),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        );
        $expected = hash_hmac('sha256', $canonical, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new StaffOpsAuthException(401, 'invalid_signature');
        }

        $this->consumeReplayAndRateLimit($keyId, $nonce, $sourceIp);

        return $keyId;
    }

    public static function canonicalRequest(
        string $keyId,
        string $method,
        string $pathWithSortedQuery,
        string $timestamp,
        string $nonce,
        string $bodySha256,
    ): string {
        return implode("\n", [
            $keyId,
            strtoupper($method),
            $pathWithSortedQuery,
            $timestamp,
            $nonce,
            $bodySha256,
        ]);
    }

    public static function canonicalQuery(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }
        if (str_contains($rawQuery, '+')) {
            throw new StaffOpsAuthException(400, 'invalid_query');
        }

        $pairs = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '' || substr_count($pair, '=') !== 1) {
                throw new StaffOpsAuthException(400, 'invalid_query');
            }
            [$rawName, $rawValue] = explode('=', $pair, 2);
            if ($rawName === '') {
                throw new StaffOpsAuthException(400, 'invalid_query');
            }
            foreach ([$rawName, $rawValue] as $raw) {
                if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                    throw new StaffOpsAuthException(400, 'invalid_query');
                }
            }
            $name = rawurldecode($rawName);
            $value = rawurldecode($rawValue);
            if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) {
                throw new StaffOpsAuthException(400, 'invalid_query');
            }
            $pairs[] = [rawurlencode($name), rawurlencode($value)];
        }

        usort($pairs, static function (array $left, array $right): int {
            return [$left[0], $left[1]] <=> [$right[0], $right[1]];
        });

        return implode('&', array_map(
            static fn (array $pair): string => $pair[0].'='.$pair[1],
            $pairs,
        ));
    }

    private static function validNonce(string $nonce): bool
    {
        if (preg_match('/\A[0-9a-fA-F]{32,128}\z/D', $nonce) === 1) {
            return true;
        }

        return strlen($nonce) >= 22
            && strlen($nonce) <= 172
            && preg_match('/\A[A-Za-z0-9_-]+\z/D', $nonce) === 1;
    }

    private function consumeReplayAndRateLimit(string $keyId, string $nonce, string $sourceIp): void
    {
        $now = time();
        $expires = gmdate('Y-m-d H:i:s', $now + self::NONCE_TTL);
        $window = gmdate('Y-m-d H:i:00', $now);

        $this->db->transactional(function (Connection $db) use (
            $keyId,
            $nonce,
            $sourceIp,
            $expires,
            $window,
            $now,
        ): void {
            $db->executeStatement(
                'DELETE FROM gf_ops_nonces WHERE expires_at < :now',
                ['now' => gmdate('Y-m-d H:i:s', $now)],
            );
            try {
                $db->insert('gf_ops_nonces', [
                    'key_id' => $keyId,
                    'nonce' => $nonce,
                    'expires_at' => $expires,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new StaffOpsAuthException(409, 'replay_detected');
            }

            $db->executeStatement(
                <<<'SQL'
                    INSERT INTO gf_ops_rate_limits (key_id, source_ip, window_start, hits)
                    VALUES (:key_id, :source_ip, :window_start, 1)
                    ON DUPLICATE KEY UPDATE hits = hits + 1
                    SQL,
                [
                    'key_id' => $keyId,
                    'source_ip' => $sourceIp,
                    'window_start' => $window,
                ],
            );
            $hits = (int) $db->fetchOne(
                'SELECT hits FROM gf_ops_rate_limits WHERE key_id = :key_id AND source_ip = :source_ip AND window_start = :window_start',
                [
                    'key_id' => $keyId,
                    'source_ip' => $sourceIp,
                    'window_start' => $window,
                ],
            );
            if ($hits > max(1, $this->rateLimit)) {
                throw new StaffOpsAuthException(429, 'rate_limited');
            }
        });
    }
}
