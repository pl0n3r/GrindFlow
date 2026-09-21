<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminContextController extends AbstractController
{
    #[Route('/api/admin/context', name: 'grindflow_admin_context', methods: ['GET'])]
    public function __invoke(Request $request, MembershipContext $memberships, ProductVersion $version, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }

        $selectedId = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selectedId) || $selectedId === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización para continuar.');
        }

        $organization = $memberships->find($user->id(), $selectedId);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }

        $liveName = $memberships->displayName($user->id());
        if ($liveName === null) {
            return $this->error(403, 'account_access_changed', 'Tu cuenta ya no está activa.');
        }
        $permissions = $memberships->permissions($organization['role']);

        return $this->privateJson([
            'data' => [
                'user' => ['display_name' => $liveName],
                'profile_name_csrf' => (string) $csrf->getToken('grindflow_profile_name')->getValue(),
                'profile_password_csrf' => (string) $csrf->getToken('grindflow_profile_password')->getValue(),
                'vault_upload_csrf' => $permissions['content_prepare']
                    ? (string) $csrf->getToken('grindflow_vault_upload')->getValue()
                    : null,
                'vault_manage_csrf' => $permissions['content_prepare']
                    ? (string) $csrf->getToken('grindflow_vault_manage')->getValue()
                    : null,
                'weekly_rule_csrf' => $permissions['content_prepare']
                    ? (string) $csrf->getToken('grindflow_weekly_rule')->getValue()
                    : null,
                'content_review_csrf' => $permissions['content_review_decide']
                    ? (string) $csrf->getToken('grindflow_content_review')->getValue()
                    : null,
                'schedule_draft_csrf' => $permissions['content_prepare']
                    ? (string) $csrf->getToken('grindflow_schedule_draft')->getValue()
                    : null,
                'distribution_authorization_csrf' => $permissions['distribution_authorize']
                    ? (string) $csrf->getToken('grindflow_distribution_authorization')->getValue()
                    : null,
                'manual_handoff_csrf' => $permissions['manual_handoff_manage']
                    ? (string) $csrf->getToken('grindflow_manual_handoff')->getValue()
                    : null,
                'organization' => $organization,
                'permissions' => $permissions,
                'organization_name_csrf' => $permissions['organization_manage']
                    ? (string) $csrf->getToken('grindflow_organization_name')->getValue()
                    : null,
            ],
            'meta' => ['version' => $version->human()],
        ]);
    }

    #[Route('/api/admin/organization/name', name: 'grindflow_admin_organization_name', methods: ['POST'])]
    public function rename(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }

        $selectedId = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selectedId) || $selectedId === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización para continuar.');
        }

        $organization = $memberships->find($user->id(), $selectedId);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }
        if (!$memberships->permissions($organization['role'])['organization_manage']) {
            return $this->error(403, 'organization_management_forbidden', 'Tu rol no permite modificar esta organización.');
        }
        if (!$this->isCsrfTokenValid('grindflow_organization_name', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_key_exists('organization_id', $body) || !is_string($body['name'] ?? null)) {
            return $this->error(422, 'invalid_name', 'Indica un nombre válido, sin identificadores de organización.');
        }

        $name = trim($body['name']);
        if (preg_match('/\A[^\x00-\x1F\x7F]{2,120}\z/uD', $name) !== 1) {
            return $this->error(422, 'invalid_name', 'El nombre debe tener entre 2 y 120 caracteres visibles.');
        }

        $db->executeStatement(
            <<<'SQL'
                UPDATE gf_identity_organizations
                SET name = :name, updated_at = :updated
                WHERE id = :organization
                  AND EXISTS (
                      SELECT 1
                      FROM gf_identity_memberships
                      INNER JOIN gf_identity_users actor ON actor.id = gf_identity_memberships.user_id
                      WHERE gf_identity_memberships.user_id = :user
                        AND gf_identity_memberships.organization_id = :membership_org
                        AND gf_identity_memberships.role IN ('admin', 'studio')
                        AND actor.is_active = 1
                  )
                SQL,
            [
                'name' => $name,
                'updated' => gmdate('Y-m-d H:i:s'),
                'organization' => $organization['id'],
                'membership_org' => $organization['id'],
                'user' => $user->id(),
            ],
        );

        $updated = $memberships->find($user->id(), $selectedId);
        if ($updated === null || !$memberships->permissions($updated['role'])['organization_manage']) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para modificar esta organización ha cambiado.');
        }

        return $this->privateJson(['data' => ['organization' => $updated]]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string, mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
