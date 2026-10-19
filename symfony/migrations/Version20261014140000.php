<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Library MVP #44/#54: audited asset lifecycle state and permanent-delete marker; additive. */
final class Version20261014140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Library asset lifecycle state, audit events and permanent-delete marker';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD lifecycle_state VARCHAR(12) NOT NULL DEFAULT 'new',
                ADD purged_at DATETIME DEFAULT NULL,
                ADD purged_by CHAR(36) DEFAULT NULL,
                ADD CONSTRAINT fk_gf_vault_assets_purger
                    FOREIGN KEY (purged_by) REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                ADD CONSTRAINT ck_gf_vault_assets_lifecycle
                    CHECK (lifecycle_state IN ('new', 'ready', 'in_review', 'in_use', 'paused', 'blocked', 'archived'))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_vault_asset_lifecycle_events (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                asset_id CHAR(36) NOT NULL,
                actor_id CHAR(36) NOT NULL,
                from_state VARCHAR(12) NOT NULL,
                to_state VARCHAR(12) NOT NULL,
                outcome VARCHAR(8) NOT NULL,
                reason VARCHAR(24) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_lifecycle_events_asset (organization_id, asset_id, created_at, id),
                CONSTRAINT fk_gf_lifecycle_events_asset
                    FOREIGN KEY (organization_id, asset_id)
                    REFERENCES gf_vault_assets (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_gf_lifecycle_events_actor
                    FOREIGN KEY (actor_id) REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_lifecycle_events_outcome
                    CHECK (outcome IN ('applied', 'rejected')),
                CONSTRAINT ck_gf_lifecycle_events_states
                    CHECK (from_state IN ('new', 'ready', 'in_review', 'in_use', 'paused', 'blocked', 'archived')
                        AND to_state IN ('new', 'ready', 'in_review', 'in_use', 'paused', 'blocked', 'archived', 'expired'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only run destructive down against disposable testing DB, not live.
        $this->addSql('DROP TABLE gf_vault_asset_lifecycle_events');
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                DROP CONSTRAINT ck_gf_vault_assets_lifecycle,
                DROP FOREIGN KEY fk_gf_vault_assets_purger,
                DROP COLUMN purged_by,
                DROP COLUMN purged_at,
                DROP COLUMN lifecycle_state
            SQL);
    }
}
