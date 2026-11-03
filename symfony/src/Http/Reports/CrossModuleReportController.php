<?php

declare(strict_types=1);

namespace GrindFlow\Http\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only S5 report contract joining Vault, Scheduling and Distribution.
 *
 * This is an offline slice. Routing activation requires a separate claimed
 * change to symfony/config/routes.yaml; no public endpoint is implied here.
 */
final class CrossModuleReportController extends AbstractController
{
    #[Route('/api/admin/reports/cross-module', name: 'grindflow_cross_module_report', methods: ['GET'])]
    public function __invoke(
        Request $request,
        MembershipContext $memberships,
        Connection $db,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->privateJson(['error' => ['code' => 'authentication_required']], 401);
        }

        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->privateJson(['error' => ['code' => 'organization_required']], 409);
        }
        $organization = $memberships->find($user->id(), $selected);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->privateJson(['error' => ['code' => 'organization_access_changed']], 403);
        }
        // Aggregated organization-wide publication outcomes are admin-only.
        if (!$memberships->permissions($organization['role'])['organization_manage']) {
            return $this->privateJson(['error' => ['code' => 'forbidden']], 403);
        }

        $query = $request->query->all();
        if (array_diff(array_keys($query), ['from', 'to']) !== []
            || !is_string($query['from'] ?? null)
            || !is_string($query['to'] ?? null)) {
            return $this->privateJson(['error' => ['code' => 'invalid_report_period']], 422);
        }

        try {
            $report = self::report(
                $db,
                (string) $organization['id'],
                $query['from'],
                $query['to'],
            );
        } catch (\InvalidArgumentException) {
            return $this->privateJson(['error' => ['code' => 'invalid_report_period']], 422);
        }

        return $this->privateJson(
            ['data' => $report],
            $report['ready'] ? 200 : 503,
        );
    }

    /**
     * @return array{ready: bool, period: array{from_utc: string, to_exclusive_utc: string}, totals: ?array{assets: int, scheduled: int, delivery_attempts: int, published: int}, reason?: string, provider_calls: bool}
     */
    public static function report(
        Connection $db,
        string $tenantId,
        string $fromUtc,
        string $toUtcExclusive,
    ): array {
        if (trim($tenantId) === '') {
            throw new \InvalidArgumentException('Tenant required.');
        }
        $utc = new DateTimeZone('UTC');
        $start = self::date($fromUtc, $utc);
        $end = self::date($toUtcExclusive, $utc);
        if ($start >= $end || $end->getTimestamp() - $start->getTimestamp() > 31 * 86400) {
            throw new \InvalidArgumentException('Invalid report period.');
        }

        $period = ['from_utc' => $fromUtc, 'to_exclusive_utc' => $toUtcExclusive];
        $params = [
            'tenant' => $tenantId,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];

        try {
            $assets = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_vault_assets asset '
                .'WHERE asset.organization_id = :tenant AND asset.deleted_at IS NULL '
                .'AND asset.created_at >= :start AND asset.created_at < :end',
                $params,
            );

            $scheduled = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM gf_schedule_drafts draft '
                .'INNER JOIN gf_vault_assets asset ON asset.id = draft.asset_id '
                .'AND asset.organization_id = draft.organization_id '
                .'WHERE draft.organization_id = :tenant AND asset.deleted_at IS NULL '
                .'AND draft.scheduled_at_utc >= :start AND draft.scheduled_at_utc < :end',
                $params,
            );

            // The idempotency key alone cannot grant ownership. Match both the
            // attempt and its referenced draft/asset to the SAME organization.
            $deliveries = $db->fetchAssociative(
                <<<'SQL'
                SELECT COUNT(*) AS attempts,
                       COALESCE(SUM(CASE WHEN attempt.status = 'published' THEN 1 ELSE 0 END), 0) AS published
                FROM gf_external_publication_attempts attempt
                INNER JOIN gf_schedule_drafts draft
                    ON draft.organization_id = attempt.organization_id
                   AND attempt.idempotency_key = CONCAT('schedule-draft:', draft.id, ':', attempt.provider, ':v1')
                   AND draft.delivery_provider = attempt.provider
                INNER JOIN gf_vault_assets asset
                    ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                WHERE attempt.organization_id = :tenant AND asset.deleted_at IS NULL
                  AND attempt.updated_at >= :start AND attempt.updated_at < :end
                SQL,
                $params,
            );
            if ($deliveries === false) {
                throw new \UnexpectedValueException('Missing aggregate row.');
            }

            return [
                'ready' => true,
                'period' => $period,
                'totals' => [
                    'assets' => $assets,
                    'scheduled' => $scheduled,
                    'delivery_attempts' => (int) $deliveries['attempts'],
                    'published' => (int) $deliveries['published'],
                ],
                'provider_calls' => false,
            ];
        } catch (DbalException | \UnexpectedValueException) {
            // Schema drift or read failure is not an empty report.
            return [
                'ready' => false,
                'period' => $period,
                'totals' => null,
                'reason' => 'report_source_unavailable',
                'provider_calls' => false,
            ];
        }
    }

    private static function date(string $value, DateTimeZone $utc): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid UTC date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid UTC date.');
        }

        return $date;
    }

    /** @param array<string, mixed> $data */
    private function privateJson(array $data, int $status): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
