<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use Doctrine\DBAL\Connection;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\FacebookPageProvider;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Infrastructure\Storage\PrivateVaultDirectory;
use GrindFlow\Infrastructure\Storage\VaultBlobVerifier;
use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Scheduling\WeeklySlotCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * S4 schedule drafts keep a durable local intent and may explicitly hand a
 * verified private photo to the provider. The draft never mirrors provider
 * outcome state: gf_external_publication_attempts remains the single ledger.
 */
final class ScheduleDraftController extends AbstractController
{
    /** Read a bounded tenant-scoped agenda, including cancelled draft history. */
    #[Route('/api/admin/schedules', name: 'grindflow_schedule_drafts_list', methods: ['GET'])]
    public function list(
        Request $request,
        MembershipContext $memberships,
        Connection $db,
        FacebookPageProvider $facebookPage,
    ): JsonResponse
    {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $params = [
            'organization' => $context['organization']['id'],
            'user' => $context['user']->id(),
        ];
        $schema = $db->createSchemaManager();
        $handoffReady = $schema->tablesExist(['gf_manual_handoff_events']);
        $destinationReady = $schema->tablesExist(['gf_manual_destinations']);
        $externalLedgerReady = $schema->tablesExist(['gf_external_publication_attempts']);
        $externalDeliveryReady = false;
        $externalDestinationId = null;
        try {
            $facebookPage->assertAvailableFor($context['organization']['id']);
            $externalDeliveryReady = $externalLedgerReady;
            $externalDestinationId = $facebookPage->destinationPageId();
        } catch (DistributionProviderException) {
            // Provider configuration remains fail-closed and no secret is projected.
        }
        $deliverySelect = $externalLedgerReady
            ? <<<'SQL'
                , (
                    SELECT attempt.status
                    FROM gf_external_publication_attempts attempt
                    WHERE attempt.organization_id = draft.organization_id
                      AND attempt.provider = 'facebook_page'
                      AND attempt.idempotency_key = CONCAT('schedule-draft:', draft.id, ':facebook_page:v1')
                    LIMIT 1
                ) AS external_delivery_status,
                (
                    SELECT attempt.external_publication_id
                    FROM gf_external_publication_attempts attempt
                    WHERE attempt.organization_id = draft.organization_id
                      AND attempt.provider = 'facebook_page'
                      AND attempt.idempotency_key = CONCAT('schedule-draft:', draft.id, ':facebook_page:v1')
                    LIMIT 1
                ) AS external_publication_id,
                (
                    SELECT attempt.updated_at
                    FROM gf_external_publication_attempts attempt
                    WHERE attempt.organization_id = draft.organization_id
                      AND attempt.provider = 'facebook_page'
                      AND attempt.idempotency_key = CONCAT('schedule-draft:', draft.id, ':facebook_page:v1')
                    LIMIT 1
                ) AS external_delivery_updated_at
                SQL
            : ', NULL AS external_delivery_status, NULL AS external_publication_id, NULL AS external_delivery_updated_at';
        $handoffSelect = $handoffReady
            ? <<<'SQL'
                , (
                    SELECT handoff.action
                    FROM gf_manual_handoff_events handoff
                    WHERE handoff.organization_id = draft.organization_id
                      AND handoff.draft_id = draft.id
                    ORDER BY handoff.created_at DESC, handoff.id DESC
                    LIMIT 1
                ) AS manual_handoff_action,
                (
                    SELECT handoff.created_at
                    FROM gf_manual_handoff_events handoff
                    WHERE handoff.organization_id = draft.organization_id
                      AND handoff.draft_id = draft.id
                    ORDER BY handoff.created_at DESC, handoff.id DESC
                    LIMIT 1
                ) AS manual_handoff_updated_at
                SQL
            : ', NULL AS manual_handoff_action, NULL AS manual_handoff_updated_at';
        $destinationSelect = $handoffReady && $destinationReady
            ? <<<'SQL'
                , (
                    SELECT handoff.destination_id
                    FROM gf_manual_handoff_events handoff
                    WHERE handoff.organization_id = draft.organization_id
                      AND handoff.draft_id = draft.id
                    ORDER BY handoff.created_at DESC, handoff.id DESC
                    LIMIT 1
                ) AS manual_destination_id,
                (
                    SELECT destination.label
                    FROM gf_manual_handoff_events handoff
                    LEFT JOIN gf_manual_destinations destination
                      ON destination.id = handoff.destination_id
                     AND destination.organization_id = handoff.organization_id
                    WHERE handoff.organization_id = draft.organization_id
                      AND handoff.draft_id = draft.id
                    ORDER BY handoff.created_at DESC, handoff.id DESC
                    LIMIT 1
                ) AS manual_destination_label
                SQL
            : ', NULL AS manual_destination_id, NULL AS manual_destination_label';
        $scope = <<<'SQL'
            FROM gf_schedule_drafts draft
            INNER JOIN gf_vault_assets asset
                ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
            INNER JOIN gf_identity_memberships membership
                ON membership.organization_id = draft.organization_id
            INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
            WHERE draft.organization_id = :organization AND membership.user_id = :user
              AND actor.is_active = 1
            SQL;
        $rows = $db->fetchAllAssociative(
            'SELECT draft.*, asset.original_name AS asset_name'.$handoffSelect.$destinationSelect.$deliverySelect.' '.$scope
            .' ORDER BY draft.created_at DESC, draft.id DESC LIMIT 30',
            $params,
        );
        $total = (int) $db->fetchOne('SELECT COUNT(*) '.$scope, $params);

        return $this->privateJson(['data' => [
            'drafts' => array_map($this->publicDraft(...), $rows),
            'total' => $total,
            'limit' => 30,
            'manual_handoff_ready' => $handoffReady,
            'manual_destination_ready' => $destinationReady,
            'external_delivery_ready' => $externalDeliveryReady,
            'external_destination_id' => $externalDestinationId,
            'mode' => 'review_only',
            'can_publish' => false,
        ]]);
    }

