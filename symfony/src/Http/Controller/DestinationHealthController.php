<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use GrindFlow\Distribution\DestinationHealthService;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Read-only destination diagnostics for the selected, authenticated tenant. */
final class DestinationHealthController extends AbstractController
{
    #[Route('/api/admin/destination-health', name: 'grindflow_destination_health_list', methods: ['GET'])]
    #[Route('/api/admin/destination-health/{organizationId}', name: 'grindflow_destination_health_tenant', methods: ['GET'])]
    public function list(
        Request $request,
        MembershipContext $memberships,
        DestinationHealthService $health,
        ?string $organizationId = null,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }

        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || !Uuid::isValid($selected)) {
            return $this->error(409, 'organization_required', 'Selecciona una organización para continuar.');
        }
        $membership = $memberships->find($user->id(), $selected);
        if ($membership === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }
        if (!$memberships->permissions($membership['role'])['workspace_view']) {
            return $this->error(403, 'destination_health_forbidden', 'No puedes consultar estos destinos.');
        }
        // A caller cannot enumerate a different organization's health or even
        // learn whether an opaque organization id exists.
        if ($organizationId !== null
            && (!Uuid::isValid($organizationId)
                || !hash_equals(strtolower($membership['id']), strtolower($organizationId)))) {
            return $this->error(404, 'destination_health_not_found', 'No se encontraron destinos.');
        }

        return $this->privateJson(['data' => [
            'scope' => 'selected_organization',
            'organization' => ['id' => $membership['id'], 'name' => $membership['name']],
            'view' => $membership['role'] === 'studio' ? 'studio' : 'independent',
            'destinations' => $health->forOrganization($membership['id']),
            'provider_calls' => false,
            'verified_provider_connection' => false,
            'read_only' => true,
        ]]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
