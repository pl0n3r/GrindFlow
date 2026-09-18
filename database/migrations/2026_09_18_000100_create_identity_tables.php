<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('platform_role', ['admin', 'studio', 'model', 'editor'])
                ->default('model');
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 50)->unique();
            $table->enum('type', ['studio', 'independent'])
                ->default('independent');
            $table->timestampsTz();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->enum('role', ['admin', 'studio', 'model', 'editor']);
            $table->timestampsTz();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('users');
    }
};
