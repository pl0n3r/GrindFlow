<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the tenant-scoped operational profile table. */
    public function up(): void
    {
        Schema::create('operational_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->timestampsTz();

            $table->unique(['organization_id', 'slug']);
            $table->index('organization_id');
        });
    }

    /** Remove the operational profile table on rollback. */
    public function down(): void
    {
        Schema::dropIfExists('operational_profiles');
    }
};
