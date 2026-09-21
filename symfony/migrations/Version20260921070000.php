<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Conservative internal categorization; not a publication approval. */
final class Version20260921070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-private, non-authorizing Vault usage classification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD usage_scope VARCHAR(20) NOT NULL DEFAULT 'unclassified',
                ADD INDEX ix_gf_vault_assets_org_usage (organization_id, deleted_at, usage_scope, created_at, id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only revert in the disposable Symfony schema after backing up metadata.
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                DROP INDEX ix_gf_vault_assets_org_usage,
                DROP COLUMN usage_scope
            SQL);
    }
}
