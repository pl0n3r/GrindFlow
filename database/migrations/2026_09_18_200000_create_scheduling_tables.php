<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('provider', 64);
            $table->enum('status', ['active', 'disabled'])
                ->default('active');
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'organization_id'],
                'publishing_destinations_id_org_unique',
            );
            $table->index(
                ['organization_id', 'status'],
                'publishing_destinations_org_status_index',
            );
            $table->index(
                ['organization_id', 'provider'],
                'publishing_destinations_org_provider_index',
            );
        });

        Schema::create('scheduled_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('media_asset_id');
            $table->uuid('publishing_destination_id');
            $table->foreignUuid('scheduled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->enum('status', ['scheduled', 'cancelled'])
                ->default('scheduled');
            $table->dateTime('scheduled_for_utc');
            $table->string('timezone', 64);
            $table->timestampsTz();

            $table->unique(
                ['id', 'organization_id'],
                'scheduled_publications_id_org_unique',
            );
            $table->index(
                ['organization_id', 'status', 'scheduled_for_utc'],
                'scheduled_publications_org_status_due_index',
            );

            $table->foreign(
                ['media_asset_id', 'organization_id'],
                'scheduled_publications_asset_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('media_assets')
                ->restrictOnDelete();

            $table->foreign(
                ['publishing_destination_id', 'organization_id'],
                'scheduled_publications_destination_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('publishing_destinations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_publications');
        Schema::dropIfExists('publishing_destinations');
    }
};
