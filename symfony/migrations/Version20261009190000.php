<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Library MVP #41: optional UTC validity window per asset; additive and nullable. */
final class Version20261009190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional UTC validity window (valid_from_at, valid_until_at) to Vault assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD valid_from_at DATETIME DEFAULT NULL,
                ADD valid_until_at DATETIME DEFAULT NULL,
                ADD CONSTRAINT ck_gf_vault_assets_validity
                    CHECK (valid_from_at IS NULL OR valid_until_at IS NULL OR valid_until_at > valid_from_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only run destructive down against disposable testing DB, not live.
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                DROP CONSTRAINT ck_gf_vault_assets_validity,
                DROP COLUMN valid_until_at,
                DROP COLUMN valid_from_at
            SQL);
    }
}
