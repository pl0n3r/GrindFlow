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

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_schedule_drafts_lifecycle_update
            BEFORE UPDATE ON gf_schedule_drafts
            FOR EACH ROW
            BEGIN
                IF NOT (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.asset_id <=> NEW.asset_id
                    AND OLD.created_by <=> NEW.created_by
                    AND OLD.scheduled_at_utc <=> NEW.scheduled_at_utc
                    AND OLD.timezone <=> NEW.timezone
                    AND OLD.local_date <=> NEW.local_date
                    AND OLD.local_time <=> NEW.local_time
                    AND OLD.created_at <=> NEW.created_at
                    AND OLD.status = 'draft'
                    AND NEW.status = 'cancelled'
                    AND OLD.cancelled_at IS NULL
                    AND OLD.cancelled_by IS NULL
                    AND NEW.cancelled_at IS NOT NULL
                    AND NEW.cancelled_by IS NOT NULL
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule drafts only allow draft-to-cancelled transition';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_schedule_drafts_no_delete
            BEFORE DELETE ON gf_schedule_drafts
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'schedule draft history cannot be deleted';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_schedule_drafts_lifecycle_update');
        $this->addSql('DROP TRIGGER IF EXISTS gf_schedule_drafts_no_delete');
        $this->addSql('DROP TABLE gf_schedule_drafts');
    }
}
