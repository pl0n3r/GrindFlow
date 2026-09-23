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
 * Isolated S2 media library. Files never live under public/; no external delivery.
 * Tenant is exclusively the verified session membership, not a client parameter.
 */
final class VaultController extends AbstractController
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_ORGANIZATION_ASSETS = 100;
    private const MAX_ORGANIZATION_BYTES = 128 * 1024 * 1024;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'];

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
        $formats = [
            'all' => null,
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
        ];
        if (!is_string($format) || !array_key_exists($format, $formats)) {
            return $this->error(422, 'invalid_format', 'Selecciona todos los formatos, JPEG, PNG o WebP.');
        }
        // Classification is internal workflow metadata, never a publishing grant.
        $usageScope = $request->query->all()['usage'] ?? 'all';
        $usageScopes = ['all', 'unclassified', 'internal_only', 'needs_review'];
        if (!is_string($usageScope) || !in_array($usageScope, $usageScopes, true)) {
            return $this->error(422, 'invalid_usage_scope', 'Selecciona una clasificación válida.');
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
        if ($usageScope !== 'all') {
            $listFilter .= ' AND asset.usage_scope = :usage_scope';
            $listParams['usage_scope'] = $usageScope;
        }
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
            'SELECT asset.id, asset.original_name, asset.mime_type, asset.size_bytes, asset.created_at, asset.deleted_at, asset.usage_scope '
            .$scope.$filter.$listFilter.' ORDER BY '.$sortOrders[$sort].', asset.id DESC LIMIT 30 OFFSET '.(($page - 1) * 30),
            $listParams,
        );

        return $this->privateJson(['data' => [
            'assets' => array_map($this->publicAsset(...), $assets),
            'limit' => 30,
            'page' => $page,
            'view' => $view,
            'format' => $format,
            'usage' => $usageScope,
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
            return $this->error(422, 'invalid_size', 'El archivo debe pesar entre 1 byte y 8 MiB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!is_string($mime) || !in_array($mime, self::MIMES, true)
            || !$this->hasSupportedMediaSignature($file->getPathname(), $mime)) {
            return $this->error(422, 'invalid_type', 'Solo se aceptan JPEG, PNG, WebP, MP4 o WebM válidos.');
        }

        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '_', $filename) ?: 'archivo';
        $filename = mb_substr(trim($filename), 0, 180);
        if ($filename === '') {
            $filename = 'archivo';
        }

        $id = Uuid::v7()->toRfc4122();
        $root = $this->storage->ensureWritable();
        $file->move($root, $id.'.blob');
        $path = $root.'/'.$id.'.blob';

        try {
            $actualSize = filesize($path);
            $sha256 = hash_file('sha256', $path);
            if ($actualSize === false || $sha256 === false || $actualSize !== $size) {
                return $this->error(422, 'invalid_upload', 'El archivo no pudo verificarse.');
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
                return $this->error(409, 'vault_quota_exceeded', 'La biblioteca alcanzó su cuota: máximo 100 archivos o 128 MiB por organización.');
            }
            if ($uploadStatus === 'duplicate_active') {
                return $this->error(409, 'vault_duplicate_active', 'Este archivo ya está en tu biblioteca; no se guardó otra copia.');
            }
            if ($uploadStatus === 'duplicate_trash') {
                return $this->error(409, 'vault_duplicate_trash', 'Este archivo ya está en tu papelera; puedes restaurarlo.');
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
            'SELECT id, original_name, mime_type, size_bytes, created_at, usage_scope FROM gf_vault_assets WHERE id = :id',
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
                SELECT asset.id, asset.original_name, asset.mime_type, asset.size_bytes, asset.created_at, asset.private_note, asset.usage_scope
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

        return $this->privateJson(['data' => ['asset' => $this->publicAsset($asset) + [
            'note' => $asset['private_note'] === null ? null : (string) $asset['private_note'],
        ]]]);
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
     * Browser-only inline media preview. The original stays outside public/,
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
        $extension = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ][$mime];
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
            return $this->error(422, 'invalid_name', 'Indica únicamente un nombre de archivo válido.');
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
            return $this->error(404, 'file_not_found', 'No se encontró el archivo activo en tu organización.');
        }
        if ($result !== 'updated') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para renombrar cambió.');
        }

        return $this->privateJson(['data' => ['id' => $id, 'name' => $name]]);
    }


    /**
     * Private organization annotation. It is NOT a publication approval,
     * access grant, ownership claim or a change to the binary original.
     */
    #[Route('/api/admin/vault/{id}/note', name: 'grindflow_vault_note', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function note(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite editar las notas.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        $body = json_decode($request->getContent(), true);
        if ($request->request->all() !== [] || $request->files->all() !== [] || !is_array($body)
            || array_keys($body) !== ['note'] || !is_string($body['note'])) {
            return $this->error(422, 'invalid_note', 'Indica únicamente una nota de texto.');
        }
        // A single, visible line prevents control-character ambiguity in
        // downstream exports. Empty text deliberately clears the annotation.
        $note = trim($body['note']);
        if (($note !== '' && preg_match('/\\A.{1,280}\\z/usD', $note) !== 1)
            || preg_match('/[\\p{C}\\p{Zl}\\p{Zp}]/u', $note) !== 0) {
            return $this->error(422, 'invalid_note', 'La nota admite hasta 280 caracteres visibles en una línea.');
        }
        $stored = $note === '' ? null : $note;
        $result = $db->transactional(function (Connection $db) use ($context, $id, $stored): string {
            $organization = $context['organization']['id'];
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return 'revoked';
            }
            $asset = $db->fetchAssociative(
                'SELECT private_note FROM gf_vault_assets WHERE id = :id AND organization_id = :organization AND deleted_at IS NULL',
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
            if ($asset['private_note'] === $stored) {
                return 'updated';
            }
            $written = $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_vault_assets asset SET asset.private_note = :note
                    WHERE asset.id = :id AND asset.organization_id = :organization AND asset.deleted_at IS NULL
                      AND EXISTS (
                        SELECT 1 FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = asset.organization_id
                          AND membership.user_id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                      )
                    SQL,
                ['note' => $stored, 'id' => $id, 'organization' => $organization, 'user' => $context['user']->id()],
            );

            return $written === 1 ? 'updated' : 'revoked';
        });
        if ($result === 'not_found') {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo activo en tu organización.');
        }
        if ($result !== 'updated') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para editar la nota cambió.');
        }

        return $this->privateJson(['data' => ['id' => $id, 'note' => $stored]]);
    }

    /**
     * Conservative internal classification. No value authorizes distribution,
     * proves rights or bypasses compliance. Default: unclassified.
     */
    #[Route('/api/admin/vault/{id}/usage', name: 'grindflow_vault_usage', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function usage(Request $request, MembershipContext $memberships, Connection $db, string $id): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite clasificar archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        $body = json_decode($request->getContent(), true);
        if ($request->request->all() !== [] || $request->files->all() !== [] || !is_array($body)
            || array_keys($body) !== ['usage_scope'] || !is_string($body['usage_scope'])
            || !in_array($body['usage_scope'], ['unclassified', 'internal_only', 'needs_review'], true)) {
            return $this->error(422, 'invalid_usage_scope', 'Indica únicamente una clasificación interna válida.');
        }
        $usage = $body['usage_scope'];
        $result = $db->transactional(function (Connection $db) use ($context, $id, $usage): string {
            $organization = $context['organization']['id'];
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return 'revoked';
            }
            $asset = $db->fetchAssociative(
                'SELECT usage_scope FROM gf_vault_assets WHERE id = :id AND organization_id = :organization AND deleted_at IS NULL',
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
            if ($asset['usage_scope'] === $usage) {
                return 'updated';
            }

            $written = $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_vault_assets asset SET asset.usage_scope = :usage
                    WHERE asset.id = :id AND asset.organization_id = :organization AND asset.deleted_at IS NULL
                      AND EXISTS (
                        SELECT 1 FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = asset.organization_id
                          AND membership.user_id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                      )
                    SQL,
                ['usage' => $usage, 'id' => $id, 'organization' => $organization, 'user' => $context['user']->id()],
            );
            return $written === 1 ? 'updated' : 'revoked';
        });
        if ($result === 'not_found') {
            return $this->error(404, 'file_not_found', 'No se encontró el archivo activo en tu organización.');
        }
        if ($result !== 'updated') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para clasificar cambió.');
        }

        return $this->privateJson(['data' => ['id' => $id, 'usage_scope' => $usage]]);
    }

    /**
     * Atomically classify 1..30 explicit active originals from the selected tenant.
     * Never interpret a classification as permission to publish or evidence of rights.
     */
    #[Route('/api/admin/vault/usage/bulk', name: 'grindflow_vault_usage_bulk', methods: ['POST'])]
    public function bulkUsage(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'vault_manage_forbidden', 'Tu rol no permite clasificar archivos.');
        }
        if (!$this->isCsrfTokenValid('grindflow_vault_manage', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        $body = json_decode($request->getContent(), true);
        if ($request->request->all() !== [] || $request->files->all() !== [] || !is_array($body)) {
            return $this->error(422, 'invalid_bulk_usage', 'Selecciona entre 1 y 30 archivos activos.');
        }
        $keys = array_keys($body);
        sort($keys);
        $ids = $body['ids'] ?? null;
        $usage = $body['usage_scope'] ?? null;
        if ($keys !== ['ids', 'usage_scope'] || !is_array($ids) || !array_is_list($ids)
            || count($ids) < 1 || count($ids) > 30 || !is_string($usage)
            || !in_array($usage, ['unclassified', 'internal_only', 'needs_review'], true)) {
            return $this->error(422, 'invalid_bulk_usage', 'Selecciona entre 1 y 30 archivos y una clasificación válida.');
        }
        foreach ($ids as $id) {
            if (!is_string($id)
                || preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $id) !== 1) {
                return $this->error(422, 'invalid_bulk_usage', 'La selección contiene un identificador inválido.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            return $this->error(422, 'invalid_bulk_usage', 'Cada archivo debe seleccionarse una sola vez.');
        }

        $organization = $context['organization']['id'];
        $actor = $context['user']->id();
        try {
            $result = $db->transactional(function (Connection $db) use ($organization, $actor, $ids, $usage): array|string {
                if ($db->fetchOne('SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                    ['organization' => $organization]) === false) {
                    return 'revoked';
                }
                // Recheck actor BEFORE asset lookup so a revoked actor learns nothing.
                $allowed = $db->fetchOne(
                    <<<'SQL'
                        SELECT 1 FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = :organization
                          AND actor.id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                        SQL,
                    ['organization' => $organization, 'user' => $actor],
                );
                if ($allowed === false) {
                    return 'revoked';
                }
                $assets = $db->fetchAllAssociative(
                    <<<'SQL'
                        SELECT id, usage_scope FROM gf_vault_assets
                        WHERE organization_id = :organization AND deleted_at IS NULL
                          AND id IN (:ids)
                        SQL,
                    ['organization' => $organization, 'ids' => $ids],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
                // No partial success: foreign, trashed or missing ID rejects the ENTIRE batch.
                if (count($assets) !== count($ids)) {
                    return 'not_found';
                }
                $pending = array_values(array_map(
                    static fn (array $asset): string => (string) $asset['id'],
                    array_filter($assets, static fn (array $asset): bool => $asset['usage_scope'] !== $usage),
                ));
                if ($pending === []) {
                    return ['selected_count' => count($ids), 'updated_count' => 0];
                }
                $written = $db->executeStatement(
                    <<<'SQL'
                        UPDATE gf_vault_assets asset
                        SET asset.usage_scope = :usage
                        WHERE asset.organization_id = :organization AND asset.deleted_at IS NULL
                          AND asset.id IN (:ids) AND asset.usage_scope <> :usage_check
                          AND EXISTS (
                              SELECT 1 FROM gf_identity_memberships membership
                              INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                              WHERE membership.organization_id = asset.organization_id
                                AND membership.user_id = :user AND actor.is_active = 1
                                AND membership.role IN ('admin', 'studio', 'editor')
                          )
                        SQL,
                    [
                        'usage' => $usage, 'usage_check' => $usage, 'organization' => $organization,
                        'ids' => $pending, 'user' => $actor,
                    ],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
                if ($written !== count($pending)) {
                    // Throw to roll back even if an unexpected concurrent writer changed a row.
                    throw new \LogicException('Vault bulk write lost its authorization or active asset set.');
                }

                return ['selected_count' => count($ids), 'updated_count' => $written];
            });
        } catch (\LogicException) {
            return $this->error(403, 'organization_access_changed', 'Tu acceso cambió; no se clasificó ningún archivo.');
        }
        if ($result === 'not_found') {
            return $this->error(404, 'file_not_found', 'Algún archivo ya no está activo en esta organización.');
        }
        if ($result === 'revoked') {
            return $this->error(403, 'organization_access_changed', 'Tu permiso para clasificar cambió.');
        }

        return $this->privateJson(['data' => [
            'usage_scope' => $usage,
            'selected_count' => $result['selected_count'],
            'updated_count' => $result['updated_count'],
        ]]);
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

    /**
     * Validate a bounded media signature without invoking external tools.
     * Quick upload stays capped at 8 MiB; deeper inspection/transcoding belongs
     * to the asynchronous media-processing pipeline.
     */
    private function hasSupportedMediaSignature(string $path, string $mime): bool
    {
        if (str_starts_with($mime, 'image/')) {
            return @getimagesize($path) !== false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $head = fread($handle, 4096);
        } finally {
            fclose($handle);
        }
        if (!is_string($head)) {
            return false;
        }
        if ($mime === 'video/webm') {
            return strlen($head) >= 4 && substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
        }
        if ($mime !== 'video/mp4') {
            return false;
        }

        $offset = 0;
        $length = strlen($head);
        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($head, $offset, 4));
            $boxSize = is_array($size) ? (int) ($size[1] ?? 0) : 0;
            $type = substr($head, $offset + 4, 4);
            if ($type === 'ftyp') {
                return $boxSize >= 16 && $offset + $boxSize <= $length;
            }
            if ($boxSize < 8 || $offset + $boxSize > $length) {
                return false;
            }
            $offset += $boxSize;
        }
        return false;
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
            'usage_scope' => (string) ($asset['usage_scope'] ?? 'unclassified'),
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
