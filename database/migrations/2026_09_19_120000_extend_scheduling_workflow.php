<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_publications', function (Blueprint $table): void {
            $table->string('request_key', 64)->nullable()->after('timezone');
            $table->unique(
                ['organization_id', 'request_key', 'publishing_destination_id'],
                'scheduled_publications_org_request_destination_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_publications', function (Blueprint $table): void {
            $table->dropUnique('scheduled_publications_org_request_destination_unique');
            $table->dropColumn('request_key');
        });
    }
};
