<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Scheduling\WeeklySlotCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * S4 internal schedule drafts, deliberately without external publication.
 *
 * A persisted draft reserves one place in an S3 weekly slot. It never creates
 * a distribution attempt, queue job, provider call, or publication permission.
 */
final class ScheduleDraftController extends AbstractController
{
    /** Read a bounded tenant-scoped agenda, including cancelled draft history. */
    #[Route('/api/admin/schedules', name: 'grindflow_schedule_drafts_list', methods: ['GET'])]
    public function list(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $params = [
            'organization' => $context['organization']['id'],
            'user' => $context['user']->id(),
        ];
        $handoffReady = $db->createSchemaManager()->tablesExist(['gf_manual_handoff_events']);
        $handoffSelect = $handoffReady
            ? <<<'SQL'
                , (
                    SELECT handoff.action
                    FROM gf_manual_handoff_events handoff
                    WHERE handoff.organization_id = draft.organization_id
                      AND handoff.draft_id = draft.id
                    ORDER BY handoff.created_at DESC, handoff.id DESC
                    LIMIT 1
                ) AS manual_handoff_action,
                (
                    SELECT handoff.created_at
                    FROM gf_manual_handoff_events handoff
                    WHERE handoff.organization_id = draft.organization_id
                      AND handoff.draft_id = draft.id
                    ORDER BY handoff.created_at DESC, handoff.id DESC
                    LIMIT 1
                ) AS manual_handoff_updated_at
                SQL
            : ', NULL AS manual_handoff_action, NULL AS manual_handoff_updated_at';
        $scope = <<<'SQL'
            FROM gf_schedule_drafts draft
            INNER JOIN gf_vault_assets asset
                ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
            INNER JOIN gf_identity_memberships membership
                ON membership.organization_id = draft.organization_id
            INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
            WHERE draft.organization_id = :organization AND membership.user_id = :user
              AND actor.is_active = 1
            SQL;
        $rows = $db->fetchAllAssociative(
            'SELECT draft.*, asset.original_name AS asset_name'.$handoffSelect.' '.$scope
            .' ORDER BY draft.created_at DESC, draft.id DESC LIMIT 30',
            $params,
        );
        $total = (int) $db->fetchOne('SELECT COUNT(*) '.$scope, $params);

        return $this->privateJson(['data' => [
            'drafts' => array_map($this->publicDraft(...), $rows),
            'total' => $total,
            'limit' => 30,
            'manual_handoff_ready' => $handoffReady,
            'mode' => 'review_only',
            'can_publish' => false,
        ]]);
    }

    /** Reserve exactly one current server-derived weekly slot for an eligible asset. */
    #[Route('/api/admin/schedules', name: 'grindflow_schedule_drafts_create', methods: ['POST'])]
    public function create(
        Request $request,
        MembershipContext $memberships,
        Connection $db,
        WeeklySlotCalculator $calculator,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'schedule_management_forbidden', 'Tu rol no permite preparar borradores.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_schedule_draft',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->error(422, 'invalid_schedule', 'Selecciona un recurso y un horario.');
        }
        $keys = array_keys($body);
        sort($keys);
        if ($keys !== ['asset_id', 'scheduled_at_utc']
            || !is_string($body['asset_id']) || !Uuid::isValid($body['asset_id'])
            || !is_string($body['scheduled_at_utc'])
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D', $body['scheduled_at_utc']) !== 1) {
            return $this->error(422, 'invalid_schedule', 'Selecciona un recurso y un slot UTC válido.');
        }

