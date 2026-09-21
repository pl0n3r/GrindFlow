<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Internal filing state. It does NOT assert rights or publication eligibility. */
final class Version20260921065500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-private internal organization state to Symfony Vault';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD filing_state VARCHAR(20) NOT NULL DEFAULT 'inbox',
                ADD INDEX ix_gf_vault_assets_org_state (organization_id, filing_state, deleted_at, created_at, id),
                ADD CONSTRAINT ck_gf_vault_assets_filing_state CHECK (
                    filing_state IN ('inbox', 'working', 'organized')
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only in disposable Symfony schema. Preserve production metadata
        // through an independently verified backup before any future rollback.
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                DROP CHECK ck_gf_vault_assets_filing_state,
                DROP INDEX ix_gf_vault_assets_org_state,
                DROP COLUMN filing_state
            SQL);
    }
}
