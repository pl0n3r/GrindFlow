<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('scheduled_publication_id');
            $table->string('idempotency_key', 191);
            $table->enum('status', [
                'queued',
                'processing',
                'retry_scheduled',
                'published',
                'authentication_failed',
                'failed',
            ])->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('claimed_until')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('external_publication_id', 191)->nullable();
            $table->string('last_error_code', 191)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'organization_id'],
                'publication_deliveries_id_org_unique',
            );
            $table->unique(
                ['scheduled_publication_id', 'organization_id'],
                'publication_deliveries_schedule_org_unique',
            );
            $table->unique(
                ['organization_id', 'idempotency_key'],
                'publication_deliveries_org_idempotency_unique',
            );
            $table->index(
                ['organization_id', 'status', 'next_attempt_at'],
                'publication_deliveries_org_retry_index',
            );

            $table->foreign(
                ['scheduled_publication_id', 'organization_id'],
                'publication_deliveries_schedule_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('scheduled_publications')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_deliveries');
    }
};
