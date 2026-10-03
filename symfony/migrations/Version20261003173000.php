<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S4 provider idempotency ledger.
 *
 * It stores no credentials, provider payloads or raw responses. An in-flight
 * row left by an interrupted worker is intentionally treated as ambiguous so
 * GrindFlow cannot duplicate an external publication by retrying blindly.
 */
final class Version20261003173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-safe idempotency state for external publication attempts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_external_publication_attempts (
                id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                idempotency_key VARCHAR(160) NOT NULL,
                request_fingerprint CHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                external_publication_id VARCHAR(191) DEFAULT NULL,
                retry_after_seconds SMALLINT UNSIGNED DEFAULT NULL,
                retry_not_before DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gf_external_attempt_key (organization_id, provider, idempotency_key),
                INDEX ix_gf_external_attempt_status (organization_id, provider, status, retry_not_before),
                CONSTRAINT fk_gf_external_attempt_org
                    FOREIGN KEY (organization_id)
                    REFERENCES gf_identity_organizations (id)
                    ON DELETE RESTRICT,
                CONSTRAINT ck_gf_external_attempt_provider
                    CHECK (provider IN ('facebook_page')),
                CONSTRAINT ck_gf_external_attempt_status
                    CHECK (status IN (
                        'in_flight',
                        'published',
                        'rate_limited',
                        'authentication_failed',
                        'rejected',
                        'ambiguous'
                    )),
                CONSTRAINT ck_gf_external_attempt_result
                    CHECK (
                        (status = 'published' AND external_publication_id IS NOT NULL)
                        OR (status <> 'published' AND external_publication_id IS NULL)
                    ),
                CONSTRAINT ck_gf_external_attempt_retry
                    CHECK (
                        (status = 'rate_limited' AND retry_after_seconds IS NOT NULL AND retry_not_before IS NOT NULL)
                        OR (status <> 'rate_limited' AND retry_after_seconds IS NULL AND retry_not_before IS NULL)
                    )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_external_publication_attempts_state_update
            BEFORE UPDATE ON gf_external_publication_attempts
            FOR EACH ROW
            BEGIN
                IF NOT (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.provider <=> NEW.provider
                    AND OLD.idempotency_key <=> NEW.idempotency_key
                    AND OLD.request_fingerprint <=> NEW.request_fingerprint
                    AND OLD.created_at <=> NEW.created_at
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'external publication attempt identity is immutable';
                END IF;

                IF NOT (
                    (OLD.status = 'in_flight' AND NEW.status IN (
                        'published',
                        'rate_limited',
                        'authentication_failed',
                        'rejected',
                        'ambiguous'
                    ))
                    OR (OLD.status = 'rate_limited' AND NEW.status = 'in_flight')
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'external publication attempt transition is invalid';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_external_publication_attempts_no_delete
            BEFORE DELETE ON gf_external_publication_attempts
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'external publication attempts cannot be deleted';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_external_publication_attempts_state_update');
        $this->addSql('DROP TRIGGER IF EXISTS gf_external_publication_attempts_no_delete');
        $this->addSql('DROP TABLE gf_external_publication_attempts');
    }
}
