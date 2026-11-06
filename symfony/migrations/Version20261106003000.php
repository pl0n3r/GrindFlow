<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Explicit fan-out intent. No external provider calls or secrets.
 *
 * Attempts and published receipts remain exclusively in the existing
 * gf_external_publication_attempts ledger, keyed per delivery.
 */
final class Version20261106003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist tenant-scoped publication groups and independent destination identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_distribution_fanout_groups (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                draft_id CHAR(36) NOT NULL,
                request_key VARCHAR(80) NOT NULL,
                request_fingerprint CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_fanout_group_request (organization_id, draft_id, request_key),
                UNIQUE KEY uq_fanout_group_tenant (id, organization_id),
                INDEX ix_fanout_group_draft (organization_id, draft_id),
                CONSTRAINT fk_fanout_group_org
                    FOREIGN KEY (organization_id) REFERENCES gf_identity_organizations (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_fanout_group_draft
                    FOREIGN KEY (draft_id) REFERENCES gf_schedule_drafts (id)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_distribution_fanout_deliveries (
                id CHAR(36) NOT NULL,
                group_id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                destination_id VARCHAR(32) NOT NULL,
                idempotency_key VARCHAR(160) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_fanout_destination (group_id, provider, destination_id),
                UNIQUE KEY uq_fanout_ledger_key (organization_id, provider, idempotency_key),
                INDEX ix_fanout_group (group_id, organization_id),
                CONSTRAINT fk_fanout_delivery_group
                    FOREIGN KEY (group_id, organization_id)
                    REFERENCES gf_distribution_fanout_groups (id, organization_id)
                    ON DELETE RESTRICT,
                CONSTRAINT ck_fanout_provider
                    CHECK (provider IN ('facebook_page', 'instagram_reels'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversible offline schema only. No external delivery is undone.
        $this->addSql('DROP TABLE gf_distribution_fanout_deliveries');
        $this->addSql('DROP TABLE gf_distribution_fanout_groups');
    }
}
