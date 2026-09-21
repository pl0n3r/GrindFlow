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
 * S3 internal distribution authorization.
 *
 * This records an explicit workflow decision for one tenant-owned Vault asset.
 * It does not prove rights/compliance and it never schedules or publishes.
 */
final class DistributionAuthorizationController extends AbstractController
{
    #[Route(
        '/api/admin/distribution-authorizations/{assetId}',
        name: 'grindflow_distribution_authorization_put',
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
        if (!$memberships->permissions($context['organization']['role'])['distribution_authorize']) {
            return $this->error(403, 'distribution_authorization_forbidden', 'Tu rol no permite autorizar distribución.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_distribution_authorization',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($assetId)) {
            return $this->error(404, 'asset_not_found', 'El recurso no está disponible en esta organización.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['authorized'] || !is_bool($body['authorized'])) {
            return $this->error(422, 'invalid_distribution_authorization', 'Indica únicamente si autorizas o revocas la distribución.');
        }

        $desiredAction = $body['authorized'] ? 'grant' : 'revoke';
        $result = $db->transactional(function (Connection $db) use ($context, $assetId, $desiredAction): array {
            $allowed = $db->fetchOne(
                <<<'SQL'
                    SELECT membership.organization_id
                    FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization
                      AND membership.user_id = :user
                      AND actor.is_active = 1
                      AND membership.role IN ('admin', 'studio')
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

            $asset = $db->fetchOne(
                <<<'SQL'
                    SELECT id
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

            $latest = $db->fetchAssociative(
                <<<'SQL'
                    SELECT action, created_at
                    FROM gf_distribution_authorization_events
                    WHERE organization_id = :organization AND asset_id = :asset
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                    SQL,
                [
                    'organization' => $context['organization']['id'],
                    'asset' => $assetId,
                ],
            );
            $currentlyAuthorized = $latest !== false && $latest['action'] === 'grant';
            $desiredAuthorized = $desiredAction === 'grant';
            if ($currentlyAuthorized === $desiredAuthorized) {
                return [
                    'status' => 'ok',
                    'changed' => false,
                    'authorized' => $currentlyAuthorized,
                    'updated_at' => $latest === false ? null : (string) $latest['created_at'],
                ];
            }

            $now = gmdate('Y-m-d H:i:s');
            $db->insert('gf_distribution_authorization_events', [
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
                'authorized' => $desiredAuthorized,
                'updated_at' => $now,
            ];
        });

        if (($result['status'] ?? null) === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para autorizar distribución ha cambiado.');
        }
        if (($result['status'] ?? null) === 'missing') {
            return $this->error(404, 'asset_not_found', 'El recurso no está disponible en esta organización.');
        }

        return $this->privateJson(['data' => [
            'asset_id' => $assetId,
            'authorized' => (bool) $result['authorized'],
            'changed' => (bool) $result['changed'],
            'updated_at' => $result['updated_at'],
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
