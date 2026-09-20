<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** S2 reversible trash: retained blobs never leave private var/vault during this slice. */
final class Version20260920194000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record reversible soft deletion without destroying organization-owned originals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD deleted_at DATETIME DEFAULT NULL,
                ADD deleted_by CHAR(36) DEFAULT NULL,
                ADD INDEX ix_gf_vault_assets_org_deleted (organization_id, deleted_at, created_at, id),
                ADD CONSTRAINT fk_gf_vault_assets_deleter
                    FOREIGN KEY (deleted_by) REFERENCES gf_identity_users(id) ON DELETE RESTRICT
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                DROP FOREIGN KEY fk_gf_vault_assets_deleter,
                DROP INDEX ix_gf_vault_assets_org_deleted,
                DROP COLUMN deleted_by,
                DROP COLUMN deleted_at
            SQL);
    }
}
