<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Library\AssetHistoryService;
use GrindFlow\Library\LibraryPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only Library permission matrix and per-asset history (#52/#53).
 * Tenant comes only from the verified session membership; nothing is mutated.
 */
final class LibraryHistoryController extends AbstractController
{
    #[Route('/api/admin/library/permissions', name: 'grindflow_library_permissions', methods: ['GET'])]
    public function permissions(Request $request, MembershipContext $memberships, LibraryPermissions $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $matrix = $library->forMember($context['organization']['id'], $context['user']->id());
        if (!$matrix['view']) {
            return $this->error(403, 'library_view_forbidden', 'Tu acceso a la biblioteca está restringido.');
        }

        return $this->privateJson(['data' => [
            'role' => $context['organization']['role'],
            'permissions' => $matrix,
        ]]);
    }

    #[Route('/api/admin/vault/{id}/history', name: 'grindflow_vault_history', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function history(Request $request, MembershipContext $memberships, LibraryPermissions $library, AssetHistoryService $history, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$library->allows($context['organization']['id'], $context['user']->id(), 'view')) {
            return $this->error(403, 'library_view_forbidden', 'Tu acceso a la biblioteca está restringido.');
        }
        $timeline = $history->forAsset($context['organization']['id'], $id);
        if ($timeline === null) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }

        return $this->privateJson(['data' => $timeline]);
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
            return $this->error(409, 'organization_required', 'Selecciona una organización.');
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
