<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S4 tenant-owned manual destinations.
 *
 * Destinations are internal workflow labels only. They do not contain provider
 * credentials, remote API identifiers or publication capability.
 */
final class Version20260921195000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add manual destination catalog and bind handoff events to explicit destinations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_manual_destinations (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                label VARCHAR(80) NOT NULL,
                created_by CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                disabled_at DATETIME DEFAULT NULL,
                disabled_by CHAR(36) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gf_manual_destinations_org_id (organization_id, id),
                UNIQUE KEY uq_gf_manual_destinations_org_label (organization_id, label),
                INDEX ix_gf_manual_destinations_active (organization_id, disabled_at, label),
                CONSTRAINT fk_gf_manual_destinations_org
                    FOREIGN KEY (organization_id)
                    REFERENCES gf_identity_organizations (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_gf_manual_destinations_creator
                    FOREIGN KEY (created_by)
                    REFERENCES gf_identity_users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_gf_manual_destinations_disabler
                    FOREIGN KEY (disabled_by)
                    REFERENCES gf_identity_users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT ck_gf_manual_destinations_label
                    CHECK (CHAR_LENGTH(TRIM(label)) BETWEEN 2 AND 80),
                CONSTRAINT ck_gf_manual_destinations_disabled_state
                    CHECK (
                        (disabled_at IS NULL AND disabled_by IS NULL)
                        OR (disabled_at IS NOT NULL AND disabled_by IS NOT NULL)
                    )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE gf_manual_handoff_events
                ADD destination_id CHAR(36) DEFAULT NULL AFTER draft_id,
                ADD INDEX ix_gf_manual_handoff_destination (organization_id, destination_id, created_at),
                ADD CONSTRAINT fk_gf_manual_handoff_destination_org
                    FOREIGN KEY (organization_id, destination_id)
                    REFERENCES gf_manual_destinations (organization_id, id)
                    ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_manual_destinations_lifecycle_update
            BEFORE UPDATE ON gf_manual_destinations
            FOR EACH ROW
            BEGIN
                IF NOT (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.label <=> NEW.label
                    AND OLD.created_by <=> NEW.created_by
                    AND OLD.created_at <=> NEW.created_at
                    AND (
                        (
                            OLD.disabled_at <=> NEW.disabled_at
                            AND OLD.disabled_by <=> NEW.disabled_by
                        )
                        OR (
                            OLD.disabled_at IS NULL
                            AND OLD.disabled_by IS NULL
                            AND NEW.disabled_at IS NOT NULL
                            AND NEW.disabled_by IS NOT NULL
                        )
                        OR (
                            OLD.disabled_at IS NOT NULL
                            AND OLD.disabled_by IS NOT NULL
                            AND NEW.disabled_at IS NULL
                            AND NEW.disabled_by IS NULL
                        )
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'manual destination lifecycle is constrained';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_manual_destinations_no_delete
            BEFORE DELETE ON gf_manual_destinations
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'manual destinations cannot be deleted';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_manual_destinations_lifecycle_update');
        $this->addSql('DROP TRIGGER IF EXISTS gf_manual_destinations_no_delete');
        $this->addSql('ALTER TABLE gf_manual_handoff_events DROP FOREIGN KEY fk_gf_manual_handoff_destination_org');
        $this->addSql('ALTER TABLE gf_manual_handoff_events DROP INDEX ix_gf_manual_handoff_destination');
        $this->addSql('ALTER TABLE gf_manual_handoff_events DROP COLUMN destination_id');
        $this->addSql('DROP TABLE gf_manual_destinations');
    }
}
