<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S4 manual handoff audit.
 *
 * Events only record an internal human workflow. They never call a provider,
 * move media outside GrindFlow or prove an external publication happened.
 */
final class Version20260921193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add append-only tenant-safe manual handoff events for schedule drafts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_schedule_drafts
                ADD UNIQUE KEY uq_gf_schedule_drafts_org_id (organization_id, id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_manual_handoff_events (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                draft_id CHAR(36) NOT NULL,
                actor_id CHAR(36) NOT NULL,
                action VARCHAR(12) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_manual_handoff_latest (organization_id, draft_id, created_at, id),
                INDEX ix_gf_manual_handoff_actor (actor_id, created_at),
                CONSTRAINT fk_gf_manual_handoff_draft_org
                    FOREIGN KEY (organization_id, draft_id)
                    REFERENCES gf_schedule_drafts (organization_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_gf_manual_handoff_actor
                    FOREIGN KEY (actor_id)
                    REFERENCES gf_identity_users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT ck_gf_manual_handoff_action
                    CHECK (action IN ('prepare', 'complete', 'fail'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_manual_handoff_events_append_only_update
            BEFORE UPDATE ON gf_manual_handoff_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'manual handoff events are append-only';
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_manual_handoff_events_append_only_delete
            BEFORE DELETE ON gf_manual_handoff_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'manual handoff events are append-only';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_manual_handoff_events_append_only_update');
        $this->addSql('DROP TRIGGER IF EXISTS gf_manual_handoff_events_append_only_delete');
        $this->addSql('DROP TABLE gf_manual_handoff_events');
        $this->addSql('ALTER TABLE gf_schedule_drafts DROP INDEX uq_gf_schedule_drafts_org_id');
    }
}
