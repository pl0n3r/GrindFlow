<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** S2 private organization notes stay with the original's protected catalog. */
final class Version20260921061500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional private note to isolated Symfony Vault assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gf_vault_assets ADD private_note VARCHAR(280) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // In the isolated schema only. Do not roll back on productive data
        // without an independently verified backup of the notes.
        $this->addSql('ALTER TABLE gf_vault_assets DROP COLUMN private_note');
    }
}
