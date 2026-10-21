<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expand provider allowlist for the existing tenant-safe distribution ledger.
 * Does not migrate data, publish content, or request credentials.
 */
final class Version20261019193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow Instagram Reels in the existing external publication attempts ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_external_publication_attempts
                DROP CONSTRAINT ck_gf_external_attempt_provider,
                ADD CONSTRAINT ck_gf_external_attempt_provider
                    CHECK (provider IN ('facebook_page', 'instagram_reels'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Fail closed when Instagram records exist; never delete audit evidence.
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_external_publication_attempts
                DROP CONSTRAINT ck_gf_external_attempt_provider,
                ADD CONSTRAINT ck_gf_external_attempt_provider
                    CHECK (provider IN ('facebook_page'))
            SQL);
    }
}
