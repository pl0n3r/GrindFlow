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
use Symfony\Component\Uid\Uuid;

/**
 * S4 manual handoff ledger.
 *
 * This only records an internal human workflow around an existing schedule
 * draft. No provider is called and no external publication is asserted.
 */
final class ManualHandoffController extends AbstractController
{
    private const ACTIONS = ['prepare', 'complete', 'fail'];

    #[Route(
        '/api/admin/schedules/{draftId}/manual-handoff',
        name: 'grindflow_manual_handoff_put',
        methods: ['PUT'],
    )]
    public function put(
        string $draftId,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['manual_handoff_manage']) {
            return $this->error(403, 'manual_handoff_forbidden', 'Tu rol no permite registrar salidas manuales.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_manual_handoff',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($draftId)) {
            return $this->error(404, 'draft_not_found', 'El borrador no existe en tu organización.');
        }
        if (!$db->createSchemaManager()->tablesExist(['gf_manual_handoff_events'])) {
            return $this->error(503, 'manual_handoff_schema_required', 'La auditoría de salida manual todavía no está migrada.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['action']
            || !is_string($body['action']) || !in_array($body['action'], self::ACTIONS, true)) {
            return $this->error(422, 'invalid_manual_handoff', 'Indica únicamente prepare, complete o fail.');
        }

        $desired = $body['action'];
        $result = $db->transactional(function (Connection $db) use ($context, $draftId, $desired): array {
            $organization = $context['organization']['id'];
            $user = $context['user']->id();

            $allowed = $db->fetchOne(
                <<<'SQL'
                    SELECT membership.id
                    FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization
                      AND membership.user_id = :user
                      AND actor.is_active = 1
                      AND membership.role IN ('admin', 'studio')
                    FOR UPDATE
                    SQL,
                ['organization' => $organization, 'user' => $user],
            );
            if ($allowed === false) {
                return ['status' => 'revoked'];
            }

            $draft = $db->fetchAssociative(
                <<<'SQL'
                    SELECT id, status, scheduled_at_utc
                    FROM gf_schedule_drafts
                    WHERE id = :draft AND organization_id = :organization
                    FOR UPDATE
                    SQL,
                ['draft' => $draftId, 'organization' => $organization],
            );
            if ($draft === false) {
                return ['status' => 'missing'];
            }
            if ($draft['status'] !== 'draft') {
                return ['status' => 'cancelled'];
            }

            $latest = $db->fetchAssociative(
                <<<'SQL'
                    SELECT action, created_at
                    FROM gf_manual_handoff_events
                    WHERE organization_id = :organization AND draft_id = :draft
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                    SQL,
                ['organization' => $organization, 'draft' => $draftId],
            );
            $current = $latest === false ? 'none' : (string) $latest['action'];

            if ($desired === $current) {
                return [
                    'status' => 'ok',
                    'changed' => false,
                    'action' => $current,
                    'updated_at' => $latest === false ? null : (string) $latest['created_at'],
                ];
            }
            if ($current === 'complete') {
                return ['status' => 'completed'];
            }

            if ($desired === 'prepare') {
                if (!in_array($current, ['none', 'fail'], true)) {
                    return ['status' => 'invalid_transition'];
                }
            } else {
                if ($current !== 'prepare') {
                    return ['status' => 'not_prepared'];
                }
                if ((string) $draft['scheduled_at_utc'] > gmdate('Y-m-d H:i:s')) {
                    return ['status' => 'not_due'];
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $db->insert('gf_manual_handoff_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $organization,
                'draft_id' => $draftId,
                'actor_id' => $user,
                'action' => $desired,
                'created_at' => $now,
            ]);

            return [
                'status' => 'ok',
                'changed' => true,
                'action' => $desired,
                'updated_at' => $now,
            ];
        });

        return match ($result['status'] ?? null) {
            'revoked' => $this->revoked($request),
            'missing' => $this->error(404, 'draft_not_found', 'El borrador no existe en tu organización.'),
            'cancelled' => $this->error(409, 'draft_cancelled', 'Un borrador cancelado no admite salida manual.'),
            'completed' => $this->error(409, 'manual_handoff_completed', 'La salida manual ya quedó cerrada como realizada.'),
            'not_prepared' => $this->error(409, 'manual_handoff_not_prepared', 'Prepara la salida manual antes de cerrarla.'),
            'not_due' => $this->error(409, 'manual_handoff_not_due', 'La salida manual solo puede cerrarse al llegar el horario programado.'),
            'invalid_transition' => $this->error(409, 'manual_handoff_transition_invalid', 'El estado actual no permite esa transición.'),
            default => $this->privateJson(['data' => [
                'draft_id' => $draftId,
                'status' => $this->publicStatus((string) $result['action']),
                'changed' => (bool) $result['changed'],
                'updated_at' => $result['updated_at'],
                'publishes' => false,
                'provider_calls' => false,
                'external_evidence' => false,
            ]]),
        };
    }

    private function publicStatus(string $action): string
    {
        return match ($action) {
            'prepare' => 'prepared',
            'complete' => 'completed',
            'fail' => 'failed',
            default => 'none',
        };
    }

    private function revoked(Request $request): JsonResponse
    {
        $request->getSession()->remove('grindflow_organization_id');

        return $this->error(403, 'organization_access_changed', 'Tu permiso para registrar la salida manual ha cambiado.');
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
