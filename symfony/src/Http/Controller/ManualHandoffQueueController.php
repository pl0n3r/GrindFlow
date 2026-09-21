<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ManualHandoffQueueController extends AbstractController
{
    #[Route('/api/admin/manual-handoff-queue', name: 'grindflow_manual_handoff_queue', methods: ['GET'])]
    public function __invoke(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $schema = $db->createSchemaManager();
        if (!$schema->tablesExist(['gf_manual_destinations', 'gf_manual_handoff_events'])) {
            return $this->privateJson(['data' => [
                'ready' => false,
                'items' => [],
                'total' => 0,
                'limit' => 30,
                'can_manage' => false,
            ]]);
        }

        $organization = $context['organization']['id'];
        $params = ['organization' => $organization];
        $latest = <<<'SQL'
            SELECT event.id
            FROM gf_manual_handoff_events event
            WHERE event.organization_id = draft.organization_id
              AND event.draft_id = draft.id
            ORDER BY event.created_at DESC, event.id DESC
            LIMIT 1
            SQL;
        $scope = <<<SQL
            FROM gf_schedule_drafts draft
            INNER JOIN gf_vault_assets asset
              ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
            INNER JOIN gf_manual_handoff_events handoff
              ON handoff.id = ($latest)
            LEFT JOIN gf_manual_destinations destination
              ON destination.id = handoff.destination_id
             AND destination.organization_id = handoff.organization_id
            WHERE draft.organization_id = :organization
              AND draft.status = 'draft'
              AND handoff.action IN ('prepare', 'fail')
            SQL;

        $rows = $db->fetchAllAssociative(
            'SELECT draft.id, draft.asset_id, asset.original_name AS asset_name, '
            .'draft.scheduled_at_utc, draft.local_date, draft.local_time, draft.timezone, '
            .'handoff.action, handoff.created_at AS handoff_updated_at, '
            .'destination.id AS destination_id, destination.label AS destination_label, '
            .'destination.disabled_at AS destination_disabled_at '
            .$scope
            ." ORDER BY (draft.scheduled_at_utc <= UTC_TIMESTAMP()) DESC, draft.scheduled_at_utc, draft.id LIMIT 30",
            $params,
        );
        $total = (int) $db->fetchOne('SELECT COUNT(*) '.$scope, $params);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->privateJson(['data' => [
            'ready' => true,
            'items' => array_map(static function (array $row) use ($now): array {
                $scheduled = new \DateTimeImmutable((string) $row['scheduled_at_utc'], new \DateTimeZone('UTC'));

                return [
                    'draft_id' => (string) $row['id'],
                    'asset_id' => (string) $row['asset_id'],
                    'asset_name' => (string) $row['asset_name'],
                    'scheduled_at_utc' => str_replace(' ', 'T', (string) $row['scheduled_at_utc']).'Z',
                    'local_date' => (string) $row['local_date'],
                    'local_time' => (string) $row['local_time'],
                    'timezone' => (string) $row['timezone'],
                    'status' => $row['action'] === 'prepare' ? 'prepared' : 'failed',
                    'due' => $scheduled <= $now,
                    'handoff_updated_at' => (string) $row['handoff_updated_at'],
                    'destination' => $row['destination_id'] === null ? null : [
                        'id' => (string) $row['destination_id'],
                        'label' => (string) $row['destination_label'],
                        'active' => $row['destination_disabled_at'] === null,
                    ],
                ];
            }, $rows),
            'total' => $total,
            'limit' => 30,
            'can_manage' => $memberships->permissions($context['organization']['role'])['manual_handoff_manage'],
            'provider_calls' => false,
        ]]);
    }

    /** @return array{user: IdentityUser, organization: array{id: string, name: string, role: string}}|JsonResponse */
    private function context(Request $request, MembershipContext $memberships): array|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }
        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización para continuar.');
        }
        $organization = $memberships->find($user->id(), $selected);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }

        return ['user' => $user, 'organization' => $organization];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
