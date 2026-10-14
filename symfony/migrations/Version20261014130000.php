<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Library MVP #45/#46: per-asset network allow/deny and reuse policy; additive. */
final class Version20261014130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-asset network rules and reuse policies for the Library';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_asset_network_rules (
                organization_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                network VARCHAR(24) NOT NULL,
                effect VARCHAR(5) NOT NULL,
                updated_by CHAR(36) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id, asset_id, network),
                CONSTRAINT fk_gf_asset_network_rule_asset
                    FOREIGN KEY (organization_id, asset_id)
                    REFERENCES gf_vault_assets (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_asset_network_rule_actor
                    FOREIGN KEY (updated_by) REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_asset_network_rule_network
                    CHECK (network IN ('facebook_page', 'instagram_reels')),
                CONSTRAINT ck_gf_asset_network_rule_effect
                    CHECK (effect IN ('allow', 'deny'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_asset_reuse_policies (
                organization_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                network VARCHAR(24) NOT NULL,
                mode VARCHAR(12) NOT NULL,
                max_uses SMALLINT UNSIGNED DEFAULT NULL,
                min_interval_hours SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                updated_by CHAR(36) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id, asset_id, network),
                CONSTRAINT fk_gf_asset_reuse_policy_asset
                    FOREIGN KEY (organization_id, asset_id)
                    REFERENCES gf_vault_assets (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_asset_reuse_policy_actor
                    FOREIGN KEY (updated_by) REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_asset_reuse_policy_network
                    CHECK (network IN ('any', 'facebook_page', 'instagram_reels')),
                CONSTRAINT ck_gf_asset_reuse_policy_mode
                    CHECK (mode IN ('single_use', 'max_n', 'unlimited')),
                CONSTRAINT ck_gf_asset_reuse_policy_max
                    CHECK (
                        (mode = 'max_n' AND max_uses BETWEEN 1 AND 1000)
                        OR (mode <> 'max_n' AND max_uses IS NULL)
                    ),
                CONSTRAINT ck_gf_asset_reuse_policy_interval
                    CHECK (min_interval_hours <= 8760)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only run destructive down against disposable testing DB, not live.
        $this->addSql('DROP TABLE gf_vault_asset_reuse_policies');
        $this->addSql('DROP TABLE gf_vault_asset_network_rules');
    }
}
