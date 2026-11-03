<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Build-ahead only: private and pseudonymous DSR audit in isolated Symfony DB. */
final class Version20261031164500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial identity-export and pending erasure request ledger without personal payloads';
    }

    public function up(Schema $schema): void
    {
        // Pair each request kind with its only permitted state. Keep SQL
        // free of inline comments for the schema-parity parser.
        $this->addSql(<<<'SQL'
            CREATE TABLE gf_data_subject_requests (
                id CHAR(36) NOT NULL,
                actor_ref CHAR(64) NOT NULL,
                kind VARCHAR(12) NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY ix_gf_dsr_actor_kind (actor_ref, kind, status, created_at),
                CONSTRAINT ck_gf_dsr_kind CHECK (kind IN ('export', 'deletion')),
                CONSTRAINT ck_gf_dsr_status CHECK (status IN ('partial', 'pending')),
                CONSTRAINT ck_gf_dsr_kind_status CHECK (
                    (kind = 'export' AND status = 'partial') OR
                    (kind = 'deletion' AND status = 'pending')
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        // CI may reverse the ledger only in its disposable synthetic database.
        // Production retention is never a generic migration rollback permit.
        if (getenv('APP_ENV') !== 'test'
            || $this->connection->getDatabase() !== 'grindflow_symfony_ci') {
            throw new \RuntimeException('DSR ledger cannot be dropped without retention authorization');
        }
        $this->addSql('DROP TABLE gf_data_subject_requests');
    }
}
