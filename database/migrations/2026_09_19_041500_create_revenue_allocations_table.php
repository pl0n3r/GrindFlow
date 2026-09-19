<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('beneficiary_user_id')->nullable();
            $table->uuid('reversal_of_id')->nullable();
            $table->string('source_label', 191);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['id', 'organization_id'],
                'revenue_allocations_id_org_unique',
            );
            $table->unique(
                'reversal_of_id',
                'revenue_allocations_reversal_unique',
            );
            $table->index(
                ['organization_id', 'occurred_on', 'created_at'],
                'revenue_allocations_org_date_index',
            );

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('beneficiary_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign(
                ['reversal_of_id', 'organization_id'],
                'revenue_allocations_reversal_org_foreign',
            )
                ->references(['id', 'organization_id'])
                ->on('revenue_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
    }
};
