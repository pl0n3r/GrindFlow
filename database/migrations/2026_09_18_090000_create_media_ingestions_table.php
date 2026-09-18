<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_ingestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUuid('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->uuid('media_asset_id')->nullable();

            $table->string('source_type', 64);
            $table->string('source_ref', 1024);
            $table->string('source_disk', 64);
            $table->string('source_key', 1024);
            $table->string('original_filename', 512);
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('idempotency_key', 64);

            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 191)->nullable();
            $table->boolean('delete_source_after_ingest')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'idempotency_key'],
                'media_ingestions_org_idempotency_unique',
            );
            $table->unique(
                ['id', 'organization_id'],
                'media_ingestions_id_org_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'media_ingestions_org_status_created_index',
            );

            $table->foreign(
                ['media_asset_id', 'organization_id'],
                'media_ingestions_asset_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('media_assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_ingestions');
    }
};
