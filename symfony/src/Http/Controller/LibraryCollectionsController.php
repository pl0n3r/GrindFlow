<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Library\AssetCollectionApplication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class LibraryCollectionsController extends AbstractController
{
    private const CSRF = 'grindflow_library_collections';

    #[Route('/api/admin/library/collections', name: 'grindflow_library_collections_list', methods: ['GET'])]
    public function list(Request $request, MembershipContext $memberships, Connection $db, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $after = $this->cursor($request);
        if ($after instanceof JsonResponse) {
            return $after;
        }
        if ($after !== null && $db->fetchOne(
            'SELECT id FROM gf_vault_collections WHERE organization_id = :organization AND id = :after LIMIT 1',
            ['organization' => $context['organization']['id'], 'after' => $after],
        ) === false) {
            return $this->error(422, 'invalid_cursor', 'Cursor de página inválido.');
        }

        $params = ['organization' => $context['organization']['id']];
        $cursorFilter = '';
        if ($after !== null) {
            $params['after'] = $after;
            $cursorFilter = ' AND id > :after';
        }
        $rows = $db->fetchFirstColumn(
            'SELECT id FROM gf_vault_collections WHERE organization_id = :organization'
            .$cursorFilter.' ORDER BY id LIMIT 101',
            $params,
        );
        $page = array_slice($rows, 0, 100);
        $more = count($rows) > 100;
        return $this->privateJson(['data' => [
            'collections' => $page,
            'has_more' => $more,
            'next_cursor' => $more ? (string) end($page) : null,
            'csrf' => (string) $csrf->getToken(self::CSRF),
            'publishes' => false,
        ]]);
    }

    #[Route('/api/admin/library/collections', name: 'grindflow_library_collections_create', methods: ['POST'])]
    public function create(Request $request, MembershipContext $memberships, AssetCollectionApplication $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (($error = $this->writeGate($request, $memberships, $context)) instanceof JsonResponse) {
            return $error;
        }
        $body = json_decode($request->getContent());
        if (!is_object($body) || (array) $body !== []) {
            return $this->error(422, 'invalid_collection', 'Envía un objeto vacío para crear la colección.');
        }
        return $this->result($library->createCollection($context['organization']['id'], $context['user']->id()), 201);
    }

    #[Route('/api/admin/library/collections/{collectionId}/assets', name: 'grindflow_library_collection_assets', methods: ['GET'])]
    public function assets(string $collectionId, Request $request, MembershipContext $memberships, AssetCollectionApplication $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $after = $this->cursor($request);
        if ($after instanceof JsonResponse) {
            return $after;
        }
        return $this->result($library->assetsForCollection(
            $context['organization']['id'], $context['user']->id(), $collectionId, $after,
        ));
    }

    #[Route('/api/admin/library/collections/{collectionId}/assets/{assetId}', name: 'grindflow_library_collection_attach', methods: ['PUT'])]
    public function attach(string $collectionId, string $assetId, Request $request, MembershipContext $memberships, AssetCollectionApplication $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (($error = $this->writeGate($request, $memberships, $context)) instanceof JsonResponse) {
            return $error;
        }
        if ($request->getContent() !== '' && $request->getContent() !== '{}') {
            return $this->error(422, 'invalid_collection', 'No envíes datos externos para vincular un recurso.');
        }
        return $this->result($library->attachAsset(
            $context['organization']['id'], $context['user']->id(), $collectionId, $assetId,
        ));
    }

    #[Route('/api/admin/library/assets/{assetId}/collections', name: 'grindflow_library_asset_collections', methods: ['GET'])]
    public function collections(string $assetId, Request $request, MembershipContext $memberships, AssetCollectionApplication $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $after = $this->cursor($request);
        if ($after instanceof JsonResponse) {
            return $after;
        }
        return $this->result($library->collectionsForAsset(
            $context['organization']['id'], $context['user']->id(), $assetId, $after,
        ));
    }

    #[Route('/api/admin/library/masters/{masterId}/variants', name: 'grindflow_library_variants', methods: ['GET'])]
    public function variants(string $masterId, Request $request, MembershipContext $memberships, AssetCollectionApplication $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $after = $this->cursor($request);
        if ($after instanceof JsonResponse) {
            return $after;
        }
        return $this->result($library->variantsForMaster(
            $context['organization']['id'], $context['user']->id(), $masterId, $after,
        ));
    }

    #[Route('/api/admin/library/masters/{masterId}/variants/{variantId}', name: 'grindflow_library_variant_link', methods: ['PUT'])]
    public function linkVariant(string $masterId, string $variantId, Request $request, MembershipContext $memberships, AssetCollectionApplication $library): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (($error = $this->writeGate($request, $memberships, $context)) instanceof JsonResponse) {
            return $error;
        }
        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['type'] || !is_string($body['type'])) {
            return $this->error(422, 'invalid_variant', 'Indica solamente el tipo de variante.');
        }
        return $this->result($library->linkVariant(
            $context['organization']['id'], $context['user']->id(), $masterId, $variantId, $body['type'],
        ));
    }

    private function cursor(Request $request): string|JsonResponse|null
    {
        $after = $request->query->all()['after'] ?? null;
        if ($after === null) {
            return null;
        }
        if (!is_string($after) || !Uuid::isValid($after)) {
            return $this->error(422, 'invalid_cursor', 'Cursor de página inválido.');
        }
        return strtolower($after);
    }

    private function writeGate(Request $request, MembershipContext $memberships, array $context): ?JsonResponse
    {
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'library_write_forbidden', 'No tienes permiso para organizar contenido.');
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud no es válida.');
        }
        return null;
    }

    /** @return array{user:IdentityUser,organization:array{id:string,name:string,role:string}}|JsonResponse */
    private function context(Request $request, MembershipContext $memberships): array|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión.');
        }
        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización.');
        }
        $organization = $memberships->find($user->id(), $selected);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');
            return $this->error(403, 'organization_access_changed', 'Tu acceso cambió.');
        }
        return ['user' => $user, 'organization' => $organization];
    }

    private function result(array $result, int $status = 200): JsonResponse
    {
        return match ($result['status'] ?? null) {
            'ok' => $this->privateJson(['data' => $result + ['publishes' => false]], $status),
            'forbidden' => $this->error(403, 'library_access_changed', 'El acceso fue revocado.'),
            'invalid_cursor' => $this->error(422, 'invalid_cursor', 'Cursor de página inválido.'),
            'missing' => $this->error(404, 'asset_not_found', 'No disponible en esta organización.'),
            'conflict' => $this->error(409, 'variant_conflict', 'La relación no puede crearse.'),
            default => $this->error(422, 'invalid_variant', 'Relación de contenido inválida.'),
        };
    }

    private function error(int $code, string $name, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $name, 'message' => $message]], $code);
    }

    private function privateJson(array $payload, int $code = 200): JsonResponse
    {
        $response = $this->json($payload, $code);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
