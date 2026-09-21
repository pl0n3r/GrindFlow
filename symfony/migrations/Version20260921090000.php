<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Planning preferences only. This table never authorizes external delivery. */
final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add one review-only weekly planning rule per organization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_content_rules (
                organization_id CHAR(36) NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                weekdays VARCHAR(32) NOT NULL,
                local_time CHAR(5) NOT NULL,
                max_per_day SMALLINT UNSIGNED NOT NULL,
                mode VARCHAR(20) NOT NULL DEFAULT 'review_only',
                updated_by CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id),
                CONSTRAINT fk_gf_content_rules_org FOREIGN KEY (organization_id)
                    REFERENCES gf_identity_organizations (id) ON DELETE CASCADE,
                CONSTRAINT fk_gf_content_rules_user FOREIGN KEY (updated_by)
                    REFERENCES gf_identity_users (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_content_rules');
    }
}
