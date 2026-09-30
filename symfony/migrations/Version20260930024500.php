<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930024500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fail-closed ControlBot staff ops state and session invalidation metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_identity_users
                ADD session_generation INT UNSIGNED NOT NULL DEFAULT 0,
                ADD last_access_at DATETIME DEFAULT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_ops_nonces (
                key_id VARCHAR(120) NOT NULL,
                nonce VARCHAR(172) NOT NULL,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (key_id, nonce),
                INDEX ix_gf_ops_nonces_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_ops_rate_limits (
                key_id VARCHAR(120) NOT NULL,
                source_ip VARCHAR(45) NOT NULL,
                window_start DATETIME NOT NULL,
                hits INT UNSIGNED NOT NULL,
                PRIMARY KEY (key_id, source_ip, window_start),
                INDEX ix_gf_ops_rate_limits_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_ops_idempotency (
                product VARCHAR(80) NOT NULL,
                actor_key_id VARCHAR(120) NOT NULL,
                action VARCHAR(80) NOT NULL,
                target VARCHAR(120) NOT NULL,
                idempotency_key VARCHAR(160) NOT NULL,
                request_fingerprint CHAR(64) NOT NULL,
                response_status SMALLINT UNSIGNED DEFAULT NULL,
                response_json LONGTEXT DEFAULT NULL CHECK (response_json IS NULL OR JSON_VALID(response_json)),
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (product, actor_key_id, action, target, idempotency_key),
                INDEX ix_gf_ops_idempotency_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_ops_audit (
                id CHAR(36) NOT NULL,
                action VARCHAR(80) NOT NULL,
                actor_key_id VARCHAR(120) NOT NULL,
                staff_id CHAR(36) DEFAULT NULL,
                result VARCHAR(40) NOT NULL,
                request_id CHAR(36) NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_ops_audit_staff (staff_id, occurred_at),
                INDEX ix_gf_ops_audit_actor (actor_key_id, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_ops_staff_login_failures (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id CHAR(36) NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_ops_staff_login_failure_time (occurred_at),
                INDEX ix_gf_ops_staff_login_failure_user (user_id, occurred_at),
                CONSTRAINT fk_gf_ops_staff_login_failure_user
                    FOREIGN KEY (user_id) REFERENCES gf_identity_users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_ops_staff_login_failures');
        $this->addSql('DROP TABLE gf_ops_audit');
        $this->addSql('DROP TABLE gf_ops_idempotency');
        $this->addSql('DROP TABLE gf_ops_rate_limits');
        $this->addSql('DROP TABLE gf_ops_nonces');
        $this->addSql('ALTER TABLE gf_identity_users DROP last_access_at, DROP session_generation');
    }
}