        $assetId = $body['asset_id'];
        $utc = $body['scheduled_at_utc'];
        $result = $db->transactional(
            function (Connection $db) use ($context, $assetId, $utc, $calculator): array {
                $organization = $context['organization']['id'];
                $user = $context['user']->id();

                // Serialize all draft creation per organization: capacity and
                // same-asset checks must be atomic across concurrent requests.
                if ($db->fetchOne(
                    'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                    ['organization' => $organization],
                ) === false) {
                    return ['status' => 'revoked'];
                }
                if ($db->fetchOne(
                    <<<'SQL'
                        SELECT membership.id
                        FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = :organization
                          AND membership.user_id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                        FOR UPDATE
                        SQL,
                    ['organization' => $organization, 'user' => $user],
                ) === false) {
                    return ['status' => 'revoked'];
                }
                $asset = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT id, original_name, usage_scope
                        FROM gf_vault_assets
                        WHERE id = :asset AND organization_id = :organization
                          AND deleted_at IS NULL
                        FOR UPDATE
                        SQL,
                    ['organization' => $organization, 'asset' => $assetId],
                );
                if ($asset === false) {
                    return ['status' => 'missing'];
                }
                $rule = $db->fetchAssociative(
                    'SELECT * FROM gf_content_rules WHERE organization_id = :organization FOR UPDATE',
                    ['organization' => $organization],
                );

                $reasons = [];
                if ($rule === false || $rule['mode'] !== 'review_only') {
                    $reasons[] = 'weekly_rule_missing';
                }
                if ($asset['usage_scope'] === 'unclassified') {
                    $reasons[] = 'classification_missing';
                } elseif ($asset['usage_scope'] === 'internal_only') {
                    $reasons[] = 'internal_only';
                } elseif ($asset['usage_scope'] === 'needs_review') {
                    $review = $db->fetchOne(
                        <<<'SQL'
                            SELECT action FROM gf_content_review_events
                            WHERE organization_id = :organization AND asset_id = :asset
                            ORDER BY created_at DESC, id DESC LIMIT 1
                            SQL,
                        ['organization' => $organization, 'asset' => $assetId],
                    );
                    if ($review !== 'approve') {
                        $reasons[] = 'content_review_required';
                    }
                } else {
                    $reasons[] = 'classification_missing';
                }
                $authorization = $db->fetchOne(
                    <<<'SQL'
                        SELECT action FROM gf_distribution_authorization_events
                        WHERE organization_id = :organization AND asset_id = :asset
                        ORDER BY created_at DESC, id DESC LIMIT 1
                        SQL,
                    ['organization' => $organization, 'asset' => $assetId],
                );
                if ($authorization !== 'grant') {
                    $reasons[] = 'distribution_authorization_missing';
                }
                if ($reasons !== []) {
                    return ['status' => 'blocked', 'reasons' => $reasons];
                }

                $slot = null;
                foreach ($calculator->upcoming($rule) as $candidate) {
                    if (hash_equals($candidate['scheduled_at_utc'], $utc)) {
                        $slot = $candidate;
                        break;
                    }
                }
                if ($slot === null) {
                    return ['status' => 'stale_slot'];
                }

                $dateTime = str_replace(['T', 'Z'], [' ', ''], $utc);
                $params = [
                    'organization' => $organization,
                    'asset' => $assetId,
                    'scheduled' => $dateTime,
                ];
                $existing = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT draft.*, asset.original_name AS asset_name
                        FROM gf_schedule_drafts draft
                        INNER JOIN gf_vault_assets asset
                          ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                        WHERE draft.organization_id = :organization AND draft.asset_id = :asset
                          AND draft.scheduled_at_utc = :scheduled AND draft.status = 'draft'
                        LIMIT 1 FOR UPDATE
                        SQL,
                    $params,
                );
                if ($existing !== false) {
                    return ['status' => 'ok', 'changed' => false, 'draft' => $existing];
                }
                $reserved = (int) $db->fetchOne(
                    <<<'SQL'
                        SELECT COUNT(*) FROM gf_schedule_drafts
                        WHERE organization_id = :organization AND scheduled_at_utc = :scheduled
                          AND status = 'draft'
                        SQL,
                    ['organization' => $organization, 'scheduled' => $dateTime],
                );
                if ($reserved >= $slot['capacity']) {
                    return ['status' => 'full'];
                }

                $id = Uuid::v7()->toRfc4122();
                $now = gmdate('Y-m-d H:i:s');
                $db->insert('gf_schedule_drafts', [
                    'id' => $id,
                    'organization_id' => $organization,
                    'asset_id' => $assetId,
                    'created_by' => $user,
                    'scheduled_at_utc' => $dateTime,
                    'timezone' => $slot['timezone'],
                    'local_date' => $slot['local_date'],
                    'local_time' => $slot['local_time'],
                    'status' => 'draft',
                    'created_at' => $now,
                ]);

