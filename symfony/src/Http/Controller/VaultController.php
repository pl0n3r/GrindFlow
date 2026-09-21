<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Infrastructure\Storage\VaultBlobVerifier;
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
    private const MAX_ORGANIZATION_ASSETS = 100;
    private const MAX_ORGANIZATION_BYTES = 128 * 1024 * 1024;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly PrivateVaultDirectory $storage,
        private readonly VaultBlobVerifier $verifier,
    )
    {
    }

    #[Route('/api/admin/vault', name: 'grindflow_vault_list', methods: ['GET'])]
    public function list(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        // Bound the offset to avoid unbounded scans and reject ambiguous query values.
        $rawPage = $request->query->all()['page'] ?? '1';
        if (!is_string($rawPage) || !preg_match('/^[1-9][0-9]{0,3}$/D', $rawPage) || (int) $rawPage > 1000) {
            return $this->error(422, 'invalid_page', 'Selecciona una página válida (1 a 1000).');
        }
        $page = (int) $rawPage;
        $view = $request->query->all()['view'] ?? 'active';
        if (!is_string($view) || !in_array($view, ['active', 'trash'], true)) {
            return $this->error(422, 'invalid_view', 'Selecciona biblioteca o papelera.');
        }
        // Search remains scoped to the selected tenant and is not allowed to alter quota.
        $rawSearch = $request->query->all()['q'] ?? '';
        if (!is_string($rawSearch)) {
            return $this->error(422, 'invalid_search', 'Indica una búsqueda de hasta 80 caracteres.');
        }
        $search = trim($rawSearch);
        if ($search !== '' && (preg_match('/\\A.{1,80}\\z/usD', $search) !== 1
            || preg_match('/[\\p{C}\\p{Zl}\\p{Zp}]/u', $search) === 1
            || preg_match('/[^\\p{Z}\\p{C}]/u', $search) !== 1)) {
            return $this->error(422, 'invalid_search', 'Indica una búsqueda de hasta 80 caracteres visibles.');
        }
        $format = $request->query->all()['format'] ?? 'all';
        $formats = ['all' => null, 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (!is_string($format) || !array_key_exists($format, $formats)) {
            return $this->error(422, 'invalid_format', 'Selecciona todos los formatos, JPEG, PNG o WebP.');
        }
        $sort = $request->query->all()['sort'] ?? 'recent';
        $sortOrders = [
            'recent' => $view === 'trash' ? 'asset.deleted_at DESC' : 'asset.created_at DESC',
            'oldest' => $view === 'trash' ? 'asset.deleted_at ASC' : 'asset.created_at ASC',
            'name_asc' => 'asset.original_name ASC',
            'name_desc' => 'asset.original_name DESC',
            'size_asc' => 'asset.size_bytes ASC',
            'size_desc' => 'asset.size_bytes DESC',
        ];
        if (!is_string($sort) || !array_key_exists($sort, $sortOrders)) {
            return $this->error(422, 'invalid_sort', 'Selecciona un orden válido.');
        }
        $params = ['organization' => $context['organization']['id'], 'user' => $context['user']->id()];
        $scope = <<<'SQL'
            FROM gf_vault_assets asset
            INNER JOIN gf_identity_memberships membership
                ON membership.organization_id = asset.organization_id
            INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
            WHERE asset.organization_id = :organization
              AND membership.user_id = :user AND actor.is_active = 1
            SQL;
        $usage = $db->fetchAssociative(
            'SELECT COUNT(*) AS count_assets, COALESCE(SUM(asset.size_bytes), 0) AS used_bytes '
            .$scope, $params,
        );
        $filter = $view === 'trash' ? ' AND asset.deleted_at IS NOT NULL' : ' AND asset.deleted_at IS NULL';
        $listParams = $params;
        $listFilter = '';
        if ($search !== '') {
            // Escape LIKE wildcards, including the escape character itself.
            $listFilter .= " AND asset.original_name LIKE :name_search ESCAPE '!'";
            $listParams['name_search'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        }
        if ($formats[$format] !== null) {
            $listFilter .= ' AND asset.mime_type = :mime_filter';
            $listParams['mime_filter'] = $formats[$format];
        }
        $total = (int) $db->fetchOne('SELECT COUNT(*) '.$scope.$filter.$listFilter, $listParams);
        // Order-by is strictly selected from trusted SQL constants above; never interpolate client text.
        $assets = $db->fetchAllAssociative(
            'SELECT asset.id, asset.original_name, asset.mime_type, asset.size_bytes, asset.created_at, asset.deleted_at '
            .$scope.$filter.$listFilter.' ORDER BY '.$sortOrders[$sort].', asset.id DESC LIMIT 30 OFFSET '.(($page - 1) * 30),
            $listParams,
        );

        return $this->privateJson(['data' => [
            'assets' => array_map($this->publicAsset(...), $assets),
            'limit' => 30,
            'page' => $page,
            'view' => $view,
            'format' => $format,
            'sort' => $sort,
            'total' => $total,
            'pages' => (int) ceil($total / 30),
            'quota' => [
                // Retained originals in trash continue to occupy private storage.
                'used_bytes' => (int) $usage['used_bytes'],
                'max_bytes' => self::MAX_ORGANIZATION_BYTES,
                'used_assets' => (int) $usage['count_assets'],
                'max_assets' => self::MAX_ORGANIZATION_ASSETS,
            ],
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
        $root = $this->storage->ensureWritable();
        $file->move($root, $id.'.blob');
        $path = $root.'/'.$id.'.blob';

        try {
            $actualSize = filesize($path);
            $sha256 = hash_file('sha256', $path);
            if ($actualSize === false || $sha256 === false || $actualSize !== $size) {
                return $this->error(422, 'invalid_upload', 'La imagen no pudo verificarse.');
            }
            // Every cooperating upload locks the same organization row before counting.
            // File IO and hashing have finished before the transaction starts.
            $uploadStatus = $db->transactional(function (Connection $db) use ($context, $id, $filename, $mime, $size, $sha256): string {
                $locked = $db->fetchOne(
                    'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                    ['organization' => $context['organization']['id']],
                );
                if ($locked === false) {
                    return 'revoked';
                }
                // Do not disclose even a duplicate state to a revoked actor.
                $allowed = $db->fetchOne(
                    <<<'SQL'
                        SELECT 1 FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = :organization
                          AND actor.id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                        SQL,
                    ['organization' => $context['organization']['id'], 'user' => $context['user']->id()],
                );
                if ($allowed === false) {
                    return 'revoked';
                }
                $usage = $db->fetchAssociative(
                    'SELECT COUNT(*) AS count_assets, COALESCE(SUM(size_bytes), 0) AS used_bytes FROM gf_vault_assets WHERE organization_id = :organization',
                    ['organization' => $context['organization']['id']],
                );
                if ((int) $usage['count_assets'] >= self::MAX_ORGANIZATION_ASSETS
                    || (int) $usage['used_bytes'] + $size > self::MAX_ORGANIZATION_BYTES) {
                    return 'quota';
                }

                // The same organization row lock serializes concurrent uploads.
                // Check retained originals in both views, scoped to this tenant only.
                // An active match wins if old data contains both states.
                $duplicate = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT deleted_at
                        FROM gf_vault_assets
                        WHERE organization_id = :organization AND sha256 = :sha
                        ORDER BY (deleted_at IS NULL) DESC, id DESC
                        LIMIT 1
                        SQL,
                    ['organization' => $context['organization']['id'], 'sha' => $sha256],
                );
                if ($duplicate !== false) {
                    return $duplicate['deleted_at'] === null ? 'duplicate_active' : 'duplicate_trash';
                }

                // Permission and active account are checked again inside the write.
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
                        'id' => $id, 'name' => $filename, 'mime' => $mime, 'size' => $size,
                        'sha' => $sha256, 'storage' => $id, 'created' => gmdate('Y-m-d H:i:s'),
                        'organization' => $context['organization']['id'],
                        'user' => $context['user']->id(),
                    ],
                );

                return $written === 1 ? 'stored' : 'revoked';
            });

            if ($uploadStatus === 'quota') {
                return $this->error(409, 'vault_quota_exceeded', 'La biblioteca alcanzó su cuota: máximo 100 imágenes o 128 MiB por organización.');
            }
            if ($uploadStatus === 'duplicate_active') {
                return $this->error(409, 'vault_duplicate_active', 'Esta imagen ya está en tu biblioteca; no se guardó otra copia.');
            }
            if ($uploadStatus === 'duplicate_trash') {
                return $this->error(409, 'vault_duplicate_trash', 'Esta imagen ya está en tu papelera; puedes restaurarla.');
            }
            if ($uploadStatus !== 'stored') {
                return $this->error(403, 'organization_access_changed', 'Tu permiso para guardar cambió.');
            }
        } finally {
            // A rejected write cannot leave a private blob orphaned.
            if (($uploadStatus ?? null) !== 'stored') {
                @unlink($path);
            }
        }

        $asset = $db->fetchAssociative(
            'SELECT id, original_name, mime_type, size_bytes, created_at FROM gf_vault_assets WHERE id = :id',
            ['id' => $id],
        );

        return $this->privateJson(['data' => ['asset' => $this->publicAsset($asset)]], 201);
    }

    #[Route('/api/admin/vault/{id}', name: 'grindflow_vault_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function detail(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $asset = $db->fetchAssociative(
            <<<'SQL'
                SELECT asset.id, asset.original_name, asset.mime_type, asset.size_bytes, asset.created_at
                FROM gf_vault_assets asset
                INNER JOIN gf_identity_memberships membership
                    ON membership.organization_id = asset.organization_id
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE asset.id = :id AND asset.organization_id = :organization
                  AND membership.user_id = :user AND actor.is_active = 1 AND asset.deleted_at IS NULL
                SQL,
            ['id' => $id, 'organization' => $context['organization']['id'], 'user' => $context['user']->id()],
        );
        if ($asset === false) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }

        return $this->privateJson(['data' => ['asset' => $this->publicAsset($asset)]]);
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
                SELECT asset.original_name, asset.storage_key, asset.size_bytes, asset.sha256
                FROM gf_vault_assets asset
                INNER JOIN gf_identity_memberships membership
                    ON membership.organization_id = asset.organization_id
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE asset.id = :id AND asset.organization_id = :organization
                  AND membership.user_id = :user AND actor.is_active = 1 AND asset.deleted_at IS NULL
                SQL,
            ['id' => $id, 'organization' => $context['organization']['id'], 'user' => $context['user']->id()],
        );
        if ($asset === false) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }
        // Never serve a modified or incomplete original, even when its size
        // matches. The integrity endpoint remains a read-only diagnostic.
        if ($this->verifier->status($asset) !== 'verified') {
            return $this->error(404, 'file_unavailable', 'El archivo no está disponible.');
        }
        $path = $this->storage->root().'/'.$asset['storage_key'].'.blob';

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $asset['original_name']);

        return $response;
    }


    /**
     * Browser-only inline image preview. The original stays outside public/,
     * and every request rechecks the user, tenant and active file state.
     */
    #[Route('/api/admin/vault/{id}/preview', name: 'grindflow_vault_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function preview(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse|BinaryFileResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $asset = $db->fetchAssociative(
            <<<'SQL'
                SELECT asset.storage_key, asset.mime_type, asset.size_bytes, asset.sha256
                FROM gf_vault_assets asset
                INNER JOIN gf_identity_memberships membership
                    ON membership.organization_id = asset.organization_id
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE asset.id = :id AND asset.organization_id = :organization
                  AND membership.user_id = :user AND actor.is_active = 1 AND asset.deleted_at IS NULL
                SQL,
            ['id' => $id, 'organization' => $context['organization']['id'], 'user' => $context['user']->id()],
        );
        if ($asset === false) {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.');
        }
        $mime = (string) $asset['mime_type'];
        if (!in_array($mime, self::MIMES, true)) {
            return $this->error(404, 'file_unavailable', 'El archivo no está disponible.');
        }
        if ($this->verifier->status($asset) !== 'verified') {
            return $this->error(404, 'file_unavailable', 'El archivo no está disponible.');
        }
        $path = $this->storage->root().'/'.$asset['storage_key'].'.blob';

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // Never use a user-controlled filename for inline content.
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.'.$extension);

        return $response;
    }

    /**
     * On-demand, read-only integrity check for retained originals in either
     * view. Never disclose storage keys, paths or the raw stored fingerprint.
     */
    #[Route('/api/admin/vault/{id}/integrity', name: 'grindflow_vault_integrity', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function integrity(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $asset = $db->fetchAssociative(
            <<<'SQL'
                SELECT asset.storage_key, asset.size_bytes, asset.sha256
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
        $status = $this->verifier->status($asset);

        return $this->privateJson(['data' => ['id' => $id, 'status' => $status]]);
    }


    /**
     * Update only the private display/attachment name, never the opaque storage key.
     * Reauthorize the acting membership in the SQL write under the tenant lock.
     */
    #[Route('/api/admin/vault/{id}/name', name: 'grindflow_vault_rename', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function rename(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite renombrar archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        $body = json_decode($request->getContent(), true);
        if ($request->request->all() !== [] || $request->files->all() !== [] || !is_array($body)
            || array_keys($body) !== ['name'] || !is_string($body['name'])) {
            return $this->error(422, 'invalid_name', 'Indica únicamente un nombre de imagen válido.');
        }
        $name = trim($body['name']);
        if (preg_match('/\\A.{2,180}\\z/usD', $name) !== 1
            || preg_match('/[\\p{C}\\p{Zl}\\p{Zp}]/u', $name) === 1
            || preg_match('/[^\\p{Z}\\p{C}]/u', $name) !== 1) {
            return $this->error(422, 'invalid_name', 'El nombre debe tener entre 2 y 180 caracteres visibles.');
        }

        $result = $db->transactional(function (Connection $db) use ($context, $id, $name): string {
            $organization = $context['organization']['id'];
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return 'revoked';
            }
            $asset = $db->fetchAssociative(
                'SELECT id, original_name FROM gf_vault_assets WHERE id = :id AND organization_id = :organization AND deleted_at IS NULL',
                ['id' => $id, 'organization' => $organization],
            );
            if ($asset === false) {
                return 'not_found';
            }
            $allowed = $db->fetchOne(
                <<<'SQL'
                    SELECT 1 FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization
                      AND actor.id = :user AND actor.is_active = 1
                      AND membership.role IN ('admin', 'studio', 'editor')
                    SQL,
                ['organization' => $organization, 'user' => $context['user']->id()],
            );
            if ($allowed === false) {
                return 'revoked';
            }
            if ($asset['original_name'] === $name) {
                return 'updated';
            }

            $written = $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_vault_assets asset SET asset.original_name = :name
                    WHERE asset.id = :id AND asset.organization_id = :organization AND asset.deleted_at IS NULL
                      AND EXISTS (
                        SELECT 1 FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = asset.organization_id
                          AND membership.user_id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                      )
                    SQL,
                ['name' => $name, 'id' => $id, 'organization' => $organization, 'user' => $context['user']->id()],
            );
            return $written === 1 ? 'updated' : 'revoked';
        });
        if ($result === 'not_found') {
            return $this->error(404, 'file_not_found', 'No se encontró la imagen activa en tu organización.');
        }
        if ($result !== 'updated') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para renombrar cambió.');
        }

        return $this->privateJson(['data' => ['id' => $id, 'name' => $name]]);
    }

    #[Route('/api/admin/vault/{id}/trash', name: 'grindflow_vault_trash', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function trash(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        return $this->transition($request, $memberships, $db, $id, 'trash');
    }

    #[Route('/api/admin/vault/{id}/restore', name: 'grindflow_vault_restore', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function restore(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        return $this->transition($request, $memberships, $db, $id, 'restore');
    }

    /**
     * Reversible only. No unlink/physical deletion: the original remains private
     * until a separately audited retention policy is implemented and approved.
     */
    private function transition(Request $request, MembershipContext $memberships, Connection $db, string $id, string $action): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite gestionar estos archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        // No tenant, uploader, storage key or other fields are accepted from the browser.
        if ($request->getContent() !== '' || $request->request->all() !== [] || $request->files->all() !== []) {
            return $this->error(422, 'invalid_action', 'La acción no acepta parámetros de archivo u organización.');
        }

        $result = $db->transactional(function (Connection $db) use ($context, $id, $action): string {
            $locked = $db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $context['organization']['id']],
            );
            if ($locked === false) {
                return 'revoked';
            }
            $asset = $db->fetchAssociative(
                'SELECT id, size_bytes, sha256, storage_key, deleted_at FROM gf_vault_assets WHERE id = :id AND organization_id = :organization',
                ['id' => $id, 'organization' => $context['organization']['id']],
            );
            if ($asset === false) {
                return 'not_found';
            }
            if ($action === 'trash' && $asset['deleted_at'] !== null) {
                return 'already_trashed';
            }
            if ($action === 'restore' && $asset['deleted_at'] === null) {
                return 'already_active';
            }
            if ($action === 'restore') {
                if ($this->verifier->status($asset) !== 'verified') {
                    return 'missing_blob';
                }
                // Quota covers retained blobs in both views; restoration adds no bytes.
            }

            $written = $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_vault_assets asset
                    SET asset.deleted_at = :deleted, asset.deleted_by = :deleter
                    WHERE asset.id = :id AND asset.organization_id = :organization
                      AND EXISTS (
                          SELECT 1 FROM gf_identity_memberships membership
                          INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                          WHERE membership.organization_id = asset.organization_id
                            AND membership.user_id = :user AND actor.is_active = 1
                            AND membership.role IN ('admin', 'studio', 'editor')
                      )
                    SQL,
                [
                    'deleted' => $action === 'trash' ? gmdate('Y-m-d H:i:s') : null,
                    'deleter' => $action === 'trash' ? $context['user']->id() : null,
                    'id' => $id, 'organization' => $context['organization']['id'],
                    'user' => $context['user']->id(),
                ],
            );

            return $written === 1 ? 'updated' : 'revoked';
        });

        return match ($result) {
            'updated' => $this->privateJson(['data' => [
                'id' => $id, 'state' => $action === 'trash' ? 'trash' : 'active',
            ]]),
            'not_found' => $this->error(404, 'file_not_found', 'No se encontró el archivo en tu organización.'),
            'missing_blob' => $this->error(409, 'file_unavailable', 'No se puede restaurar un archivo sin su original privado.'),
            'already_active', 'already_trashed' => $this->error(409, 'vault_state_changed', 'El estado del archivo ya cambió. Actualiza la biblioteca.'),
            default => $this->error(403, 'organization_access_changed', 'Tu permiso para gestionar el archivo cambió.'),
        };
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
     * @return array{id:string,name:string,mime_type:string,size_bytes:int,created_at:string,deleted_at:?string,download_url:string}
     */
    private function publicAsset(array $asset): array
    {
        return [
            'id' => (string) $asset['id'],
            'name' => (string) $asset['original_name'],
            'mime_type' => (string) $asset['mime_type'],
            'size_bytes' => (int) $asset['size_bytes'],
            'created_at' => (string) $asset['created_at'],
            'deleted_at' => isset($asset['deleted_at']) ? (string) $asset['deleted_at'] : null,
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
