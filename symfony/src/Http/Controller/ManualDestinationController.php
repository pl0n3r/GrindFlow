<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ManualDestinationController extends AbstractController
{
    #[Route('/api/admin/manual-destinations', name: 'grindflow_manual_destinations_list', methods: ['GET'])]
    public function list(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$this->schemaReady($db)) {
            return $this->privateJson(['data' => [
                'ready' => false,
                'destinations' => [],
                'total' => 0,
                'limit' => 100,
                'provider_calls' => false,
            ]]);
        }

        $rows = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT id, label, created_at, disabled_at
                FROM gf_manual_destinations
                WHERE organization_id = :organization
                ORDER BY disabled_at IS NOT NULL, label, id
                LIMIT 100
                SQL,
            ['organization' => $context['organization']['id']],
        );

        $total = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_manual_destinations WHERE organization_id = :organization',
            ['organization' => $context['organization']['id']],
        );

        return $this->privateJson(['data' => [
            'ready' => true,
            'destinations' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'label' => (string) $row['label'],
                'active' => $row['disabled_at'] === null,
                'created_at' => (string) $row['created_at'],
                'disabled_at' => $row['disabled_at'] === null ? null : (string) $row['disabled_at'],
            ], $rows),
            'total' => $total,
            'limit' => 100,
            'provider_calls' => false,
        ]]);
    }

    #[Route('/api/admin/manual-destinations', name: 'grindflow_manual_destinations_create', methods: ['POST'])]
    public function create(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['manual_handoff_manage']) {
            return $this->error(403, 'manual_destination_forbidden', 'Tu rol no permite administrar destinos manuales.');
        }
        if (!$this->validCsrf($request)) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!$this->schemaReady($db)) {
            return $this->error(503, 'manual_destination_schema_required', 'El catálogo de destinos manuales todavía no está migrado.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['label'] || !is_string($body['label'])) {
            return $this->error(422, 'invalid_manual_destination', 'Indica únicamente un nombre para el destino manual.');
        }
        $label = $this->normalizeLabel($body['label']);
        if ($label === null) {
            return $this->error(422, 'invalid_manual_destination', 'El nombre debe tener entre 2 y 80 caracteres visibles.');
        }

        $result = $db->transactional(function (Connection $db) use ($context, $label): array {
            if (!$this->stillAllowed($db, $context['organization']['id'], $context['user']->id())) {
                return ['status' => 'revoked'];
            }

            $now = gmdate('Y-m-d H:i:s');
            $id = Uuid::v7()->toRfc4122();
            try {
                $db->insert('gf_manual_destinations', [
                    'id' => $id,
                    'organization_id' => $context['organization']['id'],
                    'label' => $label,
                    'created_by' => $context['user']->id(),
                    'created_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT id, label, created_at, disabled_at
                        FROM gf_manual_destinations
                        WHERE organization_id = :organization AND label = :label
                        LIMIT 1
                        SQL,
                    ['organization' => $context['organization']['id'], 'label' => $label],
                );

                return ['status' => 'duplicate', 'destination' => $existing];
            }

            return ['status' => 'created', 'destination' => [
                'id' => $id,
                'label' => $label,
                'created_at' => $now,
                'disabled_at' => null,
            ]];
        });

        if ($result['status'] === 'revoked') {
            return $this->revoked($request);
        }
        if ($result['status'] === 'duplicate') {
            return $this->privateJson(['data' => [
                'destination' => $this->publicDestination($result['destination']),
                'changed' => false,
                'provider_calls' => false,
            ]]);
        }

        return $this->privateJson(['data' => [
            'destination' => $this->publicDestination($result['destination']),
            'changed' => true,
            'provider_calls' => false,
        ]], 201);
    }

    #[Route(
        '/api/admin/manual-destinations/{destinationId}',
        name: 'grindflow_manual_destinations_status',
        methods: ['PUT'],
    )]
    public function status(
        string $destinationId,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['manual_handoff_manage']) {
            return $this->error(403, 'manual_destination_forbidden', 'Tu rol no permite administrar destinos manuales.');
        }
        if (!$this->validCsrf($request)) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($destinationId)) {
            return $this->error(404, 'manual_destination_not_found', 'El destino manual no existe en tu organización.');
        }
        if (!$this->schemaReady($db)) {
            return $this->error(503, 'manual_destination_schema_required', 'El catálogo de destinos manuales todavía no está migrado.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['active'] || !is_bool($body['active'])) {
            return $this->error(422, 'invalid_manual_destination', 'Indica únicamente si el destino está activo.');
        }

        $desiredActive = $body['active'];
        $result = $db->transactional(function (Connection $db) use (
            $context,
            $destinationId,
            $desiredActive,
        ): array {
            if (!$this->stillAllowed($db, $context['organization']['id'], $context['user']->id())) {
                return ['status' => 'revoked'];
            }

            $destination = $db->fetchAssociative(
                <<<'SQL'
                    SELECT id, label, created_at, disabled_at
                    FROM gf_manual_destinations
                    WHERE id = :id AND organization_id = :organization
                    FOR UPDATE
                    SQL,
                ['id' => $destinationId, 'organization' => $context['organization']['id']],
            );
            if ($destination === false) {
                return ['status' => 'missing'];
            }

            $isActive = $destination['disabled_at'] === null;
            if ($isActive === $desiredActive) {
                return ['status' => 'ok', 'changed' => false, 'destination' => $destination];
            }

            if ($desiredActive) {
                $db->update('gf_manual_destinations', [
                    'disabled_at' => null,
                    'disabled_by' => null,
                ], [
                    'id' => $destinationId,
                    'organization_id' => $context['organization']['id'],
                ]);
                $destination['disabled_at'] = null;
            } else {
                $now = gmdate('Y-m-d H:i:s');
                $db->update('gf_manual_destinations', [
                    'disabled_at' => $now,
                    'disabled_by' => $context['user']->id(),
                ], [
                    'id' => $destinationId,
                    'organization_id' => $context['organization']['id'],
                ]);
                $destination['disabled_at'] = $now;
            }

            return ['status' => 'ok', 'changed' => true, 'destination' => $destination];
        });

        if ($result['status'] === 'revoked') {
            return $this->revoked($request);
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'manual_destination_not_found', 'El destino manual no existe en tu organización.');
        }

        return $this->privateJson(['data' => [
            'destination' => $this->publicDestination($result['destination']),
            'changed' => (bool) $result['changed'],
            'provider_calls' => false,
        ]]);
    }

    private function normalizeLabel(string $label): ?string
    {
        $label = preg_replace('/\s+/u', ' ', trim($label));
        if (!is_string($label) || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
            return null;
        }
        $length = mb_strlen($label);

        return $length >= 2 && $length <= 80 ? $label : null;
    }

    private function schemaReady(Connection $db): bool
    {
        return $db->createSchemaManager()->tablesExist(['gf_manual_destinations']);
    }

    private function validCsrf(Request $request): bool
    {
        return $this->isCsrfTokenValid(
            'grindflow_manual_destination',
            (string) $request->headers->get('X-CSRF-Token', ''),
        );
    }

    private function stillAllowed(Connection $db, string $organization, string $user): bool
    {
        return $db->fetchOne(
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
        ) !== false;
    }

    /** @param array<string, mixed> $row */
    private function publicDestination(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'label' => (string) $row['label'],
            'active' => $row['disabled_at'] === null,
            'created_at' => (string) $row['created_at'],
            'disabled_at' => $row['disabled_at'] === null ? null : (string) $row['disabled_at'],
        ];
    }

    private function revoked(Request $request): JsonResponse
    {
        $request->getSession()->remove('grindflow_organization_id');

        return $this->error(403, 'organization_access_changed', 'Tu permiso para administrar destinos manuales ha cambiado.');
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
