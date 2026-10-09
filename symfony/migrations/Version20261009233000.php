<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Library #42/#43: only relationships, never another media blob.
 * All cross-table asset references carry the same organization_id.
 */
final class Version20261009233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant-bound Vault collections and typed master-to-variant links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_collections (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gf_vault_collection_org_id (organization_id, id),
                CONSTRAINT fk_gf_vault_collection_org FOREIGN KEY (organization_id)
                    REFERENCES gf_identity_organizations(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_collection_assets (
                organization_id CHAR(36) NOT NULL,
                collection_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id, collection_id, asset_id),
                KEY ix_gf_vault_collection_assets_asset (organization_id, asset_id, collection_id),
                CONSTRAINT fk_gf_vault_collection_assets_collection
                    FOREIGN KEY (organization_id, collection_id)
                    REFERENCES gf_vault_collections (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_vault_collection_assets_asset
                    FOREIGN KEY (organization_id, asset_id)
                    REFERENCES gf_vault_assets (organization_id, id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_asset_variants (
                organization_id CHAR(36) NOT NULL,
                master_asset_id CHAR(36) NOT NULL,
                variant_asset_id CHAR(36) NOT NULL,
                variant_type VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id, variant_asset_id),
                KEY ix_gf_vault_variants_master (organization_id, master_asset_id, variant_type, variant_asset_id),
                CONSTRAINT fk_gf_vault_variants_master
                    FOREIGN KEY (organization_id, master_asset_id)
                    REFERENCES gf_vault_assets (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_vault_variants_child
                    FOREIGN KEY (organization_id, variant_asset_id)
                    REFERENCES gf_vault_assets (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_vault_variants_distinct
                    CHECK (master_asset_id <> variant_asset_id),
                CONSTRAINT ck_gf_vault_variants_type
                    CHECK (variant_type IN ('format', 'network', 'campaign'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Destructive rollback belongs ONLY to a disposable test schema.
        // Production rollback requires backup verification and separate authorization.
        $this->addSql('DROP TABLE gf_vault_asset_variants');
        $this->addSql('DROP TABLE gf_vault_collection_assets');
        $this->addSql('DROP TABLE gf_vault_collections');
    }
}
