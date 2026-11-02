<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Build-ahead: platform-wide plan limits and append-only audit.
 * Does not establish prices, bill customers, enforce quotas, or migrate production.
 */
final class Version20261031194000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add platform plan-limit revisions and append-only audit without enabling billing or enforcement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_plan_limits (
                plan_key VARCHAR(128) NOT NULL,
                storage_bytes BIGINT UNSIGNED NULL,
                traffic_bytes BIGINT UNSIGNED NULL,
                queued_jobs BIGINT UNSIGNED NULL,
                api_calls BIGINT UNSIGNED NULL,
                revision BIGINT UNSIGNED NOT NULL,
                updated_by CHAR(36) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY (plan_key),
                CONSTRAINT ck_gf_plan_revision CHECK (revision >= 1),
                CONSTRAINT fk_gf_plan_updated_by FOREIGN KEY (updated_by)
                    REFERENCES gf_identity_users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_plan_limit_changes (
                id CHAR(36) NOT NULL,
                plan_key VARCHAR(128) NOT NULL,
                actor_id CHAR(36) NOT NULL,
                before_json LONGTEXT NOT NULL,
                after_json LONGTEXT NOT NULL,
                before_revision BIGINT UNSIGNED NOT NULL,
                after_revision BIGINT UNSIGNED NOT NULL,
                changed_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                INDEX ix_gf_plan_limit_changes_plan (plan_key, changed_at, id),
                INDEX ix_gf_plan_limit_changes_actor (actor_id, changed_at),
                CONSTRAINT fk_gf_plan_change_actor FOREIGN KEY (actor_id)
                    REFERENCES gf_identity_users(id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_plan_before_json CHECK (JSON_VALID(before_json)),
                CONSTRAINT ck_gf_plan_after_json CHECK (JSON_VALID(after_json)),
                CONSTRAINT ck_gf_plan_change_revision
                    CHECK (after_revision = before_revision + 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_plan_limit_changes_no_update
            BEFORE UPDATE ON gf_plan_limit_changes FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'plan limit audit is append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_plan_limit_changes_no_delete
            BEFORE DELETE ON gf_plan_limit_changes FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'plan limit audit is append-only';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Never discard operator audit in live databases as a generic rollback.
        if (getenv('APP_ENV') !== 'test'
            || $this->connection->getDatabase() !== 'grindflow_symfony_ci') {
            throw new \RuntimeException('Plan-limit audit cannot be dropped without retention authorization');
        }
        $this->addSql('DROP TRIGGER gf_plan_limit_changes_no_delete');
        $this->addSql('DROP TRIGGER gf_plan_limit_changes_no_update');
        $this->addSql('DROP TABLE gf_plan_limit_changes');
        $this->addSql('DROP TABLE gf_plan_limits');
    }
}
