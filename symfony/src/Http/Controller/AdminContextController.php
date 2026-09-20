<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Shared\Version\ProductVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminContextController extends AbstractController
{
    #[Route('/api/admin/context', name: 'grindflow_admin_context', methods: ['GET'])]
    public function __invoke(Request $request, MembershipContext $memberships, ProductVersion $version): JsonResponse
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

        return $this->privateJson([
            'data' => [
                'user' => ['display_name' => $user->displayName()],
                'organization' => $organization,
                'permissions' => $memberships->permissions($organization['role']),
            ],
            'meta' => ['version' => $version->human()],
        ]);
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
