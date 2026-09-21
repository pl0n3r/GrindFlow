<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S3 human content-review decisions for immutable Vault originals.
 *
 * Approval means the current original passed GrindFlow's internal content
 * review. It is not proof of rights, consent, age, ownership or provider
 * acceptance, and it never schedules or publishes.
 */
final class Version20260921163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add append-only tenant-safe content review decisions for Vault assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_content_review_events (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                actor_id CHAR(36) NOT NULL,
                action VARCHAR(12) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_content_review_latest (organization_id, asset_id, created_at, id),
                INDEX ix_gf_content_review_actor (actor_id, created_at),
                CONSTRAINT fk_gf_content_review_asset_org
                    FOREIGN KEY (organization_id, asset_id)
                    REFERENCES gf_vault_assets (organization_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_gf_content_review_actor
                    FOREIGN KEY (actor_id)
                    REFERENCES gf_identity_users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT ck_gf_content_review_action
                    CHECK (action IN ('approve', 'revoke'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_content_review_events_append_only_update
            BEFORE UPDATE ON gf_content_review_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'content review events are append-only';
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_content_review_events_append_only_delete
            BEFORE DELETE ON gf_content_review_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'content review events are append-only';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_content_review_events_append_only_update');
        $this->addSql('DROP TRIGGER IF EXISTS gf_content_review_events_append_only_delete');
        $this->addSql('DROP TABLE gf_content_review_events');
    }
}
