<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Canonical local reservation boundary for S4 schedule drafts.
 *
 * This service owns the single transaction, locks, eligibility, capacity and
 * idempotency rules. It performs no provider/network I/O and has no HTTP/CSRF
 * concerns so automation can reuse the exact same reservation semantics.
 */
final readonly class ScheduleDraftApplicationService
{
    public function __construct(
        private Connection $db,
        private WeeklySlotCalculator $calculator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reserve(
        string $organizationId,
        string $userId,
        string $assetId,
        string $scheduledAtUtc,
    ): array {
        return $this->db->transactional(
            function (Connection $db) use (
                $organizationId,
                $userId,
                $assetId,
                $scheduledAtUtc,
            ): array {
                // Serialize all draft creation per organization: capacity and
                // same-asset checks must be atomic across concurrent callers.
                if ($db->fetchOne(
                    'SELECT id FROM gf_identity_organizations WHERE id = :organization FOR UPDATE',
                    ['organization' => $organizationId],
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
                    ['organization' => $organizationId, 'user' => $userId],
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
                    ['organization' => $organizationId, 'asset' => $assetId],
                );
                if ($asset === false) {
                    return ['status' => 'missing'];
                }

                $rule = $db->fetchAssociative(
                    'SELECT * FROM gf_content_rules WHERE organization_id = :organization FOR UPDATE',
                    ['organization' => $organizationId],
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
                        ['organization' => $organizationId, 'asset' => $assetId],
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
                    ['organization' => $organizationId, 'asset' => $assetId],
                );
                if ($authorization !== 'grant') {
                    $reasons[] = 'distribution_authorization_missing';
                }
                if ($reasons !== []) {
                    return ['status' => 'blocked', 'reasons' => $reasons];
                }

                $slot = null;
                foreach ($this->calculator->upcoming($rule) as $candidate) {
                    if (hash_equals($candidate['scheduled_at_utc'], $scheduledAtUtc)) {
                        $slot = $candidate;
                        break;
                    }
                }
                if ($slot === null) {
                    return ['status' => 'stale_slot'];
                }

                $dateTime = str_replace(['T', 'Z'], [' ', ''], $scheduledAtUtc);
                $params = [
                    'organization' => $organizationId,
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
                    ['organization' => $organizationId, 'scheduled' => $dateTime],
                );
                if ($reserved >= $slot['capacity']) {
                    return ['status' => 'full'];
                }

                $id = Uuid::v7()->toRfc4122();
                $now = gmdate('Y-m-d H:i:s');
                $db->insert('gf_schedule_drafts', [
                    'id' => $id,
                    'organization_id' => $organizationId,
                    'asset_id' => $assetId,
                    'created_by' => $userId,
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
    }
}
