<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Library MVP #53: per-member deny-only restrictions on top of the role matrix; additive. */
final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deny-only per-member Library permission restrictions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_library_member_permissions (
                organization_id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                capability VARCHAR(24) NOT NULL,
                created_by CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (organization_id, user_id, capability),
                CONSTRAINT fk_gf_library_member_perm_membership
                    FOREIGN KEY (organization_id, user_id)
                    REFERENCES gf_identity_memberships (organization_id, user_id) ON DELETE CASCADE,
                CONSTRAINT fk_gf_library_member_perm_creator
                    FOREIGN KEY (created_by) REFERENCES gf_identity_users (id) ON DELETE RESTRICT,
                CONSTRAINT ck_gf_library_member_perm_capability
                    CHECK (capability IN ('view', 'upload', 'edit', 'approve', 'authorize_publication', 'archive', 'delete'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only run destructive down against disposable testing DB, not live.
        $this->addSql('DROP TABLE gf_library_member_permissions');
    }
}
