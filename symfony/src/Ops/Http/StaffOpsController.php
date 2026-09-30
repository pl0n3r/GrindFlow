<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Http;

use Doctrine\DBAL\Connection;
use GrindFlow\Ops\Security\StaffOpsAuthException;
use GrindFlow\Ops\Security\StaffOpsIdempotency;
use GrindFlow\Ops\Security\StaffOpsRequestAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class StaffOpsController
{
    private const STAFF_ROLES = ['admin', 'editor'];

    public function __construct(
        private readonly Connection $db,
        private readonly StaffOpsRequestAuthenticator $authenticator,
        private readonly StaffOpsIdempotency $idempotency,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        return $this->guard($request, function (string $actor, string $requestId) use ($request): JsonResponse {
            $query = $request->query->all();
            $q = $query['q'] ?? null;
            if (!is_string($q) || mb_strlen($q) < 2 || mb_strlen($q) > 120) {
                throw new StaffOpsAuthException(422, 'invalid_query');
            }

            $limit = 25;
            if (array_key_exists('limit', $query)) {
                $parsed = filter_var($query['limit'], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 100],
                ]);
                if (!is_int($parsed)) {
                    throw new StaffOpsAuthException(422, 'invalid_limit');
                }
                $limit = $parsed;
            }

            $cursor = null;
            if (array_key_exists('cursor', $query)) {
                if (!is_string($query['cursor']) || $query['cursor'] === '') {
                    throw new StaffOpsAuthException(422, 'invalid_cursor');
                }
                $cursor = self::decodeCursor($query['cursor']);
                if ($cursor === null) {
                    throw new StaffOpsAuthException(422, 'invalid_cursor');
                }
            }

            $needle = '%'.strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $params = ['q' => $needle];
            $after = '';
            if ($cursor !== null) {
                $after = ' AND id > :cursor';
                $params['cursor'] = $cursor;
            }
            $rows = $this->db->fetchAllAssociative(
                <<<'SQL'
                    SELECT id, name, email, platform_role, is_active, last_access_at
                    FROM gf_identity_users
                    WHERE platform_role IN ('admin','editor')
                      AND (name LIKE :q ESCAPE '!' OR email LIKE :q ESCAPE '!')
                    SQL
                .$after
                .' ORDER BY id ASC LIMIT '.($limit + 1),
                $params,
            );

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            $items = array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'email_masked' => self::maskEmail((string) $row['email']),
                'role' => (string) $row['platform_role'],
                'status' => (int) $row['is_active'] === 1 ? 'active' : 'suspended',
                'last_access_at' => $row['last_access_at'] === null ? null : (string) $row['last_access_at'],
            ], $rows);
            $nextCursor = $hasMore && $rows !== []
                ? self::encodeCursor((string) $rows[array_key_last($rows)]['id'])
                : null;

            $this->audit($this->db, 'search_staff', $actor, null, 'success', $requestId);

            return $this->json(['data' => ['items' => $items, 'next_cursor' => $nextCursor]]);
        });
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->guard($request, function (string $actor, string $requestId): JsonResponse {
            $counts = $this->db->fetchAssociative(
                <<<'SQL'
                    SELECT
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS suspended
                    FROM gf_identity_users
                    WHERE platform_role IN ('admin','editor')
                    SQL,
            ) ?: ['active' => 0, 'suspended' => 0];
            $failed = (int) $this->db->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM gf_ops_staff_login_failures f
                    INNER JOIN gf_identity_users u ON u.id = f.user_id
                    WHERE u.platform_role IN ('admin','editor')
                      AND f.occurred_at >= :cutoff
                    SQL,
                ['cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)],
            );
            $this->audit($this->db, 'staff_summary', $actor, null, 'success', $requestId);

            return $this->json(['data' => [
                'active' => (int) ($counts['active'] ?? 0),
                'suspended' => (int) ($counts['suspended'] ?? 0),
                'recent_failed_logins' => $failed,
            ]]);
        });
    }

    public function invite(Request $request): JsonResponse
    {
        return $this->guard($request, function (string $actor, string $requestId) use ($request): JsonResponse {
            $body = self::body($request);
            if ($body === null || !self::hasOnlyKeys($body, ['email', 'name', 'role'])) {
                throw new StaffOpsAuthException(422, 'invalid_payload');
            }
            $name = $body['name'] ?? null;
            $email = $body['email'] ?? null;
            $role = $body['role'] ?? null;
            if (!is_string($name) || mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 120
                || !is_string($email) || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || !is_string($role) || !in_array($role, self::STAFF_ROLES, true)) {
                throw new StaffOpsAuthException(422, 'invalid_payload');
            }
            $name = trim($name);
            $email = strtolower(trim($email));
            $target = hash('sha256', $email);

            [$status, $payload] = $this->idempotency->execute(
                $request,
                $actor,
                'invite_staff',
                $target,
                function (Connection $db) use ($actor, $requestId, $name, $email, $role): array {
                    if ($db->fetchOne(
                        'SELECT id FROM gf_identity_users WHERE email = :email LIMIT 1',
                        ['email' => $email],
                    ) !== false) {
                        throw new StaffOpsAuthException(409, 'staff_invite_conflict');
                    }

                    $id = Uuid::v7()->toRfc4122();
                    $now = gmdate('Y-m-d H:i:s');
                    $db->insert('gf_identity_users', [
                        'id' => $id,
                        'name' => $name,
                        'email' => $email,
                        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        'platform_role' => $role,
                        'is_active' => 1,
                        'session_generation' => 0,
                        'last_access_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->queueReset($db, $id, $now);
                    $this->audit($db, 'invite_staff', $actor, $id, 'success', $requestId);

                    return [201, ['data' => [
                        'id' => $id,
                        'status' => 'active',
                        'invitation_sent' => true,
                    ]]];
                },
            );

            return $this->json($payload, $status);
        });
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        return $this->stateMutation($request, $id, 'suspend_staff', false);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        return $this->stateMutation($request, $id, 'reactivate_staff', true);
    }

    public function changeRole(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, function (string $actor, string $requestId) use ($request, $id): JsonResponse {
            $body = self::body($request);
            if ($body === null || !self::hasOnlyKeys($body, ['role'])
                || !is_string($body['role'] ?? null)
                || !in_array($body['role'], self::STAFF_ROLES, true)) {
                throw new StaffOpsAuthException(422, 'invalid_role');
            }
            $nextRole = $body['role'];

            [$status, $payload] = $this->idempotency->execute(
                $request,
                $actor,
                'change_staff_role',
                $id,
                function (Connection $db) use ($actor, $requestId, $id, $nextRole): array {
                    $staff = self::staffForUpdate($db, $id);
                    if ($staff === null) {
                        throw new StaffOpsAuthException(404, 'staff_not_found');
                    }
                    if ((string) $staff['platform_role'] !== $nextRole) {
                        $db->executeStatement(
                            <<<'SQL'
                                UPDATE gf_identity_users
                                SET platform_role = :role,
                                    session_generation = session_generation + 1,
                                    updated_at = :updated
                                WHERE id = :id
                                SQL,
                            ['role' => $nextRole, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $id],
                        );
                    }
                    $this->audit($db, 'change_staff_role', $actor, $id, 'success', $requestId);

                    return [200, ['data' => ['id' => $id, 'role' => $nextRole]]];
                },
            );

            return $this->json($payload, $status);
        });
    }

    public function passwordReset(Request $request, string $id): JsonResponse
    {
        return $this->guard($request, function (string $actor, string $requestId) use ($request, $id): JsonResponse {
            self::assertEmptyBody($request);

            [$status, $payload] = $this->idempotency->execute(
                $request,
                $actor,
                'request_staff_password_reset',
                $id,
                function (Connection $db) use ($actor, $requestId, $id): array {
                    $staff = self::staffForUpdate($db, $id);
                    if ($staff === null) {
                        throw new StaffOpsAuthException(404, 'staff_not_found');
                    }
                    if ((int) $staff['is_active'] !== 1) {
                        throw new StaffOpsAuthException(409, 'staff_state_conflict');
                    }

                    $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
                    $this->queueReset($db, $id, gmdate('Y-m-d H:i:s'));
                    $this->audit($db, 'request_staff_password_reset', $actor, $id, 'success', $requestId);

                    return [202, ['data' => ['id' => $id, 'reset_sent' => true]]];
                },
            );

            return $this->json($payload, $status);
        });
    }

    private function stateMutation(Request $request, string $id, string $action, bool $activate): JsonResponse
    {
        return $this->guard($request, function (string $actor, string $requestId) use ($request, $id, $action, $activate): JsonResponse {
            if ($activate) {
                self::assertEmptyBody($request);
            } else {
                $body = self::body($request);
                $reason = $body['reason_code'] ?? null;
                if ($body === null
                    || !self::hasOnlyKeys($body, ['reason_code'])
                    || !is_string($reason)
                    || preg_match('/\\A[a-z0-9][a-z0-9._:-]{0,63}\\z/D', $reason) !== 1) {
                    throw new StaffOpsAuthException(422, 'invalid_reason_code');
                }
            }

            [$status, $payload] = $this->idempotency->execute(
                $request,
                $actor,
                $action,
                $id,
                function (Connection $db) use ($actor, $requestId, $id, $action, $activate): array {
                    $staff = self::staffForUpdate($db, $id);
                    if ($staff === null) {
                        throw new StaffOpsAuthException(404, 'staff_not_found');
                    }
                    if ((int) $staff['is_active'] === ($activate ? 1 : 0)) {
                        throw new StaffOpsAuthException(409, 'staff_state_conflict');
                    }

                    $db->executeStatement(
                        <<<'SQL'
                            UPDATE gf_identity_users
                            SET is_active = :active,
                                session_generation = session_generation + 1,
                                updated_at = :updated
                            WHERE id = :id
                            SQL,
                        ['active' => $activate ? 1 : 0, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $id],
                    );
                    if (!$activate) {
                        $db->delete('gf_password_reset_tokens', ['user_id' => $id]);
                        $db->delete('gf_password_recovery_outbox', ['user_id' => $id]);
                    }
                    $this->audit($db, $action, $actor, $id, 'success', $requestId);

                    return [200, ['data' => [
                        'id' => $id,
                        'status' => $activate ? 'active' : 'suspended',
                    ]]];
                },
            );

            return $this->json($payload, $status);
        });
    }

    /** @param callable(string,string): JsonResponse $action */
    private function guard(Request $request, callable $action): JsonResponse
    {
        try {
            $actor = $this->authenticator->authenticate($request);

            return $action($actor, Uuid::v7()->toRfc4122());
        } catch (StaffOpsAuthException $exception) {
            return $this->json(['error' => ['code' => $exception->errorCode]], $exception->status);
        }
    }

    private function queueReset(Connection $db, string $userId, string $now): void
    {
        $db->executeStatement(
            <<<'SQL'
                INSERT INTO gf_password_recovery_outbox (
                    id, user_id, kind, available_at, claimed_at, delivered_at,
                    attempts, last_error_code, created_at, updated_at
                ) VALUES (
                    :id, :user_id, 'reset', :now, NULL, NULL,
                    0, NULL, :now, :now
                )
                ON DUPLICATE KEY UPDATE
                    id = VALUES(id),
                    available_at = VALUES(available_at),
                    claimed_at = NULL,
                    delivered_at = NULL,
                    attempts = 0,
                    last_error_code = NULL,
                    created_at = VALUES(created_at),
                    updated_at = VALUES(updated_at)
                SQL,
            ['id' => Uuid::v7()->toRfc4122(), 'user_id' => $userId, 'now' => $now],
        );
    }

    private function audit(
        Connection $db,
        string $action,
        string $actor,
        ?string $staffId,
        string $result,
        string $requestId,
    ): void {
        $db->insert('gf_ops_audit', [
            'id' => Uuid::v7()->toRfc4122(),
            'action' => $action,
            'actor_key_id' => $actor,
            'staff_id' => $staffId,
            'result' => $result,
            'request_id' => $requestId,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private static function body(Request $request): ?array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > 4096 || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function assertEmptyBody(Request $request): void
    {
        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return;
        }
        $decoded = self::body($request);
        if ($decoded !== []) {
            throw new StaffOpsAuthException(422, 'invalid_payload');
        }
    }

    /** @param array<string,mixed> $body @param list<string> $expected */
    private static function hasOnlyKeys(array $body, array $expected): bool
    {
        $keys = array_keys($body);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    /** @return array<string,mixed>|null */
    private static function staffForUpdate(Connection $db, string $id): ?array
    {
        $row = $db->fetchAssociative(
            <<<'SQL'
                SELECT id, platform_role, is_active
                FROM gf_identity_users
                WHERE id = :id AND platform_role IN ('admin','editor')
                FOR UPDATE
                SQL,
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    private static function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $cursor): ?string
    {
        $padding = str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $decoded = base64_decode(strtr($cursor, '-_', '+/').$padding, true);
        if (!is_string($decoded)
            || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $decoded) !== 1) {
            return null;
        }

        return strtolower($decoded);
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $first = $local === '' ? '*' : mb_substr($local, 0, 1);

        return $first.'***@'.$domain;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
