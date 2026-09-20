<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** A private API whose organization and role are revalidated on every request. */
final class AdminContextController extends AbstractController
{
    #[Route('/api/admin/context', name: 'grindflow_admin_context', methods: ['GET'])]
    public function context(Request $request, Connection $db, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->result(['error' => 'Debes iniciar sesión.'], 401);
        }

        $tenant = $this->tenant($request, $db, $user);
        if ($tenant === null) {
            return $this->result(['error' => 'Selecciona una organización.'], 409);
        }
        if ($tenant === false) {
            return $this->result(['error' => 'Ya no tienes acceso a la organización.'], 403);
        }

        return $this->result([
            'user' => ['display_name' => $user->displayName()],
            'organization' => $tenant,
            'permissions' => ['rename_organization' => $tenant['role'] === 'admin'],
            'csrf_token' => $tenant['role'] === 'admin'
                ? (string) $csrf->getToken('grindflow_rename_organization')->getValue()
                : null,
        ]);
    }

    #[Route('/api/admin/organization/name', name: 'grindflow_admin_rename_organization', methods: ['POST'])]
    public function rename(Request $request, Connection $db): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->result(['error' => 'Debes iniciar sesión.'], 401);
        }

        $tenant = $this->tenant($request, $db, $user);
        if ($tenant === null) {
            return $this->result(['error' => 'Selecciona una organización.'], 409);
        }
        if ($tenant === false || $tenant['role'] !== 'admin') {
            return $this->result(['error' => 'No tienes permiso para modificar esta organización.'], 403);
        }
        if (!$this->isCsrfTokenValid('grindflow_rename_organization', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->result(['error' => 'La solicitud no es válida.'], 403);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_key_exists('organization_id', $body) || !is_string($body['name'] ?? null)) {
            return $this->result(['error' => 'Indica un nombre válido sin identificadores de organización.'], 422);
        }
        $name = trim($body['name']);
        if (preg_match('/\A.{2,120}\z/usD', $name) !== 1) {
            return $this->result(['error' => 'El nombre debe tener entre 2 y 120 caracteres.'], 422);
        }

        // SQL rechecks role and tenant at the instant of the write.
        $db->executeStatement(
            <<<'SQL'
                UPDATE gf_identity_organizations
                SET name = :name, updated_at = :updated
                WHERE id = :organization
                  AND EXISTS (
                      SELECT 1 FROM gf_identity_memberships
                      WHERE user_id = :user AND organization_id = :membership_org AND role = 'admin'
                  )
                SQL,
            [
                'name' => $name,
                'updated' => gmdate('Y-m-d H:i:s'),
                'organization' => $tenant['id'],
                'membership_org' => $tenant['id'],
                'user' => $user->id(),
            ],
        );

        $updated = $this->tenant($request, $db, $user);
        if ($updated === false || $updated === null || $updated['role'] !== 'admin') {
            return $this->result(['error' => 'El acceso a esta organización cambió.'], 403);
        }

        return $this->result(['organization' => $updated]);
    }

    /** @return array{id: string, name: string, role: string}|false|null */
    private function tenant(Request $request, Connection $db, IdentityUser $user): array|false|null
    {
        $id = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($id) || $id === '') {
            return null;
        }

        $tenant = $db->fetchAssociative(
            <<<'SQL'
                SELECT organization.id, organization.name, membership.role
                FROM gf_identity_memberships membership
                INNER JOIN gf_identity_organizations organization
                    ON organization.id = membership.organization_id
                WHERE membership.user_id = :user AND membership.organization_id = :organization
                SQL,
            ['user' => $user->id(), 'organization' => $id],
        );
        if ($tenant === false) {
            $request->getSession()->remove('grindflow_organization_id');
        }

        return $tenant;
    }

    /** @param array<string, mixed> $payload */
    private function result(array $payload, int $status = 200): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
