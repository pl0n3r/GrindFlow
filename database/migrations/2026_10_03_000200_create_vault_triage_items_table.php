<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_profiles', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'operational_profiles_id_org_unique');
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->uuid('profile_id')->nullable()->after('ingested_by_user_id');

            $table->foreign(
                ['profile_id', 'organization_id'],
                'media_assets_profile_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('operational_profiles')
                ->restrictOnDelete();
        });

        Schema::create('vault_triage_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('media_asset_id');
            $table->uuid('assigned_profile_id')->nullable();
            $table->foreignUuid('assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'media_asset_id'], 'vault_triage_org_asset_unique');
            $table->index(['organization_id', 'status'], 'vault_triage_org_status_index');

            $table->foreign(
                ['media_asset_id', 'organization_id'],
                'vault_triage_asset_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('media_assets')
                ->restrictOnDelete();

            $table->foreign(
                ['assigned_profile_id', 'organization_id'],
                'vault_triage_assigned_profile_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('operational_profiles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_triage_items');

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropForeign('media_assets_profile_org_foreign');
            $table->dropColumn('profile_id');
        });

        Schema::table('operational_profiles', function (Blueprint $table): void {
            $table->dropUnique('operational_profiles_id_org_unique');
        });
    }
};
