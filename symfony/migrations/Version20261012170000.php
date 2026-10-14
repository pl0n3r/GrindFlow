<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** S4: opt-in local draft generation; no provider calls, no cron activation. */
final class Version20261012170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend the canonical tenant weekly rule with paused-by-default automation preferences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_content_rules
                ADD COLUMN automation_state VARCHAR(12) NOT NULL DEFAULT 'paused',
                ADD COLUMN automation_local_times TEXT NULL,
                ADD COLUMN automation_destination_id VARCHAR(32) NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only run destructive down against disposable testing DB, not live.
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_content_rules
                DROP COLUMN automation_destination_id,
                DROP COLUMN automation_local_times,
                DROP COLUMN automation_state
            SQL);
    }
}
