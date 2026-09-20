<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Self-service profile: a membership role never grants access to another account. */
final class ProfileController extends AbstractController
{
    #[Route('/api/admin/profile/name', name: 'grindflow_profile_name', methods: ['POST'])]
    public function rename(Request $request, Connection $db): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }
        if (!$this->isCsrfTokenValid('grindflow_profile_name', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        $body = json_decode($request->getContent(), true);
        // An authenticated actor may change only their own name, never account/tenant IDs.
        if (!is_array($body) || array_keys($body) !== ['name'] || !is_string($body['name'])) {
            return $this->error(422, 'invalid_name', 'Indica solo el nombre visible de tu perfil.');
        }
        $name = trim($body['name']);
        if (preg_match('/\A[^\x00-\x1F\x7F]{2,120}\z/uD', $name) !== 1) {
            return $this->error(422, 'invalid_name', 'El nombre debe tener entre 2 y 120 caracteres visibles.');
        }

        $db->executeStatement(
            'UPDATE gf_identity_users SET name = :name, updated_at = :updated WHERE id = :id AND is_active = 1',
            ['name' => $name, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $user->id()],
        );
        // Do not report success when an account has been revoked during the write.
        $current = $db->fetchOne(
            'SELECT name FROM gf_identity_users WHERE id = :id AND is_active = 1',
            ['id' => $user->id()],
        );
        if (!is_string($current)) {
            return $this->error(403, 'account_access_changed', 'Tu cuenta ya no está activa.');
        }

        return $this->privateJson(['data' => ['user' => ['display_name' => $current]]]);
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
