<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('created_by_user_id')->nullable();
            $table->string('token', 32)->unique();
            $table->string('label', 191);
            $table->text('destination_url');
            $table->string('channel', 64)->nullable();
            $table->string('campaign', 128)->nullable();
            $table->enum('status', ['active', 'disabled'])
                ->default('active');
            $table->timestampsTz();

            $table->unique(
                ['id', 'organization_id'],
                'tracked_links_id_org_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'tracked_links_org_status_created_index',
            );

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create(
            'tracked_link_daily_metrics',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->uuid('tracked_link_id');
                $table->date('metric_date');
                $table->unsignedBigInteger('clicks')->default(0);
                $table->timestampsTz();

                $table->unique(
                    [
                        'tracked_link_id',
                        'organization_id',
                        'metric_date',
                    ],
                    'tracked_link_daily_metric_unique',
                );
                $table->index(
                    ['organization_id', 'metric_date'],
                    'tracked_link_daily_org_date_index',
                );

                $table->foreign(
                    ['tracked_link_id', 'organization_id'],
                    'tracked_link_daily_link_org_foreign',
                )
                    ->references(['id', 'organization_id'])
                    ->on('tracked_links')
                    ->cascadeOnDelete();
            },
        );

        Schema::create(
            'tracked_link_dedupes',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')
                    ->constrained('organizations')
                    ->cascadeOnDelete();
                $table->uuid('tracked_link_id');
                $table->char('visitor_hash', 64);
                $table->dateTime('last_counted_at')->nullable();
                $table->timestampsTz();

                $table->unique(
                    [
                        'tracked_link_id',
                        'organization_id',
                        'visitor_hash',
                    ],
                    'tracked_link_dedupe_unique',
                );
                $table->index(
                    ['organization_id', 'last_counted_at'],
                    'tracked_link_dedupe_org_last_index',
                );

                $table->foreign(
                    ['tracked_link_id', 'organization_id'],
                    'tracked_link_dedupe_link_org_foreign',
                )
                    ->references(['id', 'organization_id'])
                    ->on('tracked_links')
                    ->cascadeOnDelete();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_link_dedupes');
        Schema::dropIfExists('tracked_link_daily_metrics');
        Schema::dropIfExists('tracked_links');
    }
};
