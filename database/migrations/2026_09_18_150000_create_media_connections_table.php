<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignUuid('authorized_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('provider', 32);
            $table->string('label', 191);
            $table->string('account_identifier', 191)->nullable();
            $table->longText('access_ciphertext');
            $table->longText('refresh_ciphertext')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->text('cursor')->nullable();
            $table->string('root_path', 1024)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('scan_interval_minutes')->default(15);
            $table->timestamp('next_scan_at')->nullable();
            $table->timestamp('last_scan_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_error', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['id', 'organization_id'],
                'media_connections_id_org_unique',
            );
            $table->index(
                ['organization_id', 'status', 'next_scan_at'],
                'media_connections_org_status_next_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_connections');
    }
};
