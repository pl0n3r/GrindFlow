<?php
declare(strict_types=1);
namespace GrindFlow\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
/** Only for the isolated Symfony database; never Laravel production. */
final class Version20260920095500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep user and organization identifiers of memberships immutable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_identity_membership_immutable
            BEFORE UPDATE ON gf_identity_memberships
            FOR EACH ROW
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
        $this->addSql('DROP TRIGGER IF EXISTS gf_identity_membership_immutable');
    }
}
