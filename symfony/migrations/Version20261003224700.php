<?php

declare(strict_types=1);

namespace GrindFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Add mutable delivery intent that becomes immutable before external I/O. */
final class Version20261003224700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add caption and locked Facebook delivery intent to schedule drafts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_schedule_drafts_lifecycle_update');

        $this->addSql(<<<'SQL'
            ALTER TABLE gf_schedule_drafts
                ADD caption TEXT DEFAULT NULL AFTER asset_id,
                ADD delivery_provider VARCHAR(32) DEFAULT NULL AFTER caption,
                ADD delivery_destination_id VARCHAR(64) DEFAULT NULL AFTER delivery_provider,
                ADD delivery_locked_at DATETIME DEFAULT NULL AFTER delivery_destination_id,
                ADD CONSTRAINT ck_gf_drafts_delivery_pair CHECK (
                    (delivery_provider IS NULL AND delivery_destination_id IS NULL)
                    OR (
                        delivery_provider IS NOT NULL
                        AND delivery_destination_id IS NOT NULL
                        AND delivery_provider = 'facebook_page'
                        AND delivery_destination_id REGEXP '^[0-9]{1,32}
                ),
                ADD CONSTRAINT ck_gf_drafts_delivery_lock CHECK (
                    delivery_locked_at IS NULL
                    OR (
                        status = 'draft'
                        AND delivery_provider = 'facebook_page'
                        AND delivery_destination_id REGEXP '^[0-9]{1,32}$'
                        AND caption IS NOT NULL
                        AND CHAR_LENGTH(TRIM(caption)) BETWEEN 1 AND 5000
                    )
                )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_schedule_drafts_lifecycle_update
            BEFORE UPDATE ON gf_schedule_drafts
            FOR EACH ROW
            BEGIN
                DECLARE immutable_fields_match BOOLEAN DEFAULT FALSE;

                SET immutable_fields_match = (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.asset_id <=> NEW.asset_id
                    AND OLD.created_by <=> NEW.created_by
                    AND OLD.scheduled_at_utc <=> NEW.scheduled_at_utc
                    AND OLD.timezone <=> NEW.timezone
                    AND OLD.local_date <=> NEW.local_date
                    AND OLD.local_time <=> NEW.local_time
                    AND OLD.created_at <=> NEW.created_at
                );

                IF NOT immutable_fields_match THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule draft immutable fields cannot change';
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'draft' THEN
                    IF OLD.cancelled_at IS NOT NULL OR OLD.cancelled_by IS NOT NULL
                        OR NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'active schedule draft cannot contain cancellation data';
                    END IF;

                    IF NOT (
                        (NEW.delivery_provider IS NULL AND NEW.delivery_destination_id IS NULL)
                        OR (
                            NEW.delivery_provider IS NOT NULL
                            AND NEW.delivery_destination_id IS NOT NULL
                            AND NEW.delivery_provider = 'facebook_page'
                            AND NEW.delivery_destination_id REGEXP '^[0-9]{1,32}$'
                        )
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'schedule draft delivery provider and destination must be a complete pair';
                    END IF;

                    IF OLD.delivery_locked_at IS NOT NULL THEN
                        IF NOT (
                            OLD.caption <=> NEW.caption
                            AND OLD.delivery_provider <=> NEW.delivery_provider
                            AND OLD.delivery_destination_id <=> NEW.delivery_destination_id
                            AND OLD.delivery_locked_at <=> NEW.delivery_locked_at
                        ) THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'locked schedule draft delivery intent cannot change';
                        END IF;
                    ELSEIF NEW.delivery_locked_at IS NOT NULL THEN
                        IF NEW.caption IS NULL OR CHAR_LENGTH(TRIM(NEW.caption)) NOT BETWEEN 1 AND 5000
                            OR NEW.delivery_provider <> 'facebook_page'
                            OR NEW.delivery_destination_id IS NULL
                            OR NEW.delivery_destination_id NOT REGEXP '^[0-9]{1,32}$' THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'schedule draft cannot lock incomplete delivery intent';
                        END IF;
                    END IF;
                ELSEIF OLD.status = 'draft' AND NEW.status = 'cancelled' THEN
                    IF OLD.delivery_locked_at IS NOT NULL
                        OR NEW.delivery_locked_at IS NOT NULL
                        OR NOT (OLD.caption <=> NEW.caption)
                        OR NOT (OLD.delivery_provider <=> NEW.delivery_provider)
                        OR NOT (OLD.delivery_destination_id <=> NEW.delivery_destination_id)
                        OR OLD.cancelled_at IS NOT NULL
                        OR OLD.cancelled_by IS NOT NULL
                        OR NEW.cancelled_at IS NULL
                        OR NEW.cancelled_by IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'locked or mutated schedule draft cannot be cancelled';
                    END IF;
                ELSE
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule draft status cannot transition this way';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_schedule_drafts_lifecycle_update');
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_schedule_drafts
                DROP CONSTRAINT ck_gf_drafts_delivery_lock,
                DROP CONSTRAINT ck_gf_drafts_delivery_pair,
                DROP COLUMN delivery_locked_at,
                DROP COLUMN delivery_destination_id,
                DROP COLUMN delivery_provider,
                DROP COLUMN caption
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_schedule_drafts_lifecycle_update
            BEFORE UPDATE ON gf_schedule_drafts
            FOR EACH ROW
            BEGIN
                IF NOT (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.asset_id <=> NEW.asset_id
                    AND OLD.created_by <=> NEW.created_by
                    AND OLD.scheduled_at_utc <=> NEW.scheduled_at_utc
                    AND OLD.timezone <=> NEW.timezone
                    AND OLD.local_date <=> NEW.local_date
                    AND OLD.local_time <=> NEW.local_time
                    AND OLD.created_at <=> NEW.created_at
                    AND OLD.status = 'draft'
                    AND NEW.status = 'cancelled'
                    AND OLD.cancelled_at IS NULL
                    AND OLD.cancelled_by IS NULL
                    AND NEW.cancelled_at IS NOT NULL
                    AND NEW.cancelled_by IS NOT NULL
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule drafts only allow draft-to-cancelled transition';
                END IF;
            END
            SQL);
    }
}

                    )
                ),
                ADD CONSTRAINT ck_gf_drafts_delivery_lock CHECK (
                    delivery_locked_at IS NULL
                    OR (
                        status = 'draft'
                        AND delivery_provider = 'facebook_page'
                        AND delivery_destination_id REGEXP '^[0-9]{1,32}$'
                        AND caption IS NOT NULL
                        AND CHAR_LENGTH(TRIM(caption)) BETWEEN 1 AND 5000
                    )
                )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_schedule_drafts_lifecycle_update
            BEFORE UPDATE ON gf_schedule_drafts
            FOR EACH ROW
            BEGIN
                DECLARE immutable_fields_match BOOLEAN DEFAULT FALSE;

                SET immutable_fields_match = (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.asset_id <=> NEW.asset_id
                    AND OLD.created_by <=> NEW.created_by
                    AND OLD.scheduled_at_utc <=> NEW.scheduled_at_utc
                    AND OLD.timezone <=> NEW.timezone
                    AND OLD.local_date <=> NEW.local_date
                    AND OLD.local_time <=> NEW.local_time
                    AND OLD.created_at <=> NEW.created_at
                );

                IF NOT immutable_fields_match THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule draft immutable fields cannot change';
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'draft' THEN
                    IF OLD.cancelled_at IS NOT NULL OR OLD.cancelled_by IS NOT NULL
                        OR NEW.cancelled_at IS NOT NULL OR NEW.cancelled_by IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'active schedule draft cannot contain cancellation data';
                    END IF;

                    IF OLD.delivery_locked_at IS NOT NULL THEN
                        IF NOT (
                            OLD.caption <=> NEW.caption
                            AND OLD.delivery_provider <=> NEW.delivery_provider
                            AND OLD.delivery_destination_id <=> NEW.delivery_destination_id
                            AND OLD.delivery_locked_at <=> NEW.delivery_locked_at
                        ) THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'locked schedule draft delivery intent cannot change';
                        END IF;
                    ELSEIF NEW.delivery_locked_at IS NOT NULL THEN
                        IF NEW.caption IS NULL OR CHAR_LENGTH(TRIM(NEW.caption)) NOT BETWEEN 1 AND 5000
                            OR NEW.delivery_provider <> 'facebook_page'
                            OR NEW.delivery_destination_id IS NULL
                            OR NEW.delivery_destination_id NOT REGEXP '^[0-9]{1,32}$' THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'schedule draft cannot lock incomplete delivery intent';
                        END IF;
                    END IF;
                ELSEIF OLD.status = 'draft' AND NEW.status = 'cancelled' THEN
                    IF OLD.delivery_locked_at IS NOT NULL
                        OR NEW.delivery_locked_at IS NOT NULL
                        OR NOT (OLD.caption <=> NEW.caption)
                        OR NOT (OLD.delivery_provider <=> NEW.delivery_provider)
                        OR NOT (OLD.delivery_destination_id <=> NEW.delivery_destination_id)
                        OR OLD.cancelled_at IS NOT NULL
                        OR OLD.cancelled_by IS NOT NULL
                        OR NEW.cancelled_at IS NULL
                        OR NEW.cancelled_by IS NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'locked or mutated schedule draft cannot be cancelled';
                    END IF;
                ELSE
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule draft status cannot transition this way';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS gf_schedule_drafts_lifecycle_update');
        $this->addSql(<<<'SQL'
            ALTER TABLE gf_schedule_drafts
                DROP CONSTRAINT ck_gf_drafts_delivery_lock,
                DROP CONSTRAINT ck_gf_drafts_delivery_pair,
                DROP COLUMN delivery_locked_at,
                DROP COLUMN delivery_destination_id,
                DROP COLUMN delivery_provider,
                DROP COLUMN caption
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER gf_schedule_drafts_lifecycle_update
            BEFORE UPDATE ON gf_schedule_drafts
            FOR EACH ROW
            BEGIN
                IF NOT (
                    OLD.id <=> NEW.id
                    AND OLD.organization_id <=> NEW.organization_id
                    AND OLD.asset_id <=> NEW.asset_id
                    AND OLD.created_by <=> NEW.created_by
                    AND OLD.scheduled_at_utc <=> NEW.scheduled_at_utc
                    AND OLD.timezone <=> NEW.timezone
                    AND OLD.local_date <=> NEW.local_date
                    AND OLD.local_time <=> NEW.local_time
                    AND OLD.created_at <=> NEW.created_at
                    AND OLD.status = 'draft'
                    AND NEW.status = 'cancelled'
                    AND OLD.cancelled_at IS NULL
                    AND OLD.cancelled_by IS NULL
                    AND NEW.cancelled_at IS NOT NULL
                    AND NEW.cancelled_by IS NOT NULL
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'schedule drafts only allow draft-to-cancelled transition';
                END IF;
            END
            SQL);
    }
}
