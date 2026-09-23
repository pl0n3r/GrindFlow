<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** S2 is isolated: no Laravel media tables or production records are touched. */
final class Version20260920164500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create private organization-scoped media catalog for isolated Symfony S2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_assets (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                uploaded_by CHAR(36) NOT NULL,
                original_name VARCHAR(180) NOT NULL,
                mime_type VARCHAR(32) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                storage_key CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gf_vault_assets_storage_key (storage_key),
                KEY ix_gf_vault_assets_org_created (organization_id, created_at, id),
                CONSTRAINT fk_gf_vault_assets_org FOREIGN KEY (organization_id)
                    REFERENCES gf_identity_organizations(id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_vault_assets_uploader FOREIGN KEY (uploaded_by)
                    REFERENCES gf_identity_users(id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_vault_assets_size CHECK (size_bytes > 0 AND size_bytes <= 8388608),
                CONSTRAINT ck_gf_vault_assets_mime CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_vault_assets');
    }
}
