<?php

declare(strict_types=1);

namespace GrindFlow\Library;

use Doctrine\DBAL\Connection;

/**
 * Per-asset network allow/deny (#45) and reuse policy (#46).
 *
 * Documented safe defaults: no network rule = allowed (current behavior), no
 * reuse policy = unlimited with no minimum interval; an explicit deny always
 * wins; an unknown network or any evaluation failure is denied. A rule only
 * restricts automation/scheduling: it is never a license or a publication grant.
 */
final readonly class AssetNetworkRules
{
    public const NETWORKS = ['facebook_page', 'instagram_reels'];
    public const MODES = ['single_use', 'max_n', 'unlimited'];
    public const REASONS = ['network_denied', 'network_unknown', 'reuse_exhausted', 'reuse_interval'];
    /** The only delivery provider today; drafts without a provider belong to it. */
    public const DEFAULT_NETWORK = 'facebook_page';

    public function __construct(private Connection $db)
    {
    }

    /**
     * Stable blocking reasons for using the asset on a network at the UTC instant
     * `Y-m-d H:i:s`. Empty list = allowed. Uses of the very same slot are the same
     * draft (idempotent reservation) and therefore never count against the asset.
     *
     * @return list<string>
     */
    public function blockingReasons(string $organizationId, string $assetId, string $network, string $atUtc): array
    {
        if (!in_array($network, self::NETWORKS, true)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $atUtc) !== 1) {
            return ['network_unknown'];
        }
        try {
            $effect = $this->db->fetchOne(
                'SELECT effect FROM gf_vault_asset_network_rules WHERE organization_id = :organization AND asset_id = :asset AND network = :network',
                ['organization' => $organizationId, 'asset' => $assetId, 'network' => $network],
            );
            if ($effect === 'deny') {
                return ['network_denied'];
            }
            if ($effect !== false && $effect !== 'allow') {
                return ['network_unknown'];
            }

            return $this->reuseReasons($organizationId, $assetId, $network, $atUtc);
        } catch (\Throwable) {
            return ['network_unknown'];
        }
    }

    /** True when the asset is explicitly denied for the network; unknown network or any failure counts as denied. */
    public function isDenied(string $organizationId, string $assetId, string $network): bool
    {
        if (!in_array($network, self::NETWORKS, true)) {
            return true;
        }
        try {
            return $this->db->fetchOne(
                'SELECT effect FROM gf_vault_asset_network_rules WHERE organization_id = :organization AND asset_id = :asset AND network = :network',
                ['organization' => $organizationId, 'asset' => $assetId, 'network' => $network],
            ) === 'deny';
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return list<string> */
    private function reuseReasons(string $organizationId, string $assetId, string $network, string $atUtc): array
    {
        // A network-specific policy wins over the generic `any` one.
        $policy = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT network, mode, max_uses, min_interval_hours
                FROM gf_vault_asset_reuse_policies
                WHERE organization_id = :organization AND asset_id = :asset AND network IN (:network, 'any')
                ORDER BY network = 'any' ASC LIMIT 1
                SQL,
            ['organization' => $organizationId, 'asset' => $assetId, 'network' => $network],
        );
        if ($policy === false) {
            return [];
        }
        $filter = $policy['network'] === 'any' ? '' : " AND COALESCE(delivery_provider, '".self::DEFAULT_NETWORK."') = :network";
        $params = ['organization' => $organizationId, 'asset' => $assetId, 'at' => $atUtc];
        if ($filter !== '') {
            $params['network'] = $network;
        }
        $reasons = [];
        $uses = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM gf_schedule_drafts WHERE organization_id = :organization AND asset_id = :asset AND status = 'draft' AND scheduled_at_utc <> :at".$filter,
            $params,
        );
        if (($policy['mode'] === 'single_use' && $uses >= 1)
            || ($policy['mode'] === 'max_n' && $uses >= (int) $policy['max_uses'])) {
            $reasons[] = 'reuse_exhausted';
        }
        $interval = (int) $policy['min_interval_hours'];
        if ($interval > 0 && $this->db->fetchOne(
            "SELECT 1 FROM gf_schedule_drafts WHERE organization_id = :organization AND asset_id = :asset AND status = 'draft' AND scheduled_at_utc <> :at AND ABS(TIMESTAMPDIFF(MINUTE, scheduled_at_utc, :at_gap)) < :minutes".$filter.' LIMIT 1',
            $params + ['at_gap' => $atUtc, 'minutes' => $interval * 60],
        ) !== false) {
            $reasons[] = 'reuse_interval';
        }

        return $reasons;
    }

    /** @return array{rules: list<array{network: string, effect: string}>, policies: list<array<string, mixed>>} */
    public function forAsset(string $organizationId, string $assetId): array
    {
        $params = ['organization' => $organizationId, 'asset' => $assetId];

        return [
            'rules' => array_map(
                static fn (array $row): array => ['network' => (string) $row['network'], 'effect' => (string) $row['effect']],
                $this->db->fetchAllAssociative(
                    'SELECT network, effect FROM gf_vault_asset_network_rules WHERE organization_id = :organization AND asset_id = :asset ORDER BY network',
                    $params,
                ),
            ),
            'policies' => array_map(
                static fn (array $row): array => [
                    'network' => (string) $row['network'], 'mode' => (string) $row['mode'],
                    'max_uses' => $row['max_uses'] === null ? null : (int) $row['max_uses'],
                    'min_interval_hours' => (int) $row['min_interval_hours'],
                ],
                $this->db->fetchAllAssociative(
                    'SELECT network, mode, max_uses, min_interval_hours FROM gf_vault_asset_reuse_policies WHERE organization_id = :organization AND asset_id = :asset ORDER BY network',
                    $params,
                ),
            ),
        ];
    }

    /** Set (`allow`/`deny`) or clear (null) the rule; false when invalid or the asset is not active in the organization. */
    public function setRule(string $organizationId, string $assetId, string $network, ?string $effect, string $actorId): bool
    {
        if (!in_array($network, self::NETWORKS, true) || !($effect === null || in_array($effect, ['allow', 'deny'], true))
            || !$this->activeAsset($organizationId, $assetId)) {
            return false;
        }
        $params = ['organization' => $organizationId, 'asset' => $assetId, 'network' => $network];
        if ($effect === null) {
            $this->db->executeStatement(
                'DELETE FROM gf_vault_asset_network_rules WHERE organization_id = :organization AND asset_id = :asset AND network = :network',
                $params,
            );

            return true;
        }
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO gf_vault_asset_network_rules (organization_id, asset_id, network, effect, updated_by, updated_at)
                VALUES (:organization, :asset, :network, :effect, :actor, :now)
                ON DUPLICATE KEY UPDATE effect = VALUES(effect), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
                SQL,
            $params + ['effect' => $effect, 'actor' => $actorId, 'now' => gmdate('Y-m-d H:i:s')],
        );

        return true;
    }

    /** Set or clear (mode null) the reuse policy of one network or `any`. */
    public function setPolicy(string $organizationId, string $assetId, string $network, ?string $mode, ?int $maxUses, int $minIntervalHours, string $actorId): bool
    {
        $valid = ($network === 'any' || in_array($network, self::NETWORKS, true))
            && ($mode === null || in_array($mode, self::MODES, true))
            && ($mode === 'max_n' ? ($maxUses !== null && $maxUses >= 1 && $maxUses <= 1000) : $maxUses === null)
            && $minIntervalHours >= 0 && $minIntervalHours <= 8760;
        if (!$valid || !$this->activeAsset($organizationId, $assetId)) {
            return false;
        }
        $params = ['organization' => $organizationId, 'asset' => $assetId, 'network' => $network];
        if ($mode === null) {
            $this->db->executeStatement(
                'DELETE FROM gf_vault_asset_reuse_policies WHERE organization_id = :organization AND asset_id = :asset AND network = :network',
                $params,
            );

            return true;
        }
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO gf_vault_asset_reuse_policies (organization_id, asset_id, network, mode, max_uses, min_interval_hours, updated_by, updated_at)
                VALUES (:organization, :asset, :network, :mode, :max_uses, :interval, :actor, :now)
                ON DUPLICATE KEY UPDATE mode = VALUES(mode), max_uses = VALUES(max_uses),
                    min_interval_hours = VALUES(min_interval_hours), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
                SQL,
            $params + [
                'mode' => $mode, 'max_uses' => $maxUses, 'interval' => $minIntervalHours,
                'actor' => $actorId, 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );

        return true;
    }

    private function activeAsset(string $organizationId, string $assetId): bool
    {
        return $this->db->fetchOne(
            'SELECT id FROM gf_vault_assets WHERE id = :asset AND organization_id = :organization AND deleted_at IS NULL FOR UPDATE',
            ['asset' => $assetId, 'organization' => $organizationId],
        ) !== false;
    }
}
