<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit, revocable S3 distribution authorization per tenant asset.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_distribution_authorizations (
                organization_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                authorized_by CHAR(36) NOT NULL,
                authorized_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id, asset_id),
                CONSTRAINT fk_distribution_authorization_org FOREIGN KEY (organization_id) REFERENCES gf_identity_organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_distribution_authorization_asset FOREIGN KEY (asset_id, organization_id) REFERENCES gf_vault_assets (id, organization_id) ON DELETE CASCADE,
                CONSTRAINT fk_distribution_authorization_actor FOREIGN KEY (authorized_by) REFERENCES gf_identity_users (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_distribution_authorizations');
    }
}
