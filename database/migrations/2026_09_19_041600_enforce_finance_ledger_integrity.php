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

        DB::unprepared(
            'DROP TRIGGER IF EXISTS revenue_allocations_append_only_update',
        );
        DB::unprepared(
            'DROP TRIGGER IF EXISTS revenue_allocations_append_only_delete',
        );

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER revenue_allocations_append_only_update
            BEFORE UPDATE ON revenue_allocations
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'revenue allocation ledger is append-only';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER revenue_allocations_append_only_delete
            BEFORE DELETE ON revenue_allocations
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'revenue allocation ledger is append-only';
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(
            'DROP TRIGGER IF EXISTS revenue_allocations_append_only_update',
        );
        DB::unprepared(
            'DROP TRIGGER IF EXISTS revenue_allocations_append_only_delete',
        );
    }
};
