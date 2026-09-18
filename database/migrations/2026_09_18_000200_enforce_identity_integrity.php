<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS memberships_identity_immutable');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER memberships_identity_immutable
            BEFORE UPDATE ON memberships
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

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS memberships_identity_immutable');
    }
};
