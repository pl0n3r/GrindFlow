<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Library\AssetLifecycle;
use GrindFlow\Library\LibraryPermissions;
use GrindFlow\Scheduling\ScheduleDraftApplicationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Audited asset lifecycle (#44) and explicit permanent deletion (#54).
 * Tenant is only the verified session membership. A state never grants
 * publication; a purge removes the private original but keeps every
 * historical row and counter.
 */
final class LibraryLifecycleController extends AbstractController
{
    private const UUID = '[0-9a-fA-F-]{36}';

    #[Route('/api/admin/vault/{id}/lifecycle', name: 'grindflow_vault_lifecycle', methods: ['GET'], requirements: ['id' => self::UUID])]
    public function show(Request $request, MembershipContext $memberships, LibraryPermissions $library, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $organization = $context['organization']['id'];
        if (!$library->allows($organization, $context['user']->id(), 'view')) {
            return $this->error(403, 'library_view_forbidden', 'Tu acceso a la biblioteca está restringido.');
        }
        $asset = $db->fetchAssociative(
            'SELECT lifecycle_state, valid_from_at, valid_until_at FROM gf_vault_assets WHERE id = :id AND organization_id = :organization AND deleted_at IS NULL AND purged_at IS NULL',
            ['id' => $id, 'organization' => $organization],
        );
        if ($asset === false) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo activo en tu organización.');
        }
        $events = $db->fetchAllAssociative(
            'SELECT from_state, to_state, outcome, reason, created_at FROM gf_vault_asset_lifecycle_events WHERE organization_id = :organization AND asset_id = :id ORDER BY created_at DESC, id DESC LIMIT 20',
            ['id' => $id, 'organization' => $organization],
        );

        return $this->privateJson(['data' => $this->statePayload($id, $asset) + [
            'events' => array_map(static fn (array $row): array => [
                'from' => (string) $row['from_state'], 'to' => (string) $row['to_state'],
                'outcome' => (string) $row['outcome'], 'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'at' => str_replace(' ', 'T', (string) $row['created_at']).'Z',
            ], $events),
        ]]);
    }

    #[Route('/api/admin/vault/{id}/lifecycle', name: 'grindflow_vault_lifecycle_transition', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function transition(Request $request, MembershipContext $memberships, LibraryPermissions $library, AssetLifecycle $lifecycle, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']
            || !$library->allows($context['organization']['id'], $context['user']->id(), 'edit')) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite cambiar el estado de este archivo.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        $body = $this->body($request);
        if ($body === null || array_keys($body) !== ['to'] || !is_string($body['to'])
            || !in_array($body['to'], [...AssetLifecycle::STATES, AssetLifecycle::DERIVED], true)) {
            return $this->error(422, 'invalid_lifecycle_state', 'Indica un estado de ciclo de vida válido.');
        }
        $result = $db->transactional(function (Connection $db) use ($context, $lifecycle, $id, $body): array {
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $context['organization']['id']],
            ) === false) {
                return ['status' => 'missing'];
            }
            if (!$this->stillAllowed($db, $context)) {
                return ['status' => 'revoked'];
            }

            return $lifecycle->transition($context['organization']['id'], $id, $context['user']->id(), $body['to']);
        });
        if ($result['status'] === 'missing') {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo activo en tu organización.');
        }
        if ($result['status'] === 'revoked') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para cambiar el estado cambió.');
        }
        if ($result['status'] === 'rejected') {
            // The rejection itself is audited: the transaction committed its event.
            return $this->privateJson(['error' => [
                'code' => 'invalid_transition', 'message' => 'La transición de estado no es válida.',
                'reason' => $result['reason'] ?? 'invalid_transition',
                'from' => $result['from'] ?? null, 'allowed' => AssetLifecycle::allowedNext($result['from'] ?? null),
            ]], 409);
        }
        $asset = $db->fetchAssociative(
            'SELECT lifecycle_state, valid_from_at, valid_until_at FROM gf_vault_assets WHERE id = :id AND organization_id = :organization',
            ['id' => $id, 'organization' => $context['organization']['id']],
        );

        return $this->privateJson(['data' => $this->statePayload($id, $asset ?: [])]);
    }

    /**
     * Permanent deletion: only for an asset already in the trash, with the `delete`
     * capability, the management CSRF and an explicit confirmation of the asset id.
     * The private original is removed and personal fields are scrubbed; the row,
     * its events and counters are kept as historical metrics.
     */
    #[Route('/api/admin/vault/{id}/purge', name: 'grindflow_vault_purge', methods: ['POST'], requirements: ['id' => self::UUID])]
    public function purge(Request $request, MembershipContext $memberships, LibraryPermissions $library, PrivateVaultDirectory $storage, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$library->allows($context['organization']['id'], $context['user']->id(), 'delete')) {
            return $this->error(403, 'purge_forbidden', 'Tu rol no está autorizado a eliminar definitivamente archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        $body = $this->body($request);
        if ($body === null || array_keys($body) !== ['confirm_asset_id']
            || !is_string($body['confirm_asset_id']) || !hash_equals(strtolower($id), strtolower($body['confirm_asset_id']))) {
            return $this->error(422, 'purge_confirmation_required', 'Confirma el identificador del archivo para eliminarlo definitivamente.');
        }
        $result = $db->transactional(function (Connection $db) use ($context, $library, $id): array {
            $organization = $context['organization']['id'];
            if ($db->fetchOne('SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE', ['organization' => $organization]) === false
                || !$library->allows($organization, $context['user']->id(), 'delete')) {
                return ['status' => 'revoked'];
            }
            $asset = $db->fetchAssociative(
                'SELECT storage_key, deleted_at, purged_at FROM gf_vault_assets WHERE id = :id AND organization_id = :organization FOR UPDATE',
                ['id' => $id, 'organization' => $organization],
            );
            if ($asset === false) {
                return ['status' => 'not_found'];
            }
            if ($asset['purged_at'] !== null) {
                return ['status' => 'already_purged'];
            }
            if ($asset['deleted_at'] === null) {
                return ['status' => 'not_in_trash'];
            }
            $params = ['organization' => $organization, 'asset' => $id];
            $count = static fn (string $sql): int => (int) $db->fetchOne($sql, $params);
            $metrics = [
                'schedule_drafts' => $count('SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = :organization AND asset_id = :asset'),
                'content_reviews' => $count('SELECT COUNT(*) FROM gf_content_review_events WHERE organization_id = :organization AND asset_id = :asset'),
                'distribution_authorizations' => $count('SELECT COUNT(*) FROM gf_distribution_authorization_events WHERE organization_id = :organization AND asset_id = :asset'),
                'variants' => $count('SELECT COUNT(*) FROM gf_vault_asset_variants WHERE organization_id = :organization AND (master_asset_id = :asset OR variant_asset_id = :asset)'),
                'lifecycle_events' => $count('SELECT COUNT(*) FROM gf_vault_asset_lifecycle_events WHERE organization_id = :organization AND asset_id = :asset'),
            ];
            $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_vault_assets
                    SET original_name = :name, private_note = NULL, sha256 = :fingerprint,
                        purged_at = :purged_at, purged_by = :purger
                    WHERE id = :id AND organization_id = :organization
                    SQL,
                [
                    'name' => 'purged-'.substr($id, 0, 8), 'fingerprint' => hash('sha256', 'purged|'.$id),
                    'purged_at' => gmdate('Y-m-d H:i:s'), 'purger' => $context['user']->id(),
                    'id' => $id, 'organization' => $organization,
                ],
            );

            return ['status' => 'purged', 'storage_key' => (string) $asset['storage_key'], 'metrics' => $metrics];
        });
        if ($result['status'] === 'not_found') {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }
        if ($result['status'] === 'revoked') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para eliminar cambió.');
        }
        if ($result['status'] === 'not_in_trash') {
            return $this->error(409, 'purge_requires_trash', 'Solo se puede eliminar definitivamente un archivo que ya está en la papelera.');
        }
        if ($result['status'] === 'already_purged') {
            return $this->error(409, 'vault_state_changed', 'El archivo ya fue eliminado definitivamente.');
        }
        // The row is already scrubbed and invisible; now remove the private original.
        $removed = false;
        $key = $result['storage_key'];
        $root = $storage->root();
        if (preg_match('/\A[0-9a-fA-F-]{36}\z/D', $key) === 1 && !is_link($root) && is_dir($root)) {
            $path = $root.'/'.$key.'.blob';
            $removed = !is_link($path) && (!is_file($path) || @unlink($path));
        }

        return $this->privateJson(['data' => [
            'id' => $id, 'state' => 'purged', 'storage_removed' => $removed,
            'retained_metrics' => $result['metrics'],
        ]]);
    }

    /** @param array<string, mixed> $asset @return array<string, mixed> */
    private function statePayload(string $id, array $asset): array
    {
        $from = isset($asset['valid_from_at']) ? (string) $asset['valid_from_at'] : null;
        $until = isset($asset['valid_until_at']) ? (string) $asset['valid_until_at'] : null;
        $persisted = $asset['lifecycle_state'] ?? AssetLifecycle::DEFAULT_STATE;
        $validity = ScheduleDraftApplicationService::validityState($from, $until, gmdate('Y-m-d H:i:s'));

        return [
            'id' => $id,
            'state' => AssetLifecycle::effectiveState($persisted, $validity),
            'persisted_state' => is_string($persisted) ? $persisted : AssetLifecycle::DEFAULT_STATE,
            'allowed_next' => AssetLifecycle::allowedNext($persisted),
        ];
    }

    /** @param array{user: IdentityUser, organization: array{id: string, name: string, role: string}} $context */
    private function stillAllowed(Connection $db, array $context): bool
    {
        return $db->fetchOne(
            <<<'SQL'
                SELECT 1 FROM gf_identity_memberships membership
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE membership.organization_id = :organization AND actor.id = :user AND actor.is_active = 1
                  AND membership.role IN ('admin', 'studio', 'editor')
                SQL,
            ['organization' => $context['organization']['id'], 'user' => $context['user']->id()],
        ) !== false;
    }

    /** @return array<string, mixed>|null */
    private function body(Request $request): ?array
    {
        $body = json_decode($request->getContent(), true);

        return $request->request->all() === [] && $request->files->all() === [] && is_array($body) ? $body : null;
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
