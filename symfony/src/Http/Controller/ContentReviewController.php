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
 * Explicit human review of an immutable S2 Vault original.
 *
 * A review approval clears only the content-review blocker. Distribution
 * authorization, scheduling and publication remain separate contracts.
 */
final class ContentReviewController extends AbstractController
{
    #[Route(
        '/api/admin/content-reviews/{assetId}',
        name: 'grindflow_content_review_put',
        methods: ['PUT'],
    )]
    public function put(
        string $assetId,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_review_decide']) {
            return $this->error(403, 'content_review_forbidden', 'Tu rol no permite decidir revisiones de contenido.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_content_review',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($assetId)) {
            return $this->error(404, 'asset_not_found', 'El recurso no está disponible en esta organización.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['approved'] || !is_bool($body['approved'])) {
            return $this->error(422, 'invalid_content_review', 'Indica únicamente si apruebas o revocas la revisión.');
        }

        $desiredAction = $body['approved'] ? 'approve' : 'revoke';
        $result = $db->transactional(
            function (Connection $db) use ($context, $assetId, $desiredAction): array {
                $allowed = $db->fetchOne(
                    <<<'SQL'
                        SELECT membership.organization_id
                        FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = :organization
                          AND membership.user_id = :user
                          AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                        FOR UPDATE
                        SQL,
                    [
                        'organization' => $context['organization']['id'],
                        'user' => $context['user']->id(),
                    ],
                );
                if ($allowed === false) {
                    return ['status' => 'revoked'];
                }

                $asset = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT id, usage_scope
                        FROM gf_vault_assets
                        WHERE id = :asset AND organization_id = :organization
                          AND deleted_at IS NULL
                        FOR UPDATE
                        SQL,
                    [
                        'asset' => $assetId,
                        'organization' => $context['organization']['id'],
                    ],
                );
                if ($asset === false) {
                    return ['status' => 'missing'];
                }
                if ($asset['usage_scope'] !== 'needs_review') {
                    return ['status' => 'not_required'];
                }

                $latest = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT action, created_at
                        FROM gf_content_review_events
                        WHERE organization_id = :organization AND asset_id = :asset
                        ORDER BY created_at DESC, id DESC
                        LIMIT 1
                        SQL,
                    [
                        'organization' => $context['organization']['id'],
                        'asset' => $assetId,
                    ],
                );
                $currentlyApproved = $latest !== false && $latest['action'] === 'approve';
                $desiredApproved = $desiredAction === 'approve';
                if ($currentlyApproved === $desiredApproved) {
                    return [
                        'status' => 'ok',
                        'changed' => false,
                        'approved' => $currentlyApproved,
                        'updated_at' => $latest === false ? null : (string) $latest['created_at'],
                    ];
                }

                $now = gmdate('Y-m-d H:i:s');
                $db->insert('gf_content_review_events', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'organization_id' => $context['organization']['id'],
                    'asset_id' => $assetId,
                    'actor_id' => $context['user']->id(),
                    'action' => $desiredAction,
                    'created_at' => $now,
                ]);

                return [
                    'status' => 'ok',
                    'changed' => true,
                    'approved' => $desiredApproved,
                    'updated_at' => $now,
                ];
            },
        );

        if (($result['status'] ?? null) === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para revisar contenido ha cambiado.');
        }
        if (($result['status'] ?? null) === 'missing') {
            return $this->error(404, 'asset_not_found', 'El recurso no está disponible en esta organización.');
        }
        if (($result['status'] ?? null) === 'not_required') {
            return $this->error(
                409,
                'content_review_not_required',
                'Este recurso debe estar clasificado como Requiere revisión para registrar una decisión.',
            );
        }

        return $this->privateJson(['data' => [
            'asset_id' => $assetId,
            'approved' => (bool) $result['approved'],
            'changed' => (bool) $result['changed'],
            'updated_at' => $result['updated_at'],
            'schedules' => false,
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
