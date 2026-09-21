<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only export of filtered private catalog metadata. Never include notes,
 * storage identifiers, hashes or links. The limit protects historical data
 * that could exceed the provisional 100-original Vault organization quota.
 */
final class VaultInventoryExportController extends AbstractController
{
    private const MAX_ROWS = 100;

    #[Route('/api/admin/vault/export.csv', name: 'grindflow_vault_export', methods: ['GET'])]
    public function __invoke(Request $request, MembershipContext $memberships, Connection $db): Response
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }
        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización.');
        }
        if ($memberships->find($user->id(), $selected) === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }

        $query = $request->query->all();
        if (array_diff(array_keys($query), ['view', 'q', 'format', 'usage', 'sort']) !== []) {
            return $this->error(422, 'invalid_export_filter', 'La exportación solo acepta los filtros visibles.');
        }
        $view = $query['view'] ?? 'active';
        if (!is_string($view) || !in_array($view, ['active', 'trash'], true)) {
            return $this->error(422, 'invalid_view', 'Selecciona biblioteca o papelera.');
        }
        $rawSearch = $query['q'] ?? '';
        if (!is_string($rawSearch)) {
            return $this->error(422, 'invalid_search', 'Indica una búsqueda de hasta 80 caracteres.');
        }
        $search = trim($rawSearch);
        if ($search !== '' && (preg_match('/\A.{1,80}\z/usD', $search) !== 1
            || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $search) === 1
            || preg_match('/[^\p{Z}\p{C}]/u', $search) !== 1)) {
            return $this->error(422, 'invalid_search', 'Indica una búsqueda de hasta 80 caracteres visibles.');
        }
        $format = $query['format'] ?? 'all';
        $formats = ['all' => null, 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (!is_string($format) || !array_key_exists($format, $formats)) {
            return $this->error(422, 'invalid_format', 'Selecciona todos los formatos, JPEG, PNG o WebP.');
        }
        $usage = $query['usage'] ?? 'all';
        if (!is_string($usage) || !in_array($usage, ['all', 'unclassified', 'internal_only', 'needs_review'], true)) {
            return $this->error(422, 'invalid_usage_scope', 'Selecciona una clasificación válida.');
        }
        $sort = $query['sort'] ?? 'recent';
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

        $params = ['organization' => $selected, 'user' => $user->id()];
        $filters = $view === 'trash' ? ' AND asset.deleted_at IS NOT NULL' : ' AND asset.deleted_at IS NULL';
        if ($usage !== 'all') {
            $filters .= ' AND asset.usage_scope = :usage_scope';
            $params['usage_scope'] = $usage;
        }
        if ($search !== '') {
            $filters .= " AND asset.original_name LIKE :name_search ESCAPE '!'";
            $params['name_search'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        }
        if ($formats[$format] !== null) {
            $filters .= ' AND asset.mime_type = :mime_filter';
            $params['mime_filter'] = $formats[$format];
        }
        $rows = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT asset.id, asset.original_name, asset.mime_type,
                    asset.size_bytes, asset.created_at, asset.deleted_at, asset.usage_scope
                FROM gf_vault_assets asset
                INNER JOIN gf_identity_memberships membership
                    ON membership.organization_id = asset.organization_id
                INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                WHERE asset.organization_id = :organization
                  AND membership.user_id = :user AND actor.is_active = 1
                SQL
                .$filters.' ORDER BY '.$sortOrders[$sort].', asset.id DESC LIMIT '.(self::MAX_ROWS + 1),
            $params,
        );
        if (count($rows) > self::MAX_ROWS) {
            return $this->error(409, 'inventory_too_large', 'Reduce los filtros para exportar un máximo de 100 imágenes.');
        }

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Private inventory buffer is unavailable.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', 'Nombre', 'MIME', 'Bytes', 'Creada UTC', 'Papelera UTC', 'Clasificación interna'], ',', '"', '');
            foreach ($rows as $row) {
                // Spreadsheet apps interpret a leading =,+,-,@ as a formula.
                // Escape every text field, even if it originates in old data.
                fputcsv($stream, array_map(self::safeCell(...), [
                    (string) $row['id'], (string) $row['original_name'],
                    (string) $row['mime_type'], (string) $row['size_bytes'],
                    (string) $row['created_at'], (string) ($row['deleted_at'] ?? ''),
                    (string) $row['usage_scope'],
                ]), ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv)) {
                throw new \RuntimeException('Private inventory could not be encoded.');
            }
        } finally {
            fclose($stream);
        }

        $filename = $view === 'trash' ? 'grindflow-vault-papelera.csv' : 'grindflow-vault-activos.csv';
        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private static function safeCell(string $value): string
    {
        // Prevent invisible control characters from hiding the formula prefix.
        $cleaned = preg_replace('/[\p{C}\p{Zl}\p{Zp}]/u', '', $value);
        $safe = is_string($cleaned) ? $cleaned : '';
        if (preg_match('/\A[\p{Z}\s]*[=+\-@]/u', $safe) === 1) {
            return "'".$safe;
        }

        return $safe;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        $response = $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
