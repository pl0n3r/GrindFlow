<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class StaffOpsIdempotency
{
    private const PRODUCT = 'grindflow';
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param callable(Connection): array{0:int,1:array<string,mixed>} $operation
     * @return array{0:int,1:array<string,mixed>}
     */
    public function execute(
        Request $request,
        string $actorKeyId,
        string $action,
        string $target,
        callable $operation,
    ): array {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if (preg_match('/\A[A-Za-z0-9._:-]{1,160}\z/D', $key) !== 1) {
            throw new StaffOpsAuthException(422, 'idempotency_key_required');
        }

        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        $query = StaffOpsRequestAuthenticator::canonicalQuery($rawQuery);
        $path = $request->getPathInfo().($query === '' ? '' : '?'.$query);
        $fingerprint = hash(
            'sha256',
            strtoupper($request->getMethod())."\n".$path."\n".hash('sha256', (string) $request->getContent()),
        );
        $now = time();

        return $this->db->transactional(function (Connection $db) use (
            $actorKeyId,
            $action,
            $target,
            $key,
            $fingerprint,
            $operation,
            $now,
        ): array {
            $db->executeStatement(
                'DELETE FROM gf_ops_idempotency WHERE expires_at <= :now',
                ['now' => gmdate('Y-m-d H:i:s', $now)],
            );

            $inserted = $db->executeStatement(
                <<<'SQL'
                    INSERT INTO gf_ops_idempotency (
                        product, actor_key_id, action, target, idempotency_key,
                        request_fingerprint, response_status, response_json,
                        expires_at, created_at
                    ) VALUES (
                        :product, :actor, :action, :target, :idem,
                        :fingerprint, NULL, NULL, :expires, :created
                    )
                    ON DUPLICATE KEY UPDATE idempotency_key = VALUES(idempotency_key)
                    SQL,
                [
                    'product' => self::PRODUCT,
                    'actor' => $actorKeyId,
                    'action' => $action,
                    'target' => $target,
                    'idem' => $key,
                    'fingerprint' => $fingerprint,
                    'expires' => gmdate('Y-m-d H:i:s', $now + self::RETENTION_SECONDS),
                    'created' => gmdate('Y-m-d H:i:s', $now),
                ],
            ) === 1;

            $row = $db->fetchAssociative(
                <<<'SQL'
                    SELECT request_fingerprint, response_status, response_json
                    FROM gf_ops_idempotency
                    WHERE product = :product AND actor_key_id = :actor
                      AND action = :action AND target = :target
                      AND idempotency_key = :idem
                    FOR UPDATE
                    SQL,
                [
                    'product' => self::PRODUCT,
                    'actor' => $actorKeyId,
                    'action' => $action,
                    'target' => $target,
                    'idem' => $key,
                ],
            );
            if ($row === false) {
                throw new \RuntimeException('idempotency row disappeared');
            }
            if (!hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
                throw new StaffOpsAuthException(409, 'idempotency_conflict');
            }

            if (!$inserted && $row['response_status'] !== null && is_string($row['response_json'])) {
                $payload = json_decode($row['response_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \RuntimeException('invalid idempotency payload');
                }

                return [(int) $row['response_status'], $payload];
            }
            if (!$inserted) {
                throw new StaffOpsAuthException(409, 'idempotency_in_progress');
            }

            [$status, $payload] = $operation($db);
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $db->update('gf_ops_idempotency', [
                'response_status' => $status,
                'response_json' => $encoded,
            ], [
                'product' => self::PRODUCT,
                'actor_key_id' => $actorKeyId,
                'action' => $action,
                'target' => $target,
                'idempotency_key' => $key,
            ]);

            return [$status, $payload];
        });
    }
}
