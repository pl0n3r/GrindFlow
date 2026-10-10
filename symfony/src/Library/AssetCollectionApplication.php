<?php

declare(strict_types=1);

namespace GrindFlow\Library;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Links existing Vault asset identities without copying, moving or deleting blobs.
 * Callers resolve organization from a verified session; every query repeats tenant
 * scope, and composite foreign keys enforce it even if an application check regresses.
 */
final readonly class AssetCollectionApplication
{
    private const VARIANT_TYPES = ['format', 'network', 'campaign'];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{status:string,id?:string} */
    public function createCollection(string $organization, string $actor): array
    {
        return $this->db->transactional(function (Connection $db) use ($organization, $actor): array {
            if (!$this->member($db, $organization, $actor, true)) {
                return ['status' => 'forbidden'];
            }
            $id = Uuid::v7()->toRfc4122();
            $db->insert('gf_vault_collections', [
                'id' => $id,
                'organization_id' => $organization,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return ['status' => 'ok', 'id' => $id];
        });
    }

    /** @return array{status:string,changed?:bool} */
    public function attachAsset(string $organization, string $actor, string $collection, string $asset): array
    {
        if (!Uuid::isValid($collection) || !Uuid::isValid($asset)) {
            return ['status' => 'missing'];
        }

        return $this->db->transactional(function (Connection $db) use ($organization, $actor, $collection, $asset): array {
            if (!$this->member($db, $organization, $actor, true)) {
                return ['status' => 'forbidden'];
            }
            if (!$this->collection($db, $organization, $collection, true)
                || !$this->asset($db, $organization, $asset, true)) {
                return ['status' => 'missing'];
            }

            // Composite tenant FKs and a unique key are the ultimate authority.
            // Duplicate requests are idempotent; no blob or original is altered.
            $changed = $db->executeStatement(
                <<<'SQL'
                    INSERT INTO gf_vault_collection_assets
                        (organization_id, collection_id, asset_id, created_at)
                    VALUES (:organization, :collection, :asset, :created)
                    ON DUPLICATE KEY UPDATE asset_id = asset_id
                    SQL,
                [
                    'organization' => $organization,
                    'collection' => $collection,
                    'asset' => $asset,
                    'created' => gmdate('Y-m-d H:i:s'),
                ],
            );

            return ['status' => 'ok', 'changed' => $changed > 0];
        });
    }

    /** @return array{status:string,assets?:list<string>,has_more?:bool,next_cursor?:?string} */
    public function assetsForCollection(string $organization, string $actor, string $collection, ?string $after = null): array
    {
        if (($after !== null && !Uuid::isValid($after))
            || !Uuid::isValid($collection) || !$this->member($this->db, $organization, $actor, false)
            || !$this->collection($this->db, $organization, $collection, false)) {
            return ['status' => 'missing'];
        }

        $params = ['organization' => $organization, 'collection' => $collection];
        $cursorFilter = '';
        if ($after !== null) {
            $params['after'] = strtolower($after);
            $cursorFilter = ' AND link.asset_id > :after';
        }
        $rows = $this->db->fetchFirstColumn(
            'SELECT link.asset_id FROM gf_vault_collection_assets link
             INNER JOIN gf_vault_assets asset
               ON asset.organization_id = link.organization_id
              AND asset.id = link.asset_id AND asset.deleted_at IS NULL
             WHERE link.organization_id = :organization AND link.collection_id = :collection'
            .$cursorFilter.' ORDER BY link.asset_id LIMIT 101',
            $params,
        );
        $page = array_slice($rows, 0, 100);
        $more = count($rows) > 100;

        return [
            'status' => 'ok',
            'assets' => $page,
            'has_more' => $more,
            'next_cursor' => $more ? (string) end($page) : null,
        ];
    }

    /** @return array{status:string,collections?:list<string>,has_more?:bool,next_cursor?:?string} */
    public function collectionsForAsset(string $organization, string $actor, string $asset, ?string $after = null): array
    {
        if (($after !== null && !Uuid::isValid($after))
            || !Uuid::isValid($asset) || !$this->member($this->db, $organization, $actor, false)
            || !$this->asset($this->db, $organization, $asset, false)) {
            return ['status' => 'missing'];
        }

        $params = ['organization' => $organization, 'asset' => $asset];
        $cursorFilter = '';
        if ($after !== null) {
            $params['after'] = strtolower($after);
            $cursorFilter = ' AND link.collection_id > :after';
        }
        $rows = $this->db->fetchFirstColumn(
            'SELECT link.collection_id FROM gf_vault_collection_assets link
             WHERE link.organization_id = :organization AND link.asset_id = :asset'
            .$cursorFilter.' ORDER BY link.collection_id LIMIT 101',
            $params,
        );
        $page = array_slice($rows, 0, 100);
        $more = count($rows) > 100;

        return [
            'status' => 'ok',
            'collections' => $page,
            'has_more' => $more,
            'next_cursor' => $more ? (string) end($page) : null,
        ];
    }

    /** @return array{status:string,changed?:bool} */
    public function linkVariant(string $organization, string $actor, string $master, string $variant, string $type): array
    {
        if (!Uuid::isValid($master) || !Uuid::isValid($variant)
            || !in_array($type, self::VARIANT_TYPES, true)) {
            return ['status' => 'invalid'];
        }

        // Tenant UUID columns have case-insensitive collation. Normalize once
        // so idempotency and self-link checks use the same identity semantics.
        $master = strtolower($master);
        $variant = strtolower($variant);
        if ($master === $variant) {
            return ['status' => 'invalid'];
        }

        return $this->db->transactional(function (Connection $db) use ($organization, $actor, $master, $variant, $type): array {
            if (!$this->member($db, $organization, $actor, true)) {
                return ['status' => 'forbidden'];
            }
            // Lock both asset identities in stable order to serialize concurrent
            // attempts to create chains/cycles for the same pair.
            $locked = $db->fetchFirstColumn(
                <<<'SQL'
                    SELECT id FROM gf_vault_assets
                    WHERE organization_id = :organization
                      AND id IN (:master, :variant) AND deleted_at IS NULL
                    ORDER BY id FOR UPDATE
                    SQL,
                ['organization' => $organization, 'master' => $master, 'variant' => $variant],
            );
            if (count($locked) !== 2) {
                return ['status' => 'missing'];
            }

            $current = $db->fetchAssociative(
                <<<'SQL'
                    SELECT master_asset_id, variant_type FROM gf_vault_asset_variants
                    WHERE organization_id = :organization AND variant_asset_id = :variant
                    SQL,
                ['organization' => $organization, 'variant' => $variant],
            );
            if ($current !== false) {
                return [
                    'status' => ($current['master_asset_id'] === $master && $current['variant_type'] === $type)
                        ? 'ok' : 'conflict',
                    'changed' => false,
                ];
            }
            // Only master -> leaf links: no self-link, multi-parent or cycles.
            if ($db->fetchOne(
                'SELECT variant_asset_id FROM gf_vault_asset_variants WHERE organization_id = :organization AND variant_asset_id = :master LIMIT 1',
                ['organization' => $organization, 'master' => $master],
            ) !== false || $db->fetchOne(
                'SELECT variant_asset_id FROM gf_vault_asset_variants WHERE organization_id = :organization AND master_asset_id = :variant LIMIT 1',
                ['organization' => $organization, 'variant' => $variant],
            ) !== false) {
                return ['status' => 'conflict'];
            }

            $db->insert('gf_vault_asset_variants', [
                'organization_id' => $organization,
                'master_asset_id' => $master,
                'variant_asset_id' => $variant,
                'variant_type' => $type,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return ['status' => 'ok', 'changed' => true];
        });
    }

    /** @return array{status:string,variants?:list<array{asset_id:string,type:string}>,has_more?:bool,next_cursor?:?string} */
    public function variantsForMaster(string $organization, string $actor, string $master, ?string $after = null): array
    {
        if (($after !== null && !Uuid::isValid($after))
            || !Uuid::isValid($master) || !$this->member($this->db, $organization, $actor, false)
            || !$this->asset($this->db, $organization, $master, false)) {
            return ['status' => 'missing'];
        }

        $params = ['organization' => $organization, 'master' => $master];
        $cursorFilter = '';
        if ($after !== null) {
            $params['after'] = strtolower($after);
            $cursorFilter = ' AND link.variant_asset_id > :after';
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT link.variant_asset_id, link.variant_type
             FROM gf_vault_asset_variants link
             INNER JOIN gf_vault_assets asset ON asset.id = link.variant_asset_id
               AND asset.organization_id = link.organization_id AND asset.deleted_at IS NULL
             WHERE link.organization_id = :organization AND link.master_asset_id = :master'
            .$cursorFilter.' ORDER BY link.variant_asset_id LIMIT 101',
            $params,
        );
        $page = array_slice($rows, 0, 100);
        $more = count($rows) > 100;

        return [
            'status' => 'ok',
            'variants' => array_map(
                static fn (array $row): array => [
                    'asset_id' => (string) $row['variant_asset_id'],
                    'type' => (string) $row['variant_type'],
                ],
                $page,
            ),
            'has_more' => $more,
            'next_cursor' => $more ? (string) end($page)['variant_asset_id'] : null,
        ];
    }

    private function member(Connection $db, string $organization, string $actor, bool $write): bool
    {
        if (!Uuid::isValid($organization) || !Uuid::isValid($actor)) {
            return false;
        }
        $sql = <<<'SQL'
            SELECT membership.user_id FROM gf_identity_memberships membership
            INNER JOIN gf_identity_users actor ON actor.id = membership.user_id
            WHERE membership.organization_id = :organization
              AND membership.user_id = :actor AND actor.is_active = 1
            SQL;
        if ($write) {
            $sql .= " AND membership.role IN ('admin', 'studio', 'editor') FOR UPDATE";
        }
        return $db->fetchOne($sql, ['organization' => $organization, 'actor' => $actor]) !== false;
    }

    private function collection(Connection $db, string $organization, string $collection, bool $lock): bool
    {
        return $db->fetchOne(
            'SELECT id FROM gf_vault_collections WHERE organization_id = :organization AND id = :collection'
            .($lock ? ' FOR UPDATE' : ''),
            ['organization' => $organization, 'collection' => $collection],
        ) !== false;
    }

    private function asset(Connection $db, string $organization, string $asset, bool $lock): bool
    {
        return $db->fetchOne(
            'SELECT id FROM gf_vault_assets WHERE organization_id = :organization AND id = :asset AND deleted_at IS NULL'
            .($lock ? ' FOR UPDATE' : ''),
            ['organization' => $organization, 'asset' => $asset],
        ) !== false;
    }
}
