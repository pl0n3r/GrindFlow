<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_delivery_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->uuid('publication_delivery_id');
            $table->enum('event_type', [
                'attempt_started',
                'published',
                'retry_scheduled',
                'authentication_failed',
                'failed',
            ]);
            $table->unsignedInteger('provider_attempt');
            $table->unsignedInteger('event_number');
            $table->string('error_code', 191)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'publication_delivery_id', 'event_number'],
                'publication_delivery_events_order_unique',
            );
            $table->foreign(
                ['publication_delivery_id', 'organization_id'],
                'publication_delivery_events_delivery_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('publication_deliveries')
                ->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER publication_delivery_events_append_only_update
            BEFORE UPDATE ON publication_delivery_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'publication delivery events are append-only';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER publication_delivery_events_append_only_delete
            BEFORE DELETE ON publication_delivery_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'publication delivery events are append-only';
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS publication_delivery_events_append_only_update');
            DB::unprepared('DROP TRIGGER IF EXISTS publication_delivery_events_append_only_delete');
        }

        Schema::dropIfExists('publication_delivery_events');
    }
};
