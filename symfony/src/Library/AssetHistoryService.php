<?php

declare(strict_types=1);

namespace GrindFlow\Library;

use Doctrine\DBAL\Connection;

/**
 * Functional per-asset timeline (#52) built only from events the system already
 * records: origin, review/authorization decisions, archive state, variants,
 * schedule drafts and manual handoffs. Every query is organization-scoped.
 *
 * Output is allow-listed: actors appear only as an opaque per-organization
 * reference, never as name, e-mail, token, note, caption or storage detail.
 */
final readonly class AssetHistoryService
{
    public const LIMIT = 100;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{asset_id: string, events: list<array<string, mixed>>, limit: int, truncated: bool}|null
     *         null when the asset does not exist inside the organization (cross-tenant is indistinguishable)
     */
    public function forAsset(string $organizationId, string $assetId): ?array
    {
        $asset = $this->db->fetchAssociative(
            'SELECT id, mime_type, size_bytes, created_at, uploaded_by, deleted_at, deleted_by, purged_at, purged_by FROM gf_vault_assets WHERE id = :id AND organization_id = :organization',
            ['id' => $assetId, 'organization' => $organizationId],
        );
        if ($asset === false) {
            return null;
        }
        $params = ['organization' => $organizationId, 'asset' => $assetId];
        $ref = fn (mixed $userId): ?string => is_string($userId) && $userId !== ''
            ? substr(hash('sha256', 'grindflow-library-history|'.$organizationId.'|'.$userId), 0, 12)
            : null;
        $events = [];
        $add = static function (string $type, string $at, ?string $actor, array $detail = []) use (&$events): void {
            $events[] = ['type' => $type, 'at' => str_replace(' ', 'T', $at).'Z', 'actor_ref' => $actor, 'detail' => $detail];
        };

        $add('origin', (string) $asset['created_at'], $ref($asset['uploaded_by']), [
            'mime_type' => (string) $asset['mime_type'], 'size_bytes' => (int) $asset['size_bytes'],
        ]);
        if ($asset['deleted_at'] !== null) {
            $add('archived', (string) $asset['deleted_at'], $ref($asset['deleted_by']));
        }
        if ($asset['purged_at'] !== null) {
            $add('purged', (string) $asset['purged_at'], $ref($asset['purged_by']));
        }
        foreach ($this->db->fetchAllAssociative(
            'SELECT from_state, to_state, outcome, reason, actor_id, created_at FROM gf_vault_asset_lifecycle_events WHERE organization_id = :organization AND asset_id = :asset',
            $params,
        ) as $row) {
            $add('lifecycle_transition', (string) $row['created_at'], $ref($row['actor_id']), [
                'from' => (string) $row['from_state'], 'to' => (string) $row['to_state'],
                'outcome' => (string) $row['outcome'], 'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            ]);
        }
        foreach ($this->db->fetchAllAssociative(
            'SELECT action, actor_id, created_at FROM gf_content_review_events WHERE organization_id = :organization AND asset_id = :asset',
            $params,
        ) as $row) {
            $add('content_review', (string) $row['created_at'], $ref($row['actor_id']), ['action' => (string) $row['action']]);
        }
        foreach ($this->db->fetchAllAssociative(
            'SELECT action, actor_id, created_at FROM gf_distribution_authorization_events WHERE organization_id = :organization AND asset_id = :asset',
            $params,
        ) as $row) {
            $add('distribution_authorization', (string) $row['created_at'], $ref($row['actor_id']), ['action' => (string) $row['action']]);
        }
        foreach ($this->db->fetchAllAssociative(
            'SELECT master_asset_id, variant_asset_id, variant_type, created_at FROM gf_vault_asset_variants WHERE organization_id = :organization AND (master_asset_id = :asset OR variant_asset_id = :asset)',
            $params,
        ) as $row) {
            $isMaster = $row['master_asset_id'] === $assetId;
            $add($isMaster ? 'variant_linked' : 'variant_of', (string) $row['created_at'], null, [
                'variant_type' => (string) $row['variant_type'],
                'related_asset_id' => (string) ($isMaster ? $row['variant_asset_id'] : $row['master_asset_id']),
            ]);
        }
        $drafts = $this->db->fetchAllAssociative(
            'SELECT id, created_by, scheduled_at_utc, status, created_at, cancelled_at, cancelled_by FROM gf_schedule_drafts WHERE organization_id = :organization AND asset_id = :asset',
            $params,
        );
        foreach ($drafts as $draft) {
            $add('schedule_draft', (string) $draft['created_at'], $ref($draft['created_by']), [
                'scheduled_at_utc' => str_replace(' ', 'T', (string) $draft['scheduled_at_utc']).'Z',
                'status' => (string) $draft['status'],
            ]);
            if ($draft['cancelled_at'] !== null) {
                $add('schedule_draft_cancelled', (string) $draft['cancelled_at'], $ref($draft['cancelled_by']));
            }
        }
        if ($drafts !== []) {
            foreach ($this->db->fetchAllAssociative(
                'SELECT handoff.action, handoff.actor_id, handoff.created_at FROM gf_manual_handoff_events handoff INNER JOIN gf_schedule_drafts draft ON draft.id = handoff.draft_id AND draft.organization_id = handoff.organization_id WHERE handoff.organization_id = :organization AND draft.asset_id = :asset',
                $params,
            ) as $row) {
                $add('manual_handoff', (string) $row['created_at'], $ref($row['actor_id']), ['action' => (string) $row['action']]);
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['type']] <=> [$b['at'], $b['type']]);
        $truncated = count($events) > self::LIMIT;

        return [
            'asset_id' => $assetId,
            'events' => array_slice($events, 0, self::LIMIT),
            'limit' => self::LIMIT,
            'truncated' => $truncated,
        ];
    }
}
