<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GrindFlow\Http\BoundedJsonBody;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Plans\PlanCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Platform-level plan configuration, not a tenant administration feature.
 * Requires the additive migration currently outside issue #784's V3 claims.
 * This is an offline candidate and MUST NOT be deployed before schema+UI integration.
 */
final class PlanLimitsAdminController extends AbstractController
{
    private const COLUMNS = PlanCatalog::DIMENSIONS;
    private const LIMIT = 100;

    #[Route('/api/admin/plans', name: 'grindflow_plan_limits_list', methods: ['GET'])]
    public function list(Request $request, Connection $db, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof IdentityUser || !$actor->isActive()) {
            return $this->error(401, 'authentication_required');
        }
        if (!$this->isGlobalAdmin($db, $actor->id())) {
            return $this->error(403, 'platform_admin_required');
        }
        if (!$this->schemaReady($db)) {
            return $this->error(503, 'plan_schema_required');
        }
        $rows = $db->fetchAllAssociative(
            'SELECT plan_key, storage_bytes, traffic_bytes, queued_jobs, api_calls, revision, updated_at '
            .'FROM gf_plan_limits ORDER BY plan_key LIMIT '.(self::LIMIT + 1),
        );
        if (count($rows) > self::LIMIT) {
            return $this->error(409, 'plan_catalog_too_large');
        }
        return $this->privateJson(['data' => [
            'plans' => array_map(self::publicRow(...), $rows),
            'csrf' => (string) $csrf->getToken('grindflow_plan_limits')->getValue(),
        ]]);
    }

    #[Route('/api/admin/plans/{plan}', name: 'grindflow_plan_limits_put', methods: ['PUT'], requirements: ['plan' => '[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}'])]
    public function put(string $plan, Request $request, Connection $db): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof IdentityUser || !$actor->isActive()) {
            return $this->error(401, 'authentication_required');
        }
        // Do not disclose plan-schema readiness to a user without global authority.
        // The locked transaction rechecks the same permission before mutation.
        if (!$this->isGlobalAdmin($db, $actor->id())) {
            return $this->error(403, 'platform_admin_required');
        }
        if (!$this->isCsrfTokenValid('grindflow_plan_limits', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf');
        }
        if (strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
            return $this->error(415, 'json_required');
        }
        $patch = self::parseChange($plan, BoundedJsonBody::decode($request));
        if ($patch === null) {
            return $this->error(422, 'invalid_plan_limits');
        }
        if (!$this->schemaReady($db)) {
            return $this->error(503, 'plan_schema_required');
        }
        try {
            $result = $db->transactional(function (Connection $transaction) use ($actor, $plan, $patch): array {
                // Reread current platform authority *under lock* for every mutation.
                if (!$this->isGlobalAdmin($transaction, $actor->id(), true)) {
                    return ['status' => 403, 'code' => 'platform_admin_required'];
                }
                $current = $transaction->fetchAssociative(
                    'SELECT plan_key, storage_bytes, traffic_bytes, queued_jobs, api_calls, revision, updated_at '
                    .'FROM gf_plan_limits WHERE plan_key = :plan FOR UPDATE', ['plan' => $plan],
                );
                $revision = $current === false ? 0 : (int) $current['revision'];
                if ($revision !== $patch['expected_revision']) {
                    return ['status' => 409, 'code' => 'stale_revision'];
                }
                $before = $current === false
                    ? array_fill_keys(self::COLUMNS, null)
                    : self::values($current);
                if ($current !== false && $before === $patch['limits']) {
                    return ['status' => 200, 'row' => self::publicRow($current), 'changed' => false];
                }
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                $params = ['plan' => $plan, 'actor' => $actor->id(), 'now' => $now];
                foreach (self::COLUMNS as $name) {
                    $params[$name] = $patch['limits'][$name];
                }
                if ($current === false) {
                    $transaction->executeStatement(
                        'INSERT INTO gf_plan_limits (plan_key, storage_bytes, traffic_bytes, queued_jobs, api_calls, revision, updated_by, updated_at) '
                        .'VALUES (:plan, :storage_bytes, :traffic_bytes, :queued_jobs, :api_calls, 1, :actor, :now)',
                        $params,
                    );
                } else {
                    $params['expected_revision'] = $revision;
                    $changed = $transaction->executeStatement(
                        'UPDATE gf_plan_limits SET storage_bytes=:storage_bytes, traffic_bytes=:traffic_bytes, '
                        .'queued_jobs=:queued_jobs, api_calls=:api_calls, revision=revision+1, updated_by=:actor, updated_at=:now '
                        .'WHERE plan_key=:plan AND revision=:expected_revision', $params,
                    );
                    if ($changed !== 1) {
                        return ['status' => 409, 'code' => 'stale_revision'];
                    }
                }
                $transaction->executeStatement(
                    'INSERT INTO gf_plan_limit_changes (id, plan_key, actor_id, before_json, after_json, '
                    .'before_revision, after_revision, changed_at) '
                    .'VALUES (:id, :plan, :actor, :before_json, :after_json, :before_revision, :after_revision, :changed_at)',
                    [
                        'id' => Uuid::v7()->toRfc4122(), 'plan' => $plan, 'actor' => $actor->id(),
                        'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                        'after_json' => json_encode($patch['limits'], JSON_THROW_ON_ERROR),
                        'before_revision' => $revision, 'after_revision' => $revision + 1,
                        'changed_at' => $now,
                    ],
                );
                return ['status' => 200, 'row' => [
                    'plan' => $plan, 'limits' => $patch['limits'], 'revision' => $revision + 1,
                    'updated_at' => $now,
                ], 'changed' => true];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->error(409, 'stale_revision');
        } catch (Throwable) {
            // Fail closed; never return DB exceptions, credentials or query details.
            return $this->error(503, 'plan_update_unavailable');
        }
        if (($result['status'] ?? 503) !== 200) {
            return $this->error((int) ($result['status'] ?? 503), (string) ($result['code'] ?? 'plan_update_unavailable'));
        }
        return $this->privateJson(['data' => ['plan' => $result['row'], 'changed' => $result['changed']]]);
    }

    /** Validate the complete replacement before touching the DB. Null means UNKNOWN. */
    public static function parseChange(string $plan, ?array $body): ?array
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', $plan) !== 1
            || $body === null || count($body) !== 2 || !array_key_exists('expected_revision', $body)
            || !array_key_exists('limits', $body) || !is_int($body['expected_revision'])
            || $body['expected_revision'] < 0 || $body['expected_revision'] === PHP_INT_MAX || !is_array($body['limits'])
            || count($body['limits']) !== count(self::COLUMNS)) {
            return null;
        }
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $body['limits'])) {
                return null;
            }
            $value = $body['limits'][$column];
            if ($value !== null && (!is_int($value) || $value < 0)) {
                return null;
            }
        }
        try {
            (new PlanCatalog())->configure($plan, $body['limits']);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return ['expected_revision' => $body['expected_revision'], 'limits' => $body['limits']];
    }

    public static function canEditPlatformRole(?string $role): bool
    {
        return $role === 'admin'; // migration allows admin/studio/editor/model; only platform admin.
    }

    private function isGlobalAdmin(Connection $db, string $actorId, bool $lock = false): bool
    {
        $role = $db->fetchOne(
            'SELECT platform_role FROM gf_identity_users WHERE id = :actor AND is_active = 1'.($lock ? ' FOR UPDATE' : ''),
            ['actor' => $actorId],
        );
        return self::canEditPlatformRole(is_string($role) ? $role : null);
    }

    private function schemaReady(Connection $db): bool
    {
        try {
            return $db->createSchemaManager()->tablesExist(['gf_plan_limits', 'gf_plan_limit_changes']);
        } catch (Throwable) {
            return false;
        }
    }

    private static function values(array $row): array
    {
        $result = [];
        foreach (self::COLUMNS as $name) {
            $result[$name] = $row[$name] === null ? null : (int) $row[$name];
        }
        return $result;
    }

    private static function publicRow(array $row): array
    {
        return [
            'plan' => (string) $row['plan_key'], 'limits' => self::values($row),
            'revision' => (int) $row['revision'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function error(int $status, string $code): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code]], $status);
    }

    private function privateJson(array $body, int $status = 200): JsonResponse
    {
        $response = $this->json($body, $status);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
