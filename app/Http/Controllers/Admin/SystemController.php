<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\DirectMediaUpload;
use App\Support\Operations\MigrationReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class SystemController extends Controller
{
    public function __invoke(
        Request $request,
        MigrationReadiness $readiness,
        DirectMediaUpload $directUploads,
    ): View {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isPlatformAdmin(),
            403,
        );

        $databaseOnline = false;
        $pendingMigrations = null;
        $pendingMigrationNames = [];
        $migrationFingerprint = null;
        $workspaceOrganization = null;
        $moduleReadiness = [];

        try {
            DB::connection()->select('select 1');
            $databaseOnline = true;
        } catch (Throwable) {
            // A failed connection is distinct from a failed schema inventory.
        }

        if ($databaseOnline) {
            try {
                $snapshot = $readiness->snapshot();
                $pendingMigrationNames = $snapshot['names'];
                $pendingMigrations = count($pendingMigrationNames);
                $migrationFingerprint = $snapshot['fingerprint'];
            } catch (Throwable) {
                // Unknown migration state must never enable the migration form.
            }

            try {
                $workspaceOrganization = Organization::query()->orderBy('name')->first();
            } catch (Throwable) {
                // Workspace navigation is optional on the operational status page.
            }

            try {
                $modules = [
                    'Vault' => ['media_assets', 'media_blobs'],
                    'Scheduling' => ['publishing_destinations', 'scheduled_publications'],
                    'Distribution' => ['publishing_destinations', 'scheduled_publications', 'publication_deliveries'],
                    'Traffic' => ['tracked_links', 'tracked_link_daily_metrics', 'tracked_link_dedupes'],
                    'Finance' => ['revenue_allocations'],
                ];

                foreach ($modules as $label => $tables) {
                    $moduleReadiness[$label] = collect($tables)->every(
                        fn (string $table): bool => Schema::hasTable($table),
                    );
                }
            } catch (Throwable) {
                // A schema probe failure is unknown, never evidence that the database is offline.
                $moduleReadiness = [];
            }
        }

        $mediaStorage = $directUploads->status();

        return view('admin.system', [
            'databaseOnline' => $databaseOnline,
            'workspaceOrganization' => $workspaceOrganization,
            'databaseDriver' => DB::connection()->getDriverName(),
            'environment' => app()->environment(),
            'laravelVersion' => app()->version(),
            'queueConnection' => (string) config('queue.default'),
            'sessionDriver' => (string) config('session.driver'),
            'pendingMigrations' => $pendingMigrations,
            'pendingMigrationNames' => $pendingMigrationNames,
            'migrationFingerprint' => $migrationFingerprint,
            'moduleReadiness' => $moduleReadiness,
            'mediaStorageConfigured' => $mediaStorage['configured'],
            'mediaStorageDisk' => $mediaStorage['disk'],
            'mediaStorageDriver' => $mediaStorage['driver'],
            'mediaStorageMaxBytes' => $directUploads->maxBytes(),
        ]);
    }
}
