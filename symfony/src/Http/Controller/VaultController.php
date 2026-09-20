<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Isolated S2 photo library. Files never live under public/; no external delivery.
 * Tenant is exclusively the verified session membership, not a client parameter.
 */
final class VaultController extends AbstractController
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    #[Route('/api/admin/vault', name: 'grindflow_vault_list', methods: ['GET'])]
    public function list(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        // Bound the offset to avoid unbounded scans and reject ambiguous query values.
        $rawPage = $request->query->get('page', '1');
        if (!is_string($rawPage) || !preg_match('/^[1-9][0-9]{0,3}$/D', $rawPage) || (int) $rawPage > 1000) {
            return $this->error(422, 'invalid_page', 'Selecciona una página válida (1 a 1000).');
        }
        $page = (int) $rawPage;
        $params = ['organization' => $context['organization']['id'], 'user' => $context['user']->id()];
        $scope = <<<'SQL'
            FROM gf_vault_assets asset
            INNER JOIN gf_identity_memberships membership
                ON membership.organization_id = asset.organization_id
            INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
            WHERE asset.organization_id = :organization
              AND membership.user_id = :user AND actor.is_active = 1
            SQL;
        $total = (int) $db->fetchOne('SELECT COUNT(*) '.$scope, $params);
        $assets = $db->fetchAllAssociative(
            'SELECT asset.id, asset.original_name, asset.mime_type, asset.size_bytes, asset.created_at '
            .$scope.' ORDER BY asset.created_at DESC, asset.id DESC LIMIT 30 OFFSET '.(($page - 1) * 30),
            $params,
        );

        return $this->privateJson(['data' => [
            'assets' => array_map($this->publicAsset(...), $assets),
            'limit' => 30,
            'page' => $page,
            'total' => $total,
            'pages' => (int) ceil($total / 30),
        ]]);
    }

    #[Route('/api/admin/vault', name: 'grindflow_vault_upload', methods: ['POST'])]
    public function upload(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'upload_forbidden', 'Tu rol no permite añadir archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_upload', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if ($request->request->has('organization_id') || $request->request->has('user_id') || count($request->files->all()) !== 1) {
            return $this->error(422, 'invalid_upload', 'Adjunta un solo archivo sin identificadores de cuenta.');
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error(422, 'invalid_upload', 'El archivo no se recibió correctamente.');
        }

        $size = $file->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            return $this->error(422, 'invalid_size', 'La imagen debe pesar entre 1 byte y 8 MiB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!is_string($mime) || !in_array($mime, self::MIMES, true) || @getimagesize($file->getPathname()) === false) {
            return $this->error(422, 'invalid_type', 'Solo se aceptan imágenes JPEG, PNG o WebP válidas.');
        }

        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '_', $filename) ?: 'imagen';
        $filename = mb_substr(trim($filename), 0, 180);
        if ($filename === '') {
            $filename = 'imagen';
        }

        $id = Uuid::v7()->toRfc4122();
        $root = (string) $this->getParameter('kernel.project_dir').'/var/vault';
        if (is_link($root) || (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root))) {
            throw new \RuntimeException('Private storage is not available.');
        }
        $file->move($root, $id.'.blob');
        $path = $root.'/'.$id.'.blob';

        try {
            $actualSize = filesize($path);
            $sha256 = hash_file('sha256', $path);
            if ($actualSize === false || $sha256 === false || $actualSize !== $size) {
                return $this->error(422, 'invalid_upload', 'La imagen no pudo verificarse.');
            }
            // Authorization is checked again atomically at the moment of the insert.
            $written = $db->executeStatement(
                <<<'SQL'
                    INSERT INTO gf_vault_assets (
                        id, organization_id, uploaded_by, original_name, mime_type,
                        size_bytes, sha256, storage_key, created_at
                    )
                    SELECT :id, membership.organization_id, actor.id, :name, :mime,
                           :size, :sha, :storage, :created
                    FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND actor.id = :user
                      AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                    SQL,
                [
                    'id' => $id,
                    'name' => $filename,
                    'mime' => $mime,
                    'size' => $size,
                    'sha' => $sha256,
                    'storage' => $id,
                    'created' => gmdate('Y-m-d H:i:s'),
                    'organization' => $context['organization']['id'],
                    'user' => $context['user']->id(),
                ],
            );
            if ($written !== 1) {
                return $this->error(403, 'organization_access_changed', 'Tu permiso para guardar cambió.');
            }
        } finally {
            // Do not keep an orphaned file on validation, SQL failure, or revoked membership.
            if (!isset($written) || $written !== 1) {
                @unlink($path);
            }
        }

        $asset = $db->fetchAssociative(
            'SELECT id, original_name, mime_type, size_bytes, created_at FROM gf_vault_assets WHERE id = :id',
            ['id' => $id],
        );

        return $this->privateJson(['data' => ['asset' => $this->publicAsset($asset)]], 201);
    }

    #[Route('/api/admin/vault/{id}/download', name: 'grindflow_vault_download', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function download(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse|BinaryFileResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $asset = $db->fetchAssociative(
            <<<'SQL'
                SELECT asset.original_name, asset.storage_key
                FROM gf_vault_assets asset
                INNER JOIN gf_identity_memberships membership
                    ON membership.organization_id = asset.organization_id
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE asset.id = :id AND asset.organization_id = :organization
                  AND membership.user_id = :user AND actor.is_active = 1
                SQL,
            ['id' => $id, 'organization' => $context['organization']['id'], 'user' => $context['user']->id()],
        );
        if ($asset === false) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }
        $root = (string) $this->getParameter('kernel.project_dir').'/var/vault';
        $path = $root.'/'.$asset['storage_key'].'.blob';
        if (!is_file($path) || is_link($path)) {
            return $this->error(404, 'file_unavailable', 'El archivo no está disponible.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $asset['original_name']);

        return $response;
    }

    /** @return array{user: IdentityUser, organization: array{id:string,name:string,role:string}}|JsonResponse */
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

    /** @param array<string, mixed> $asset
     * @return array{id:string,name:string,mime_type:string,size_bytes:int,created_at:string,download_url:string}
     */
    private function publicAsset(array $asset): array
    {
        return [
            'id' => (string) $asset['id'],
            'name' => (string) $asset['original_name'],
            'mime_type' => (string) $asset['mime_type'],
            'size_bytes' => (int) $asset['size_bytes'],
            'created_at' => (string) $asset['created_at'],
            'download_url' => '/api/admin/vault/'.$asset['id'].'/download',
        ];
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