    /** Reserve exactly one current server-derived weekly slot for an eligible asset. */
    #[Route('/api/admin/schedules', name: 'grindflow_schedule_drafts_create', methods: ['POST'])]
    public function create(
        Request $request,
        MembershipContext $memberships,
        Connection $db,
        WeeklySlotCalculator $calculator,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'schedule_management_forbidden', 'Tu rol no permite preparar borradores.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_schedule_draft',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->error(422, 'invalid_schedule', 'Selecciona un recurso y un horario.');
        }
        $keys = array_keys($body);
        sort($keys);
        if ($keys !== ['asset_id', 'scheduled_at_utc']
            || !is_string($body['asset_id']) || !Uuid::isValid($body['asset_id'])
            || !is_string($body['scheduled_at_utc'])
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D', $body['scheduled_at_utc']) !== 1) {
            return $this->error(422, 'invalid_schedule', 'Selecciona un recurso y un slot UTC válido.');
        }

        $assetId = $body['asset_id'];
        $utc = $body['scheduled_at_utc'];
        $result = $db->transactional(
            function (Connection $db) use ($context, $assetId, $utc, $calculator): array {
                $organization = $context['organization']['id'];
                $user = $context['user']->id();

                // Serialize all draft creation per organization: capacity and
                // same-asset checks must be atomic across concurrent requests.
                if ($db->fetchOne(
                    'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                    ['organization' => $organization],
                ) === false) {
                    return ['status' => 'revoked'];
                }
                if ($db->fetchOne(
                    <<<'SQL'
                        SELECT membership.id
                        FROM gf_identity_memberships membership
                        INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                        WHERE membership.organization_id = :organization
                          AND membership.user_id = :user AND actor.is_active = 1
                          AND membership.role IN ('admin', 'studio', 'editor')
                        FOR UPDATE
                        SQL,
                    ['organization' => $organization, 'user' => $user],
                ) === false) {
                    return ['status' => 'revoked'];
                }
                $asset = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT id, original_name, usage_scope
                        FROM gf_vault_assets
                        WHERE id = :asset AND organization_id = :organization
                          AND deleted_at IS NULL
                        FOR UPDATE
                        SQL,
                    ['organization' => $organization, 'asset' => $assetId],
                );
                if ($asset === false) {
                    return ['status' => 'missing'];
                }
                $rule = $db->fetchAssociative(
                    'SELECT * FROM gf_content_rules WHERE organization_id = :organization FOR UPDATE',
                    ['organization' => $organization],
                );

                $reasons = [];
                if ($rule === false || $rule['mode'] !== 'review_only') {
                    $reasons[] = 'weekly_rule_missing';
                }
                if ($asset['usage_scope'] === 'unclassified') {
                    $reasons[] = 'classification_missing';
                } elseif ($asset['usage_scope'] === 'internal_only') {
                    $reasons[] = 'internal_only';
                } elseif ($asset['usage_scope'] === 'needs_review') {
                    $review = $db->fetchOne(
                        <<<'SQL'
                            SELECT action FROM gf_content_review_events
                            WHERE organization_id = :organization AND asset_id = :asset
                            ORDER BY created_at DESC, id DESC LIMIT 1
                            SQL,
                        ['organization' => $organization, 'asset' => $assetId],
                    );
                    if ($review !== 'approve') {
                        $reasons[] = 'content_review_required';
                    }
                } else {
                    $reasons[] = 'classification_missing';
                }
                $authorization = $db->fetchOne(
                    <<<'SQL'
                        SELECT action FROM gf_distribution_authorization_events
                        WHERE organization_id = :organization AND asset_id = :asset
                        ORDER BY created_at DESC, id DESC LIMIT 1
                        SQL,
                    ['organization' => $organization, 'asset' => $assetId],
                );
                if ($authorization !== 'grant') {
                    $reasons[] = 'distribution_authorization_missing';
                }
                if ($reasons !== []) {
                    return ['status' => 'blocked', 'reasons' => $reasons];
                }

                $slot = null;
                foreach ($calculator->upcoming($rule) as $candidate) {
                    if (hash_equals($candidate['scheduled_at_utc'], $utc)) {
                        $slot = $candidate;
                        break;
                    }
                }
                if ($slot === null) {
                    return ['status' => 'stale_slot'];
                }

                $dateTime = str_replace(['T', 'Z'], [' ', ''], $utc);
                $params = [
                    'organization' => $organization,
                    'asset' => $assetId,
                    'scheduled' => $dateTime,
                ];
                $existing = $db->fetchAssociative(
                    <<<'SQL'
                        SELECT draft.*, asset.original_name AS asset_name
                        FROM gf_schedule_drafts draft
                        INNER JOIN gf_vault_assets asset
                          ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                        WHERE draft.organization_id = :organization AND draft.asset_id = :asset
                          AND draft.scheduled_at_utc = :scheduled AND draft.status = 'draft'
                        LIMIT 1 FOR UPDATE
                        SQL,
                    $params,
                );
                if ($existing !== false) {
                    return ['status' => 'ok', 'changed' => false, 'draft' => $existing];
                }
                $reserved = (int) $db->fetchOne(
                    <<<'SQL'
                        SELECT COUNT(*) FROM gf_schedule_drafts
                        WHERE organization_id = :organization AND scheduled_at_utc = :scheduled
                          AND status = 'draft'
                        SQL,
                    ['organization' => $organization, 'scheduled' => $dateTime],
                );
                if ($reserved >= $slot['capacity']) {
                    return ['status' => 'full'];
                }

                $id = Uuid::v7()->toRfc4122();
                $now = gmdate('Y-m-d H:i:s');
                $db->insert('gf_schedule_drafts', [
                    'id' => $id,
                    'organization_id' => $organization,
                    'asset_id' => $assetId,
                    'created_by' => $user,
                    'scheduled_at_utc' => $dateTime,
                    'timezone' => $slot['timezone'],
                    'local_date' => $slot['local_date'],
                    'local_time' => $slot['local_time'],
                    'status' => 'draft',
                    'created_at' => $now,
                ]);

                return ['status' => 'ok', 'changed' => true, 'draft' => [
                    'id' => $id,
                    'asset_id' => $assetId,
                    'asset_name' => $asset['original_name'],
                    'scheduled_at_utc' => $dateTime,
                    'timezone' => $slot['timezone'],
                    'local_date' => $slot['local_date'],
                    'local_time' => $slot['local_time'],
                    'status' => 'draft',
                    'created_at' => $now,
                    'cancelled_at' => null,
                ]];
            },
        );

        if ($result['status'] === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para programar ha cambiado.');
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'asset_not_found', 'El recurso no está disponible en esta organización.');
        }
        if ($result['status'] === 'blocked') {
            return $this->privateJson(['error' => [
                'code' => 'asset_not_eligible',
                'message' => 'El recurso conserva bloqueos internos.',
                'blocking_reasons' => $result['reasons'],
            ]], 409);
        }
        if ($result['status'] === 'stale_slot') {
            return $this->error(409, 'slot_changed', 'El horario ya no coincide con la regla vigente.');
        }
        if ($result['status'] === 'full') {
            return $this->error(409, 'slot_full', 'El horario alcanzó su capacidad interna.');
        }

        return $this->privateJson(['data' => [
            'draft' => $this->publicDraft($result['draft']),
            'changed' => $result['changed'],
            'publishes' => false,
        ]], $result['changed'] ? 201 : 200);
    }


    /** Persist a mutable caption + configured Page snapshot without external I/O. */
    #[Route(
        '/api/admin/schedules/{id}/delivery-intent',
        name: 'grindflow_schedule_drafts_delivery_intent',
        methods: ['PUT'],
    )]
    public function updateDeliveryIntent(
        string $id,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
        FacebookPageProvider $facebookPage,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'schedule_management_forbidden', 'Tu rol no permite preparar entregas.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_schedule_draft',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($id)) {
            return $this->error(422, 'invalid_schedule', 'Selecciona un borrador válido.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['caption'] || !is_string($body['caption'])) {
            return $this->error(422, 'invalid_delivery_intent', 'Escribe un caption válido.');
        }
        $caption = str_replace(["\r\n", "\r"], "\n", trim($body['caption']));
        if ($caption === '' || mb_strlen($caption) > 5000) {
            return $this->error(422, 'invalid_delivery_intent', 'El caption debe contener entre 1 y 5000 caracteres.');
        }

        try {
            $facebookPage->assertAvailableFor($context['organization']['id']);
        } catch (DistributionProviderException) {
            return $this->error(409, 'facebook_page_unavailable', 'Facebook Page no está configurada para esta organización.');
        }
        $pageId = $facebookPage->destinationPageId();

        $result = $db->transactional(function (Connection $db) use ($context, $id, $caption, $pageId): array {
            $organization = $context['organization']['id'];
            $user = $context['user']->id();
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return ['status' => 'revoked'];
            }
            if ($db->fetchOne(
                <<<'SQL'
                    SELECT membership.id FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND membership.user_id = :user
                      AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                    FOR UPDATE
                    SQL,
                ['organization' => $organization, 'user' => $user],
            ) === false) {
                return ['status' => 'revoked'];
            }
            $draft = $db->fetchAssociative(
                <<<'SQL'
                    SELECT draft.*, asset.original_name AS asset_name
                    FROM gf_schedule_drafts draft
                    INNER JOIN gf_vault_assets asset
                      ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                    WHERE draft.id = :id AND draft.organization_id = :organization
                    FOR UPDATE
                    SQL,
                ['id' => $id, 'organization' => $organization],
            );
            if ($draft === false) {
                return ['status' => 'missing'];
            }
            if ($draft['status'] !== 'draft' || ($draft['delivery_locked_at'] ?? null) !== null) {
                return ['status' => 'locked'];
            }
            if ($db->createSchemaManager()->tablesExist(['gf_manual_handoff_events'])) {
                $handoff = $db->fetchOne(
                    <<<'SQL'
                        SELECT action FROM gf_manual_handoff_events
                        WHERE organization_id = :organization AND draft_id = :draft
                        ORDER BY created_at DESC, id DESC LIMIT 1
                        SQL,
                    ['organization' => $organization, 'draft' => $id],
                );
                if (in_array($handoff, ['prepare', 'complete'], true)) {
                    return ['status' => 'handoff_active'];
                }
            }

            $changed = ($draft['caption'] ?? null) !== $caption
                || ($draft['delivery_provider'] ?? null) !== 'facebook_page'
                || (string) ($draft['delivery_destination_id'] ?? '') !== $pageId;
            if ($changed) {
                $db->update('gf_schedule_drafts', [
                    'caption' => $caption,
                    'delivery_provider' => 'facebook_page',
                    'delivery_destination_id' => $pageId,
                ], ['id' => $id, 'organization_id' => $organization]);
                $draft['caption'] = $caption;
                $draft['delivery_provider'] = 'facebook_page';
                $draft['delivery_destination_id'] = $pageId;
            }

            return ['status' => 'ok', 'changed' => $changed, 'draft' => $draft];
        });

        if ($result['status'] === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');
            return $this->error(403, 'organization_access_changed', 'Tu permiso para preparar entregas ha cambiado.');
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'draft_not_found', 'El borrador no existe en tu organización.');
        }
        if ($result['status'] === 'locked') {
            return $this->error(409, 'delivery_intent_locked', 'La intención de entrega ya está bloqueada y no puede editarse.');
        }
        if ($result['status'] === 'handoff_active') {
            return $this->error(409, 'manual_handoff_active', 'Cierra la salida manual antes de preparar una entrega externa.');
        }

        return $this->privateJson(['data' => [
            'draft' => $this->publicDraft($result['draft']),
            'changed' => $result['changed'],
            'publishes' => false,
            'provider_calls' => false,
        ]]);
    }

    /** Lock the durable intent, commit, then perform one explicit provider call. */
    #[Route(
        '/api/admin/schedules/{id}/publish-facebook',
        name: 'grindflow_schedule_drafts_publish_facebook',
        methods: ['POST'],
    )]
    public function publishFacebook(
        string $id,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
        FacebookPageProvider $facebookPage,
        FacebookPagePublicationService $publication,
        VaultBlobVerifier $vaultVerifier,
        PrivateVaultDirectory $vaultDirectory,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'schedule_management_forbidden', 'Tu rol no permite entregar publicaciones.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_schedule_draft',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($id) || $request->getContent() !== '') {
            return $this->error(422, 'invalid_schedule', 'Selecciona un borrador válido sin cuerpo adicional.');
        }

        try {
            $facebookPage->assertAvailableFor($context['organization']['id']);
        } catch (DistributionProviderException) {
            return $this->error(409, 'facebook_page_unavailable', 'Facebook Page no está configurada para esta organización.');
        }
        $pageId = $facebookPage->destinationPageId();

        $result = $db->transactional(function (Connection $db) use (
            $context,
            $id,
            $pageId,
            $vaultVerifier,
            $vaultDirectory,
        ): array {
            $organization = $context['organization']['id'];
            $user = $context['user']->id();
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return ['status' => 'revoked'];
            }
            if ($db->fetchOne(
                <<<'SQL'
                    SELECT membership.id FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND membership.user_id = :user
                      AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                    FOR UPDATE
                    SQL,
                ['organization' => $organization, 'user' => $user],
            ) === false) {
                return ['status' => 'revoked'];
            }
            $draft = $db->fetchAssociative(
                <<<'SQL'
                    SELECT draft.*, asset.original_name AS asset_name, asset.storage_key,
                           asset.size_bytes, asset.sha256, asset.mime_type, asset.usage_scope,
                           asset.deleted_at
                    FROM gf_schedule_drafts draft
                    INNER JOIN gf_vault_assets asset
                      ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                    WHERE draft.id = :id AND draft.organization_id = :organization
                    FOR UPDATE
                    SQL,
                ['id' => $id, 'organization' => $organization],
            );
            if ($draft === false) {
                return ['status' => 'missing'];
            }
            if ($draft['status'] !== 'draft') {
                return ['status' => 'locked'];
            }
            if ($draft['deleted_at'] !== null || !in_array($draft['mime_type'], ['image/jpeg', 'image/png'], true)) {
                return ['status' => 'asset_invalid'];
            }
            $caption = is_string($draft['caption']) ? trim($draft['caption']) : '';
            if ($caption === '' || mb_strlen($caption) > 5000
                || ($draft['delivery_provider'] ?? null) !== 'facebook_page'
                || (string) ($draft['delivery_destination_id'] ?? '') !== $pageId) {
                return ['status' => 'intent_invalid'];
            }
            $eligibility = $this->assetEligibility($db, $organization, (string) $draft['asset_id'], (string) $draft['usage_scope']);
            if ($eligibility !== []) {
                return ['status' => 'asset_invalid'];
            }
            if ($db->createSchemaManager()->tablesExist(['gf_manual_handoff_events'])) {
                $handoff = $db->fetchOne(
                    <<<'SQL'
                        SELECT action FROM gf_manual_handoff_events
                        WHERE organization_id = :organization AND draft_id = :draft
                        ORDER BY created_at DESC, id DESC LIMIT 1
                        SQL,
                    ['organization' => $organization, 'draft' => $id],
                );
                if (in_array($handoff, ['prepare', 'complete'], true)) {
                    return ['status' => 'handoff_active'];
                }
            }
            if ($this->vaultStatus($vaultVerifier, $draft) !== 'verified') {
                return ['status' => 'blob_invalid'];
            }
            if (!$db->createSchemaManager()->tablesExist(['gf_external_publication_attempts'])) {
                return ['status' => 'ledger_unavailable'];
            }
            try {
                $vaultRoot = $vaultDirectory->root();
            } catch (\Throwable) {
                return ['status' => 'vault_unavailable'];
            }

            if (($draft['delivery_locked_at'] ?? null) === null) {
                $lockedAt = gmdate('Y-m-d H:i:s');
                $db->update('gf_schedule_drafts', [
                    'delivery_locked_at' => $lockedAt,
                ], ['id' => $id, 'organization_id' => $organization]);
                $draft['delivery_locked_at'] = $lockedAt;
            }

            return ['status' => 'ok', 'draft' => $draft, 'vault_root' => $vaultRoot];
        });

        if ($result['status'] === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');
            return $this->error(403, 'organization_access_changed', 'Tu permiso para entregar publicaciones ha cambiado.');
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'draft_not_found', 'El borrador no existe en tu organización.');
        }
        if ($result['status'] === 'locked') {
            return $this->error(409, 'draft_not_publishable', 'El borrador ya no admite entrega externa.');
        }
        if ($result['status'] === 'asset_invalid') {
            return $this->error(409, 'asset_not_publishable', 'El recurso ya no cumple la autorización de distribución.');
        }
        if ($result['status'] === 'intent_invalid') {
            return $this->error(409, 'delivery_intent_incomplete', 'Guarda caption y destino antes de publicar.');
        }
        if ($result['status'] === 'handoff_active') {
            return $this->error(409, 'manual_handoff_active', 'Cierra la salida manual antes de publicar externamente.');
        }
        if ($result['status'] === 'blob_invalid') {
            return $this->error(409, 'vault_blob_unverified', 'El original privado no pudo verificarse.');
        }
        if ($result['status'] === 'ledger_unavailable') {
            return $this->error(409, 'external_delivery_unavailable', 'El registro de entregas externas no está disponible.');
        }
        if ($result['status'] === 'vault_unavailable') {
            return $this->error(409, 'vault_blob_unavailable', 'El almacenamiento privado no está disponible.');
        }

        $draft = $result['draft'];
        if ($this->vaultStatus($vaultVerifier, $draft) !== 'verified') {
            return $this->error(409, 'vault_blob_unverified', 'El original privado dejó de ser verificable antes del envío.');
        }
        $root = $result['vault_root'] ?? null;
        if (!is_string($root) || $root === '') {
            return $this->error(409, 'vault_blob_unavailable', 'El almacenamiento privado no está disponible.');
        }
        $mediaPath = $root.'/'.(string) $draft['storage_key'].'.blob';
        $key = 'schedule-draft:'.$id.':facebook_page:v1';
        $command = new DistributionCommand(
            $context['organization']['id'],
            $key,
            (string) $draft['caption'],
            null,
            $mediaPath,
            (string) $draft['mime_type'],
            strtolower((string) $draft['sha256']),
        );

        try {
            $outcome = $publication->publish($command);
        } catch (DistributionProviderException $exception) {
            $safe = match ($exception->kind) {
                DistributionProviderException::KIND_CONFIGURATION => [409, 'facebook_page_unavailable', 'Facebook Page no está disponible para esta organización.'],
                DistributionProviderException::KIND_AUTHENTICATION => [409, 'authentication_failed', 'Facebook Page requiere revisar su autenticación.'],
                DistributionProviderException::KIND_RATE_LIMIT => [429, 'rate_limited', 'Facebook Page limitó temporalmente la entrega. Reintenta manualmente más tarde.'],
                DistributionProviderException::KIND_REJECTED => [409, 'rejected', 'Facebook Page rechazó la entrega sin marcarla como publicada.'],
                default => [409, 'ambiguous', 'El resultado externo es incierto. No reintentes a ciegas.'],
            };
            $response = $this->error($safe[0], $safe[1], $safe[2]);
            if ($exception->kind === DistributionProviderException::KIND_RATE_LIMIT
                && $exception->retryAfterSeconds !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
            }
            return $response;
        }

        $publishedAt = $db->fetchOne(
            <<<'SQL'
                SELECT updated_at FROM gf_external_publication_attempts
                WHERE organization_id = :organization
                  AND provider = 'facebook_page'
                  AND idempotency_key = :key
                  AND status = 'published'
                LIMIT 1
                SQL,
            ['organization' => $context['organization']['id'], 'key' => $key],
        );

        return $this->privateJson(['data' => [
            'status' => 'published',
            'external_publication_id' => $outcome->externalPublicationId,
            'published_at' => is_string($publishedAt) ? $publishedAt : null,
            'automatic_retry' => false,
        ]]);
    }

    /** Cancel in place without erasing the audit-relevant assignment. */
    #[Route(
        '/api/admin/schedules/{id}/cancel',
        name: 'grindflow_schedule_drafts_cancel',
        methods: ['POST'],
    )]
    public function cancel(
        string $id,
        Request $request,
        MembershipContext $memberships,
        Connection $db,
    ): JsonResponse {
        $context = $this->context($request, $memberships);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if (!$memberships->permissions($context['organization']['role'])['content_prepare']) {
            return $this->error(403, 'schedule_management_forbidden', 'Tu rol no permite cancelar borradores.');
        }
        if (!$this->isCsrfTokenValid(
            'grindflow_schedule_draft',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            return $this->error(403, 'invalid_csrf', 'La solicitud ha caducado o es inválida.');
        }
        if (!Uuid::isValid($id) || $request->getContent() !== '') {
            return $this->error(422, 'invalid_schedule', 'Selecciona un borrador válido sin cuerpo adicional.');
        }

        $result = $db->transactional(function (Connection $db) use ($context, $id): array {
            $organization = $context['organization']['id'];
            $user = $context['user']->id();
            if ($db->fetchOne(
                'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                ['organization' => $organization],
            ) === false) {
                return ['status' => 'revoked'];
            }
            if ($db->fetchOne(
                <<<'SQL'
                    SELECT membership.id FROM gf_identity_memberships membership
                    INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
                    WHERE membership.organization_id = :organization AND membership.user_id = :user
                      AND actor.is_active = 1 AND membership.role IN ('admin', 'studio', 'editor')
                    FOR UPDATE
                    SQL,
                ['organization' => $organization, 'user' => $user],
            ) === false) {
                return ['status' => 'revoked'];
            }
            $draft = $db->fetchAssociative(
                <<<'SQL'
                    SELECT draft.*, asset.original_name AS asset_name
                    FROM gf_schedule_drafts draft
                    INNER JOIN gf_vault_assets asset
                      ON asset.id = draft.asset_id AND asset.organization_id = draft.organization_id
                    WHERE draft.id = :id AND draft.organization_id = :organization
                    FOR UPDATE
                    SQL,
                ['id' => $id, 'organization' => $organization],
            );
            if ($draft === false) {
                return ['status' => 'missing'];
            }
            if ($draft['status'] === 'cancelled') {
                return ['status' => 'ok', 'changed' => false, 'draft' => $draft];
            }
            if (($draft['delivery_locked_at'] ?? null) !== null) {
                return ['status' => 'delivery_locked'];
            }
            if ($db->createSchemaManager()->tablesExist(['gf_manual_handoff_events'])) {
                $handoff = $db->fetchOne(
                    <<<'SQL'
                        SELECT action
                        FROM gf_manual_handoff_events
                        WHERE organization_id = :organization AND draft_id = :draft
                        ORDER BY created_at DESC, id DESC
                        LIMIT 1
                        SQL,
                    ['organization' => $organization, 'draft' => $id],
                );
                if (in_array($handoff, ['prepare', 'complete'], true)) {
                    return ['status' => 'handoff_active'];
                }
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->executeStatement(
                <<<'SQL'
                    UPDATE gf_schedule_drafts
                    SET status = 'cancelled', cancelled_at = :cancelled, cancelled_by = :actor
                    WHERE id = :id AND organization_id = :organization AND status = 'draft'
                    SQL,
                ['cancelled' => $now, 'actor' => $user, 'id' => $id, 'organization' => $organization],
            );
            $draft['status'] = 'cancelled';
            $draft['cancelled_at'] = $now;

            return ['status' => 'ok', 'changed' => true, 'draft' => $draft];
        });

        if ($result['status'] === 'revoked') {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu permiso para cancelar ha cambiado.');
        }
        if ($result['status'] === 'missing') {
            return $this->error(404, 'draft_not_found', 'El borrador no existe en tu organización.');
        }
        if ($result['status'] === 'delivery_locked') {
            return $this->error(
                409,
                'delivery_intent_locked',
                'Una entrega externa bloqueada no puede cancelarse.',
            );
        }
        if ($result['status'] === 'handoff_active') {
            return $this->error(
                409,
                'manual_handoff_active',
                'Cierra o registra como fallida la salida manual antes de cancelar el borrador.',
            );
        }

        return $this->privateJson(['data' => [
            'draft' => $this->publicDraft($result['draft']),
            'changed' => $result['changed'],
            'publishes' => false,
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
    private function publicDraft(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'asset_id' => (string) $row['asset_id'],
            'asset_name' => (string) $row['asset_name'],
            'scheduled_at_utc' => str_replace(' ', 'T', (string) $row['scheduled_at_utc']).'Z',
            'timezone' => (string) $row['timezone'],
            'local_date' => (string) $row['local_date'],
            'local_time' => (string) $row['local_time'],
            'status' => (string) $row['status'],
            'caption' => ($row['caption'] ?? null) === null ? null : (string) $row['caption'],
            'delivery_provider' => ($row['delivery_provider'] ?? null) === null ? null : (string) $row['delivery_provider'],
            'delivery_destination_id' => ($row['delivery_destination_id'] ?? null) === null ? null : (string) $row['delivery_destination_id'],
            'delivery_locked_at' => ($row['delivery_locked_at'] ?? null) === null ? null : (string) $row['delivery_locked_at'],
            'external_delivery_status' => ($row['external_delivery_status'] ?? null) === null ? null : (string) $row['external_delivery_status'],
            'external_publication_id' => ($row['external_publication_id'] ?? null) === null ? null : (string) $row['external_publication_id'],
            'published_at' => (($row['external_delivery_status'] ?? null) === 'published'
                && ($row['external_delivery_updated_at'] ?? null) !== null)
                ? (string) $row['external_delivery_updated_at']
                : null,
            'manual_handoff_status' => match ($row['manual_handoff_action'] ?? null) {
                'prepare' => 'prepared',
                'complete' => 'completed',
                'fail' => 'failed',
                default => 'none',
            },
            'manual_handoff_updated_at' => ($row['manual_handoff_updated_at'] ?? null) === null
                ? null
                : (string) $row['manual_handoff_updated_at'],
            'manual_destination' => ($row['manual_destination_id'] ?? null) === null
                ? null
                : [
                    'id' => (string) $row['manual_destination_id'],
                    'label' => (string) ($row['manual_destination_label'] ?? ''),
                ],
            'created_at' => (string) $row['created_at'],
            'cancelled_at' => $row['cancelled_at'] === null ? null : (string) $row['cancelled_at'],
        ];
    }

    /** @return list<string> */
    private function assetEligibility(
        Connection $db,
        string $organization,
        string $assetId,
        string $usageScope,
    ): array {
        $reasons = [];
        if ($usageScope === 'unclassified') {
            $reasons[] = 'classification_missing';
        } elseif ($usageScope === 'internal_only') {
            $reasons[] = 'internal_only';
        } elseif ($usageScope === 'needs_review') {
            $review = $db->fetchOne(
                <<<'SQL'
                    SELECT action FROM gf_content_review_events
                    WHERE organization_id = :organization AND asset_id = :asset
                    ORDER BY created_at DESC, id DESC LIMIT 1
                    SQL,
                ['organization' => $organization, 'asset' => $assetId],
            );
            if ($review !== 'approve') {
                $reasons[] = 'content_review_required';
            }
        } else {
            $reasons[] = 'classification_missing';
        }
        $authorization = $db->fetchOne(
            <<<'SQL'
                SELECT action FROM gf_distribution_authorization_events
                WHERE organization_id = :organization AND asset_id = :asset
                ORDER BY created_at DESC, id DESC LIMIT 1
                SQL,
            ['organization' => $organization, 'asset' => $assetId],
        );
        if ($authorization !== 'grant') {
            $reasons[] = 'distribution_authorization_missing';
        }

        return $reasons;
    }

    /** @param array{storage_key:mixed,size_bytes:mixed,sha256:mixed} $asset */
    private function vaultStatus(VaultBlobVerifier $verifier, array $asset): string
    {
        try {
            return $verifier->status($asset);
        } catch (\Throwable) {
            return 'unavailable';
        }
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
