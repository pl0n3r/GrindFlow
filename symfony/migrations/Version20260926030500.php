<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Password recovery state for the isolated Symfony identity database. */
final class Version20260926030500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add one-use password reset tokens and secret-free identity security audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_password_reset_tokens (
                user_id CHAR(36) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (user_id),
                UNIQUE KEY uq_gf_password_reset_token_hash (token_hash),
                INDEX ix_gf_password_reset_expiry (expires_at),
                CONSTRAINT fk_gf_password_reset_user
                    FOREIGN KEY (user_id) REFERENCES gf_identity_users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_password_recovery_outbox (
                id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                available_at DATETIME NOT NULL,
                claimed_at DATETIME NULL,
                delivered_at DATETIME NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(48) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gf_password_recovery_outbox_user_kind (user_id, kind),
                INDEX ix_gf_password_recovery_outbox_delivery (kind, delivered_at, available_at),
                CONSTRAINT fk_gf_password_recovery_outbox_user
                    FOREIGN KEY (user_id) REFERENCES gf_identity_users(id)
                    ON DELETE CASCADE,
                CONSTRAINT ck_gf_password_recovery_outbox_kind
                    CHECK (kind IN ('reset','password_changed'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_identity_security_audit (
                id CHAR(36) NOT NULL,
                user_id CHAR(36) NULL,
                event VARCHAR(48) NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_identity_security_audit_user (user_id, occurred_at),
                CONSTRAINT fk_gf_identity_security_audit_user
                    FOREIGN KEY (user_id) REFERENCES gf_identity_users(id)
                    ON DELETE SET NULL,
                CONSTRAINT ck_gf_identity_security_audit_event
                    CHECK (event IN ('password_recovery_requested','password_reset','password_changed'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_identity_security_audit');
        $this->addSql('DROP TABLE gf_password_recovery_outbox');
        $this->addSql('DROP TABLE gf_password_reset_tokens');
    }
}