                return ['status' => 'ok', 'changed' => true, 'draft' => [
                    'id' => $id,
                    'asset_id' => $assetId,
                    'asset_name' => $asset['original_name'],
                    'scheduled_at_utc' => $dateTime,
                    'timezone' => $slot['timezone'],
                    'local_date' => $slot['local_date'],
                    'local_time' => $slot['local_time'],
                    'status' => 'draft',
                    'created_at' => $now,
                    'cancelled_at' => null,
                ]];
            },
        );

        if ($result['status'] === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para programar ha cambiado.');
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'asset_not_found', 'El recurso no está disponible en esta organización.');
        }
        if ($result['status'] === 'blocked') {
            return $this->privateJson(['error' => [
                'code' => 'asset_not_eligible',
                'message' => 'El recurso conserva bloqueos internos.',
                'blocking_reasons' => $result['reasons'],
            ]], 409);
        }
        if ($result['status'] === 'stale_slot') {
            return $this->error(409, 'slot_changed', 'El horario ya no coincide con la regla vigente.');
        }
        if ($result['status'] === 'full') {
            return $this->error(409, 'slot_full', 'El horario alcanzó su capacidad interna.');
        }

        return $this->privateJson(['data' => [
            'draft' => $this->publicDraft($result['draft']),
            'changed' => $result['changed'],
            'publishes' => false,
        ]], $result['changed'] ? 201 : 200);
    }

    /** Cancel in place without erasing the audit-relevant assignment. */
    #[Route(
        '/api/admin/schedules/{id}/cancel',
        name: 'grindflow_schedule_drafts_cancel',
        methods: ['POST'],
    )]
    public function cancel(
        string $id,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'schedule_management_forbidden', 'Tu rol no permite cancelar borradores.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_schedule_draft',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($id) || $request->getContent() !== '') {
            return $this->error(422, 'invalid_schedule', 'Selecciona un borrador válido sin cuerpo adicional.');
        }

        $result = $db->transactional(function (Connection $db) use ($context, $id): array {
            $organization = $context['organization']['id'];
            $user = $context['user']->id();
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return ['status' => 'revoked'];
            }
            if ($db->fetchOne(
                <<<'SQL'
                    SELECT membership.id FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND membership.user_id = :user
                      AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                    FOR UPDATE
                    SQL,
                ['organization' => $organization, 'user' => $user],
            ) === false) {
                return ['status' => 'revoked'];
            }
            $draft = $db->fetchAssociative(
                <<<'SQL'
                    SELECT draft.*, asset.original_name AS asset_name
                    FROM gf_schedule_drafts draft
                    INNER JOIN gf_vault_assets asset
                      ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                    WHERE draft.id = :id AND draft.organization_id = :organization
                    FOR UPDATE
                    SQL,
                ['id' => $id, 'organization' => $organization],
            );
            if ($draft === false) {
                return ['status' => 'missing'];
            }
            if ($draft['status'] === 'cancelled') {
                return ['status' => 'ok', 'changed' => false, 'draft' => $draft];
            }
            if ($db->createSchemaManager()->tablesExist(['gf_manual_handoff_events'])) {
                $handoff = $db->fetchOne(
                    <<<'SQL'
                        SELECT action
                        FROM gf_manual_handoff_events
                        WHERE organization_id = :organization AND draft_id = :draft
                        ORDER BY created_at DESC, id DESC
                        LIMIT 1
                        SQL,
                    ['organization' => $organization, 'draft' => $id],
                );
                if (in_array($handoff, ['prepare', 'complete'], true)) {
                    return ['status' => 'handoff_active'];
                }
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_schedule_drafts
                    SET status = 'cancelled', cancelled_at = :cancelled, cancelled_by = :actor
                    WHERE id = :id AND organization_id = :organization AND status = 'draft'
                    SQL,
                ['cancelled' => $now, 'actor' => $user, 'id' => $id, 'organization' => $organization],
            );
            $draft['status'] = 'cancelled';
            $draft['cancelled_at'] = $now;

            return ['status' => 'ok', 'changed' => true, 'draft' => $draft];
        });

        if ($result['status'] === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para cancelar ha cambiado.');
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'draft_not_found', 'El borrador no existe en tu organización.');
        }
        if ($result['status'] === 'handoff_active') {
            return $this->error(
                409,
                'manual_handoff_active',
                'Cierra o registra como fallida la salida manual antes de cancelar el borrador.',
            );
        }

        return $this->privateJson(['data' => [
            'draft' => $this->publicDraft($result['draft']),
            'changed' => $result['changed'],
            'publishes' => false,
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

    /** @param array<string, mixed> $row */
    private function publicDraft(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'asset_id' => (string) $row['asset_id'],
            'asset_name' => (string) $row['asset_name'],
            'scheduled_at_utc' => str_replace(' ', 'T', (string) $row['scheduled_at_utc']).'Z',
            'timezone' => (string) $row['timezone'],
            'local_date' => (string) $row['local_date'],
            'local_time' => (string) $row['local_time'],
            'status' => (string) $row['status'],
            'manual_handoff_status' => match ($row['manual_handoff_action'] ?? null) {
                'prepare' => 'prepared',
                'complete' => 'completed',
                'fail' => 'failed',
                default => 'none',
            },
            'manual_handoff_updated_at' => ($row['manual_handoff_updated_at'] ?? null) === null
                ? null
                : (string) $row['manual_handoff_updated_at'],
            'created_at' => (string) $row['created_at'],
            'cancelled_at' => $row['cancelled_at'] === null ? null : (string) $row['cancelled_at'],
        ];
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
