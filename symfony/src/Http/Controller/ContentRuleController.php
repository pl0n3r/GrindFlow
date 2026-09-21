<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * S3 planning rule. It is deliberately review-only and cannot publish anything.
 * Organization identity always comes from the verified session membership.
 */
final class ContentRuleController extends AbstractController
{
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    #[Route('/api/admin/rules/weekly', name: 'grindflow_weekly_rule_get', methods: ['GET'])]
    public function getRule(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $row = $db->fetchAssociative(
            'SELECT timezone, weekdays, local_time, max_per_day, mode, updated_at FROM gf_content_rules WHERE organization_id = :organization',
            ['organization' => $context['organization']['id']],
        );

        return $this->privateJson(['data' => ['rule' => $row === false ? null : $this->publicRule($row)]]);
    }

    #[Route('/api/admin/rules/weekly', name: 'grindflow_weekly_rule_put', methods: ['PUT'])]
    public function putRule(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'rule_management_forbidden', 'Tu rol no permite modificar reglas de contenido.');
        }
        if (!$this->isCsrfTokenValid('grindflow_weekly_rule', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->error(422, 'invalid_rule', 'Completa la regla semanal.');
        }
        $keys = array_keys($body);
        sort($keys);
        if ($keys !== ['local_time', 'max_per_day', 'timezone', 'weekdays']
            || !is_string($body['timezone']) || !is_string($body['local_time'])
            || !is_int($body['max_per_day']) || !is_array($body['weekdays'])) {
            return $this->error(422, 'invalid_rule', 'Completa la regla semanal sin identificadores de cuenta.');
        }

        $timezone = trim($body['timezone']);
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $this->error(422, 'invalid_timezone', 'Selecciona una zona horaria IANA válida.');
        }
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $body['local_time']) !== 1) {
            return $this->error(422, 'invalid_local_time', 'Selecciona una hora local válida.');
        }
        if ($body['max_per_day'] < 1 || $body['max_per_day'] > 12) {
            return $this->error(422, 'invalid_frequency', 'El máximo diario debe estar entre 1 y 12.');
        }
        $weekdays = $body['weekdays'];
        if ($weekdays === [] || count($weekdays) > 7 || count(array_unique($weekdays, SORT_REGULAR)) !== count($weekdays)) {
            return $this->error(422, 'invalid_weekdays', 'Selecciona entre uno y siete días sin repetirlos.');
        }
        foreach ($weekdays as $day) {
            if (!is_string($day) || !in_array($day, self::WEEKDAYS, true)) {
                return $this->error(422, 'invalid_weekdays', 'Selecciona días válidos.');
            }
        }
        usort($weekdays, static fn (string $a, string $b): int => array_search($a, self::WEEKDAYS, true) <=> array_search($b, self::WEEKDAYS, true));
        $serializedDays = implode(',', $weekdays);
        $now = gmdate('Y-m-d H:i:s');

        $status = $db->transactional(function (Connection $db) use ($context, $timezone, $serializedDays, $body, $now): string {
            $allowed = $db->fetchOne(
                <<<'SQL'
                    SELECT membership.organization_id
                    FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND membership.user_id = :user
                      AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                    FOR UPDATE
                    SQL,
                ['organization' => $context['organization']['id'], 'user' => $context['user']->id()],
            );
            if ($allowed === false) {
                return 'revoked';
            }
            $db->executeStatement(
                <<<'SQL'
                    INSERT INTO gf_content_rules (
                        organization_id, timezone, weekdays, local_time, max_per_day,
                        mode, updated_by, created_at, updated_at
                    ) VALUES (
                        :organization, :timezone, :weekdays, :local_time, :max_per_day,
                        'review_only', :user, :created, :updated
                    )
                    ON DUPLICATE KEY UPDATE
                        timezone = VALUES(timezone), weekdays = VALUES(weekdays),
                        local_time = VALUES(local_time), max_per_day = VALUES(max_per_day),
                        mode = 'review_only', updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
                    SQL,
                [
                    'organization' => $context['organization']['id'], 'timezone' => $timezone,
                    'weekdays' => $serializedDays, 'local_time' => $body['local_time'],
                    'max_per_day' => $body['max_per_day'], 'user' => $context['user']->id(),
                    'created' => $now, 'updated' => $now,
                ],
            );

            return 'saved';
        });
        if ($status !== 'saved') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para modificar reglas cambió.');
        }

        $row = $db->fetchAssociative(
            'SELECT timezone, weekdays, local_time, max_per_day, mode, updated_at FROM gf_content_rules WHERE organization_id = :organization',
            ['organization' => $context['organization']['id']],
        );

        return $this->privateJson(['data' => ['rule' => $this->publicRule($row)]]);
    }

    #[Route('/api/admin/rules/weekly/preview', name: 'grindflow_weekly_rule_preview', methods: ['GET'])]
    public function preview(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $rule = $db->fetchAssociative(
            'SELECT timezone, weekdays, local_time, max_per_day, mode, updated_at FROM gf_content_rules WHERE organization_id = :organization',
            ['organization' => $context['organization']['id']],
        );
        $assets = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT id, original_name, mime_type, usage_scope, created_at
                FROM gf_vault_assets
                WHERE organization_id = :organization AND deleted_at IS NULL
                ORDER BY created_at DESC, id DESC
                LIMIT 30
                SQL,
            ['organization' => $context['organization']['id']],
        );
        $total = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_assets WHERE organization_id = :organization AND deleted_at IS NULL',
            ['organization' => $context['organization']['id']],
        );

        $items = array_map(function (array $asset) use ($rule): array {
            $reasons = [];
            if ($rule === false) {
                $reasons[] = 'weekly_rule_missing';
            }
            $scope = (string) $asset['usage_scope'];
            if ($scope === 'unclassified') {
                $reasons[] = 'classification_missing';
            } elseif ($scope === 'internal_only') {
                $reasons[] = 'internal_only';
            } elseif ($scope === 'needs_review') {
                $reasons[] = 'content_review_required';
            }
            // S3 intentionally has no distribution-authorization contract yet.
            // Classification alone must never become a publishing permission.
            $reasons[] = 'distribution_authorization_missing';

            return [
                'id' => (string) $asset['id'],
                'name' => (string) $asset['original_name'],
                'mime_type' => (string) $asset['mime_type'],
                'usage_scope' => $scope,
                'eligible' => false,
                'blocking_reasons' => array_values(array_unique($reasons)),
            ];
        }, $assets);

        return $this->privateJson(['data' => [
            'rule' => $rule === false ? null : $this->publicRule($rule),
            'assets' => $items,
            'visible' => count($items),
            'total_active_assets' => $total,
            'limit' => 30,
            'can_publish' => false,
            'mode' => 'review_only',
        ]]);
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

    /** @param array<string, mixed> $row */
    private function publicRule(array $row): array
    {
        return [
            'timezone' => (string) $row['timezone'],
            'weekdays' => $row['weekdays'] === '' ? [] : explode(',', (string) $row['weekdays']),
            'local_time' => (string) $row['local_time'],
            'max_per_day' => (int) $row['max_per_day'],
            'mode' => 'review_only',
            'updated_at' => (string) $row['updated_at'],
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
