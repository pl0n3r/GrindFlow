<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S1 identity tables live ONLY in the isolated Symfony database.
 * gf_ prefix prevents accidental collision with the Laravel writer.
 */
final class Version20260920093100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create isolated GrindFlow identity, organizations and immutable membership identifiers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_identity_users (
                id CHAR(36) NOT NULL,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                platform_role VARCHAR(16) NOT NULL DEFAULT 'model',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uq_gf_identity_users_email (email),
                CONSTRAINT ck_gf_identity_users_role
                    CHECK (platform_role IN ('admin', 'studio', 'model', 'editor'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_identity_organizations (
                id CHAR(36) NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(50) NOT NULL,
                type VARCHAR(16) NOT NULL DEFAULT 'independent',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uq_gf_identity_organizations_slug (slug),
                CONSTRAINT ck_gf_identity_organizations_type
                    CHECK (type IN ('studio', 'independent'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE gf_identity_memberships (
                id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL,
                organization_id CHAR(36) NOT NULL,
                role VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE KEY uq_gf_identity_memberships_actor (organization_id, user_id),
                KEY ix_gf_identity_memberships_user (user_id),
                CONSTRAINT fk_gf_identity_memberships_user
                    FOREIGN KEY (user_id) REFERENCES gf_identity_users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_gf_identity_memberships_org
                    FOREIGN KEY (organization_id) REFERENCES gf_identity_organizations(id)
                    ON DELETE CASCADE,
                CONSTRAINT ck_gf_identity_memberships_role
                    CHECK (role IN ('admin', 'studio', 'model', 'editor'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gf_identity_memberships');
        $this->addSql('DROP TABLE gf_identity_organizations');
        $this->addSql('DROP TABLE gf_identity_users');
    }
}
