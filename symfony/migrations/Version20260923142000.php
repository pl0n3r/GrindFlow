<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S2 multimedia catalog: widen only the isolated Symfony Vault MIME constraint.
 * No Laravel table or productive record is touched by this migration in CI.
 */
final class Version20260923142000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow private MP4/WebM originals in the isolated Symfony Vault catalog';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gf_vault_assets DROP CONSTRAINT ck_gf_vault_assets_mime');
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD CONSTRAINT ck_gf_vault_assets_mime
                CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $videoCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM gf_vault_assets WHERE mime_type IN ('video/mp4', 'video/webm')",
        );
        $this->abortIf(
            $videoCount > 0,
            'Cannot narrow the Vault MIME constraint while retained MP4/WebM assets exist.',
        );

        $this->addSql('ALTER TABLE gf_vault_assets DROP CONSTRAINT ck_gf_vault_assets_mime');
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_vault_assets
                ADD CONSTRAINT ck_gf_vault_assets_mime
                CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp'))
            SQL);
    }
}
