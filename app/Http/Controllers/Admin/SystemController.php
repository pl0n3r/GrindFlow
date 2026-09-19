<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\DirectMediaUpload;
use App\Support\Operations\MigrationReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        try {
            DB::connection()->select('select 1');
            $databaseOnline = true;
            $snapshot = $readiness->snapshot();
            $pendingMigrationNames = $snapshot['names'];
            $pendingMigrations = count($pendingMigrationNames);
            $migrationFingerprint = $snapshot['fingerprint'];
            $workspaceOrganization = Organization::query()->orderBy('name')->first();
        } catch (Throwable) {
            $databaseOnline = false;
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
            'mediaStorageConfigured' => $mediaStorage['configured'],
            'mediaStorageDisk' => $mediaStorage['disk'],
            'mediaStorageDriver' => $mediaStorage['driver'],
            'mediaStorageMaxBytes' => $directUploads->maxBytes(),
        ]);
    }
}
