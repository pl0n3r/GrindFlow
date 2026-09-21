<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** S4 internal review-only agenda: no provider connection or publication job. */
final class Version20260921173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant-safe cancellable review-only schedule drafts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_schedule_drafts (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                created_by CHAR(36) NOT NULL,
                scheduled_at_utc DATETIME NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                local_date DATE NOT NULL,
                local_time CHAR(5) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'draft',
                created_at DATETIME NOT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                cancelled_by CHAR(36) DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_drafts_org_slot (organization_id, scheduled_at_utc, status),
                INDEX ix_gf_drafts_org_asset (organization_id, asset_id, status),
                INDEX ix_gf_drafts_org_created (organization_id, created_at, id),
                CONSTRAINT fk_gf_drafts_asset_org
                    FOREIGN KEY (organization_id, asset_id)
                    REFERENCES gf_vault_assets (organization_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_gf_drafts_creator FOREIGN KEY (created_by)
                    REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_drafts_canceller FOREIGN KEY (cancelled_by)
                    REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_drafts_status CHECK (status IN ('draft', 'cancelled')),
                CONSTRAINT ck_gf_drafts_cancellation
                    CHECK (
                        (status = 'draft' AND cancelled_at IS NULL AND cancelled_by IS NULL)
                        OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)
                    )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_schedule_drafts');
    }
}
