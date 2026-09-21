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
                    ON DELETE RESTRICT
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gf_manual_handoff_events DROP FOREIGN KEY fk_gf_manual_handoff_destination_org');
        $this->addSql('ALTER TABLE gf_manual_handoff_events DROP INDEX ix_gf_manual_handoff_destination');
        $this->addSql('ALTER TABLE gf_manual_handoff_events DROP COLUMN destination_id');
        $this->addSql('DROP TABLE gf_manual_destinations');
    }
}
