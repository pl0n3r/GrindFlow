<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling;

use Doctrine\DBAL\Connection;
use GrindFlow\Library\AssetNetworkRules;
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
    /**
     * Blocking reasons that depend on the requested slot, not on the asset alone: a
     * scheduler must retry the asset for a different slot instead of discarding it.
     */
    public const SLOT_DEPENDENT_REASONS = ['asset_not_yet_valid', 'asset_expired', 'reuse_interval'];

    private AssetNetworkRules $networkRules;

    public function __construct(
        private Connection $db,
        private WeeklySlotCalculator $calculator,
        ?AssetNetworkRules $networkRules = null,
    ) {
        $this->networkRules = $networkRules ?? new AssetNetworkRules($db);
    }

    /**
     * Single source of truth for Library asset validity (UTC, half-open window
     * [valid_from_at, valid_until_at)). Null bounds mean an open window. It is a
     * scheduling exclusion only: never a license, approval or publication grant.
     *
     * @return 'not_yet_valid'|'valid'|'expired'
     */
    public static function validityState(?string $validFromAt, ?string $validUntilAt, string $atUtc): string
    {
        if ($validFromAt !== null && $atUtc < $validFromAt) {
            return 'not_yet_valid';
        }
        if ($validUntilAt !== null && $atUtc >= $validUntilAt) {
            return 'expired';
        }

        return 'valid';
    }

    /**
     * @return array<string, mixed>
     */
    public function reserve(
        string $organizationId,
        string $userId,
        string $assetId,
        string $scheduledAtUtc,
        bool $uniqueAutomationSlot = false,
        ?string $expectedAutomationDestination = null,
    ): array {
        return $this->db->transactional(
            function (Connection $db) use (
                $organizationId,
                $userId,
                $assetId,
                $scheduledAtUtc,
                $uniqueAutomationSlot,
                $expectedAutomationDestination,
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
                        SELECT id, original_name, usage_scope, valid_from_at, valid_until_at
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

                // Same window as the preview, revalidated under the asset lock for
                // both "now" and the requested slot (manual and automatic paths).
                $nowUtc = gmdate('Y-m-d H:i:s');
                $slotUtc = str_replace(['T', 'Z'], [' ', ''], $scheduledAtUtc);
                foreach ([$nowUtc, $slotUtc] as $instant) {
                    $validity = self::validityState($asset['valid_from_at'], $asset['valid_until_at'], $instant);
                    if ($validity !== 'valid') {
                        $reasons[] = 'asset_'.$validity;
                        break;
                    }
                }

                // Network allow/deny and reuse policy (#45/#46) for the only delivery
                // network today; the slot instant is part of the decision (interval).
                foreach ($this->networkRules->blockingReasons(
                    $organizationId, $assetId, AssetNetworkRules::DEFAULT_NETWORK, $slotUtc,
                ) as $networkReason) {
                    $reasons[] = $networkReason;
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

                if ($uniqueAutomationSlot) {
                    // Reconstruct authorized times from the same locked row.
                    // An active->paused race invalidates all further writes.
                    if (($rule['automation_state'] ?? null) !== 'active'
                        || $expectedAutomationDestination === null
                        || !hash_equals(
                            (string) ($rule['automation_destination_id'] ?? ''),
                            $expectedAutomationDestination,
                        )) {
                        // An active/paused race or destination reassignment
                        // invalidates the entire automation run under lock.
                        return ['status' => 'revoked'];
                    }
                    try {
                        $times = json_decode((string) ($rule['automation_local_times'] ?? ''), true, 4, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        return ['status' => 'revoked'];
                    }
                    if (!is_array($times) || $times === [] || count($times) > 12
                        || count($times) > (int) $rule['max_per_day']
                        || count(array_unique($times, SORT_REGULAR)) !== count($times)) {
                        return ['status' => 'revoked'];
                    }
                    foreach ($times as $time) {
                        if (!is_string($time)
                            || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) {
                            return ['status' => 'revoked'];
                        }
                    }
                    $rule['local_times'] = $times;
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
                // Automation never consumes a second draft in an occupied
                // time slot, even if max_per_day permits manual capacity >1.
                // Organization lock above makes this decision serializable.
                if ($uniqueAutomationSlot && $reserved > 0) {
                    return ['status' => 'occupied'];
                }
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
