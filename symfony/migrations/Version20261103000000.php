<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Reversible only in the disposable CI database; never a production activation. */
final class Version20261103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fail-closed global emergency stop fence and append-only transition audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_emergency_stop (
                id TINYINT UNSIGNED NOT NULL,
                state VARCHAR(8) NOT NULL,
                revision BIGINT UNSIGNED NOT NULL,
                revocation_complete TINYINT(1) NOT NULL DEFAULT 0,
                actor VARCHAR(128) NOT NULL,
                reason VARCHAR(120) NOT NULL,
                changed_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT ck_gf_emergency_stop_id CHECK (id = 1),
                CONSTRAINT ck_gf_emergency_stop_state CHECK (state IN ('STOP','RUNNING')),
                CONSTRAINT ck_gf_emergency_stop_revision CHECK (revision > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_emergency_stop_audit (
                id CHAR(36) NOT NULL,
                state VARCHAR(8) NOT NULL,
                revision BIGINT UNSIGNED NOT NULL,
                actor VARCHAR(128) NOT NULL,
                reason VARCHAR(120) NOT NULL,
                occurred_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_gf_emergency_audit_revision (revision),
                CONSTRAINT ck_gf_emergency_audit_state CHECK (state IN ('STOP','RUNNING'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_emergency_audit_no_update
            BEFORE UPDATE ON gf_emergency_stop_audit FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'emergency audit is append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_emergency_audit_no_delete
            BEFORE DELETE ON gf_emergency_stop_audit FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'emergency audit is append-only';
            END
            SQL);
        // An explicit RUNNING fixture is permitted ONLY in disposable CI.
        // No production/default state is ever implicitly enabled.
        if (getenv('APP_ENV') === 'test'
            && $this->connection->getDatabase() === 'grindflow_symfony_ci') {
            $this->addSql(<<<'SQL'
                INSERT INTO gf_emergency_stop
                    (id, state, revision, revocation_complete, actor, reason, changed_at)
                VALUES (1, 'RUNNING', 1, 1, 'ci-fixture', 'synthetic_ci_only', UTC_TIMESTAMP(6))
                SQL);
            $this->addSql(<<<'SQL'
                INSERT INTO gf_emergency_stop_audit
                    (id, state, revision, actor, reason, occurred_at)
                VALUES ('00000000-0000-4000-8000-000000000877',
                        'RUNNING', 1, 'ci-fixture', 'synthetic_ci_only', UTC_TIMESTAMP(6))
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if (getenv('APP_ENV') !== 'test' ||
            $this->connection->getDatabase() !== 'grindflow_symfony_ci') {
            throw new \RuntimeException('Emergency stop audit is retained outside disposable CI');
        }
        $this->addSql('DROP TRIGGER gf_emergency_audit_no_delete');
        $this->addSql('DROP TRIGGER gf_emergency_audit_no_update');
        $this->addSql('DROP TABLE gf_emergency_stop_audit');
        $this->addSql('DROP TABLE gf_emergency_stop');
    }
}
