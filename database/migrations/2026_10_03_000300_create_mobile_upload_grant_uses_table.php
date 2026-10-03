<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_upload_grant_uses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('nonce', 128);
            $table->unsignedSmallInteger('file_count');
            $table->unsignedBigInteger('byte_count');
            $table->timestampTz('consumed_at');
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'nonce'],
                'mobile_upload_grant_uses_org_nonce_unique',
            );
            $table->unique(
                ['id', 'organization_id'],
                'mobile_upload_grant_uses_id_org_unique',
            );
            $table->index(
                ['organization_id', 'consumed_at'],
                'mobile_upload_grant_uses_org_consumed_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_upload_grant_uses');
    }
};
