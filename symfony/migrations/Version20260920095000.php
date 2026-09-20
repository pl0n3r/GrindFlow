<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S1 identity foundation for an ISOLATED Symfony database.
 * This migration is never run against Laravel's production schema.
 */
final class Version20260920095000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create isolated users, organizations and immutable-membership identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_users (
                id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                platform_role ENUM('admin','studio','model','editor') NOT NULL DEFAULT 'model',
                active TINYINT(1) NOT NULL DEFAULT 1,
                email_verified_at DATETIME(6) DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY gf_users_email_unique(email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_organizations (
                id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(50) NOT NULL,
                type ENUM('studio','independent') NOT NULL DEFAULT 'independent',
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY gf_organizations_slug_unique(slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_memberships (
                id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                organization_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                role ENUM('admin','studio','model','editor') NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY gf_memberships_identity_unique(organization_id, user_id),
                KEY gf_memberships_user_idx(user_id),
                CONSTRAINT gf_memberships_user_fk FOREIGN KEY(user_id)
                    REFERENCES gf_users(id) ON DELETE CASCADE,
                CONSTRAINT gf_memberships_org_fk FOREIGN KEY(organization_id)
                    REFERENCES gf_organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_memberships_identity_immutable
            BEFORE UPDATE ON gf_memberships FOR EACH ROW
            BEGIN
                IF NOT (NEW.organization_id <=> OLD.organization_id)
                    OR NOT (NEW.user_id <=> OLD.user_id) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'membership identity is immutable';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_memberships_identity_immutable');
        $this->addSql('DROP TABLE IF EXISTS gf_memberships');
        $this->addSql('DROP TABLE IF EXISTS gf_organizations');
        $this->addSql('DROP TABLE IF EXISTS gf_users');
    }
}
