<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_publication_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('scheduled_publication_id');
            $table->uuid('tracked_link_id');
            $table->timestampsTz();

            $table->unique(
                ['scheduled_publication_id', 'organization_id'],
                'scheduled_publication_links_schedule_org_unique',
            );
            $table->index(
                ['organization_id', 'tracked_link_id'],
                'scheduled_publication_links_org_link_index',
            );

            $table->foreign(
                ['scheduled_publication_id', 'organization_id'],
                'scheduled_publication_links_schedule_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('scheduled_publications')
                ->cascadeOnDelete();

            $table->foreign(
                ['tracked_link_id', 'organization_id'],
                'scheduled_publication_links_link_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('tracked_links')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_publication_links');
    }
};
