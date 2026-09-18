<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_blobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('storage_disk', 64);
            $table->string('storage_key', 1024);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('mime_type', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'sha256'],
                'media_blobs_org_sha_unique',
            );
            $table->unique(
                ['id', 'organization_id'],
                'media_blobs_id_org_unique',
            );
        });

        Schema::create('media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('media_blob_id');
            $table->uuid('duplicate_of')->nullable();
            $table->foreignUuid('ingested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('original_filename', 512);
            $table->string('source_type', 64)->default('manual_upload');
            $table->string('source_ref', 1024)->nullable();
            $table->string('status', 32)->default('ready');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'status'],
                'media_assets_org_status_index',
            );
            $table->index(
                ['organization_id', 'created_at'],
                'media_assets_org_created_index',
            );
            $table->unique(
                ['id', 'organization_id'],
                'media_assets_id_org_unique',
            );

            $table->foreign(
                ['media_blob_id', 'organization_id'],
                'media_assets_blob_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('media_blobs')
                ->restrictOnDelete();

            $table->foreign(
                ['duplicate_of', 'organization_id'],
                'media_assets_duplicate_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('media_assets')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('media_blobs');
    }
};
