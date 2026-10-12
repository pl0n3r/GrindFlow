<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Http\BoundedJsonBody;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Opt-in preferences for local draft creation, NEVER remote publication.
 * Tenant identity comes only from a verified session membership.
 */
final class PublishingAutomationController extends AbstractController
{
    #[Route('/api/admin/rules/automation', name: 'grindflow_automation_rule_get', methods: ['GET'])]
    public function getRule(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$this->schemaReady($db)) {
            return $this->privateJson(['data' => ['ready' => false, 'rule' => null, 'provider_calls' => false]]);
        }
        $row = $db->fetchAssociative(
            'SELECT automation_state, automation_local_times, automation_destination_id, max_per_day, timezone FROM gf_content_rules WHERE organization_id = :organization',
            ['organization' => $context['organization']['id']],
        );
        if ($row === false) {
            return $this->privateJson(['data' => ['ready' => true, 'rule' => null, 'provider_calls' => false]]);
        }
        try {
            $times = json_decode((string) ($row['automation_local_times'] ?? '[]'), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $times = null;
        }
        return $this->privateJson(['data' => [
            'ready' => true,
            'rule' => [
                'state' => in_array($row['automation_state'], ['active', 'paused'], true)
                    ? $row['automation_state'] : 'paused',
                'local_times' => is_array($times) ? $times : [],
                'destination_id' => $row['automation_destination_id'],
                'source' => 'eligible_assets',
                'provider' => 'facebook_page',
                'max_per_day' => (int) $row['max_per_day'],
                'timezone' => (string) $row['timezone'],
            ],
            'provider_calls' => false,
        ]]);
    }

    #[Route('/api/admin/rules/automation', name: 'grindflow_automation_rule_put', methods: ['PUT'])]
    public function putRule(
        Request $request,
        MembershipContext $memberships,
        Connection $db,
        FacebookPageProvider $facebook,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'automation_forbidden');
        }
        if (!$this->isCsrfTokenValid('grindflow_weekly_rule', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error(403, 'invalid_csrf');
        }
        if (!$this->schemaReady($db)) {
            return $this->error(503, 'automation_schema_required');
        }
        $input = BoundedJsonBody::decode($request);
        if (!is_array($input)) {
            return $this->error(422, 'invalid_automation');
        }
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['destination_id', 'local_times', 'source', 'state']
            || !in_array($input['state'], ['active', 'paused'], true)
            || $input['source'] !== 'eligible_assets'
            || !is_array($input['local_times'])
            || !array_is_list($input['local_times'])
            || count($input['local_times']) > 12
            || ($input['state'] === 'active' && $input['local_times'] === [])
            || count(array_unique($input['local_times'], SORT_REGULAR)) !== count($input['local_times'])
            || ($input['destination_id'] !== null
                && (!is_string($input['destination_id'])
                    || preg_match('/^[0-9]{1,32}$/D', $input['destination_id']) !== 1))) {
            return $this->error(422, 'invalid_automation');
        }
        foreach ($input['local_times'] as $time) {
            if (!is_string($time) || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) {
                return $this->error(422, 'invalid_automation_times');
            }
        }
        if ($input['state'] === 'active') {
            try {
                $facebook->assertAvailableFor($context['organization']['id']);
                if (!is_string($input['destination_id'])
                    || !hash_equals($facebook->destinationPageId(), $input['destination_id'])) {
                    return $this->error(422, 'destination_unavailable');
                }
            } catch (DistributionProviderException) {
                return $this->error(422, 'destination_unavailable');
            }
        }

        $status = $db->transactional(function (Connection $tx) use ($context, $input): string {
            // Lock tenant membership and rule immediately before the update;
            // a revoked permission never creates an active rule.
            $allowed = $tx->fetchOne(
                <<<'SQL'
                    SELECT membership.id FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND membership.user_id = :user
                      AND membership.role IN ('admin', 'studio', 'editor') AND actor.is_active = 1
                    FOR UPDATE
                    SQL,
                ['organization' => $context['organization']['id'], 'user' => $context['user']->id()],
            );
            if ($allowed === false) {
                return 'revoked';
            }
            $rule = $tx->fetchAssociative(
                'SELECT max_per_day, mode FROM gf_content_rules WHERE organization_id = :organization FOR UPDATE',
                ['organization' => $context['organization']['id']],
            );
            if ($rule === false || $rule['mode'] !== 'review_only') {
                return 'missing';
            }
            if (count($input['local_times']) > (int) $rule['max_per_day']) {
                return 'invalid_frequency';
            }
            $tx->executeStatement(
                <<<'SQL'
                    UPDATE gf_content_rules SET
                        automation_state = :state,
                        automation_local_times = :times,
                        automation_destination_id = :destination,
                        updated_by = :user,
                        updated_at = :updated
                    WHERE organization_id = :organization
                    SQL,
                [
                    'state' => $input['state'],
                    'times' => json_encode($input['local_times'], JSON_THROW_ON_ERROR),
                    'destination' => $input['destination_id'],
                    'user' => $context['user']->id(),
                    'updated' => gmdate('Y-m-d H:i:s'),
                    'organization' => $context['organization']['id'],
                ],
            );
            return 'saved';
        });
        if ($status !== 'saved') {
            return $this->error($status === 'revoked' ? 403 : 409, 'automation_'.$status);
        }
        return $this->getRule($request, $memberships, $db);
    }

    /** @return array{user:IdentityUser,organization:array<string,mixed>}|JsonResponse */
    private function context(Request $request, MembershipContext $memberships): array|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required');
        }
        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->error(409, 'organization_required');
        }
        $organization = $memberships->find($user->id(), $selected);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');
            return $this->error(403, 'organization_access_changed');
        }
        return ['user' => $user, 'organization' => $organization];
    }

    private function schemaReady(Connection $db): bool
    {
        $schema = $db->createSchemaManager();
        if (!$schema->tablesExist(['gf_content_rules'])) {
            return false;
        }
        $columns = $schema->listTableColumns('gf_content_rules');
        return isset($columns['automation_state'], $columns['automation_local_times'], $columns['automation_destination_id']);
    }

    private function error(int $status, string $code): JsonResponse
    {
        return $this->privateJson(['error' => [
            'code' => $code, 'message' => 'La automatización no está disponible con la configuración actual.',
        ]], $status);
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
