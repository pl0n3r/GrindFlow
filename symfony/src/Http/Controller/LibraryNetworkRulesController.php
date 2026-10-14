<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Library\AssetNetworkRules;
use GrindFlow\Library\LibraryPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Per-asset network allow/deny and reuse policy (#45/#46). Tenant is only the
 * verified session membership; writes need `edit`, the Vault CSRF token and a
 * strict body. Rules restrict scheduling: they never authorize publication.
 */
final class LibraryNetworkRulesController extends AbstractController
{
    private const UUID = '[0-9a-fA-F-]{36}';

    #[Route('/api/admin/vault/{id}/network-rules', name: 'grindflow_vault_network_rules', methods: ['GET'], requirements: ['id' => self::UUID])]
    public function show(Request $request, MembershipContext $memberships, LibraryPermissions $library, AssetNetworkRules $rules, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $organization = $context['organization']['id'];
        if (!$library->allows($organization, $context['user']->id(), 'view')) {
            return $this->error(403, 'library_view_forbidden', 'Tu acceso a la biblioteca está restringido.');
        }
        if ($db->fetchOne('SELECT id FROM gf_vault_assets WHERE id = :id AND organization_id = :organization', ['id' => $id, 'organization' => $organization]) === false) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }

        return $this->privateJson(['data' => ['id' => $id, 'networks' => AssetNetworkRules::NETWORKS] + $rules->forAsset($organization, $id)]);
    }

    #[Route('/api/admin/vault/{id}/network-rules', name: 'grindflow_vault_network_rule_set', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function setRule(Request $request, MembershipContext $memberships, LibraryPermissions $library, AssetNetworkRules $rules, Connection $db, string $id): JsonResponse
    {
        $context = $this->writeContext($request, $memberships, $library);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $body = $this->body($request);
        if ($body === null || array_keys($body) !== ['network', 'effect']
            || !is_string($body['network']) || !in_array($body['network'], AssetNetworkRules::NETWORKS, true)
            || !($body['effect'] === null || in_array($body['effect'], ['allow', 'deny'], true))) {
            return $this->error(422, 'invalid_network_rule', 'Indica una red válida y allow, deny o null.');
        }
        $stored = $db->transactional(fn (Connection $db): bool => $this->lockedOrganization($db, $context)
            && $rules->setRule($context['organization']['id'], $id, $body['network'], $body['effect'], $context['user']->id()));
        if (!$stored) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo activo en tu organización.');
        }

        return $this->privateJson(['data' => ['id' => $id] + $rules->forAsset($context['organization']['id'], $id)]);
    }

    #[Route('/api/admin/vault/{id}/reuse-policy', name: 'grindflow_vault_reuse_policy_set', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function setPolicy(Request $request, MembershipContext $memberships, LibraryPermissions $library, AssetNetworkRules $rules, Connection $db, string $id): JsonResponse
    {
        $context = $this->writeContext($request, $memberships, $library);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $body = $this->body($request);
        if ($body === null || array_keys($body) !== ['network', 'mode', 'max_uses', 'min_interval_hours']
            || !is_string($body['network']) || !($body['network'] === 'any' || in_array($body['network'], AssetNetworkRules::NETWORKS, true))
            || !($body['mode'] === null || (is_string($body['mode']) && in_array($body['mode'], AssetNetworkRules::MODES, true)))
            || !($body['max_uses'] === null || is_int($body['max_uses']))
            || !is_int($body['min_interval_hours'])
            || ($body['mode'] === 'max_n') !== is_int($body['max_uses'])
            || ($body['mode'] === null && $body['min_interval_hours'] !== 0)) {
            return $this->error(422, 'invalid_reuse_policy', 'Indica una política de reutilización válida o null para quitarla.');
        }
        $stored = null;
        $db->transactional(function (Connection $db) use ($context, $rules, $body, $id, &$stored): void {
            $stored = $this->lockedOrganization($db, $context)
                ? $rules->setPolicy(
                    $context['organization']['id'], $id, $body['network'], $body['mode'], $body['max_uses'],
                    $body['min_interval_hours'], $context['user']->id(),
                )
                : false;
        });
        if ($stored !== true) {
            // Invalid numeric ranges are indistinguishable from a missing asset by design.
            return $this->error(422, 'invalid_reuse_policy', 'La política no es válida o el archivo no existe en tu organización.');
        }

        return $this->privateJson(['data' => ['id' => $id] + $rules->forAsset($context['organization']['id'], $id)]);
    }

    /** @param array{user: IdentityUser, organization: array{id: string, name: string, role: string}} $context */
    private function lockedOrganization(Connection $db, array $context): bool
    {
        return $db->fetchOne(
            'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
            ['organization' => $context['organization']['id']],
        ) !== false;
    }

    /** @return array<string, mixed>|null */
    private function body(Request $request): ?array
    {
        $body = json_decode($request->getContent(), true);

        return $request->request->all() === [] && $request->files->all() === [] && is_array($body) ? $body : null;
    }

    /** @return array{user: IdentityUser, organization: array{id: string, name: string, role: string}}|JsonResponse */
    private function writeContext(Request $request, MembershipContext $memberships, LibraryPermissions $library): array|JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']
            || !$library->allows($context['organization']['id'], $context['user']->id(), 'edit')) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite editar las reglas de este archivo.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        return $context;
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
