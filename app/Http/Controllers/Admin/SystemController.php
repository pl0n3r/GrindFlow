<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Media\DirectMediaUpload;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SystemController extends Controller
{
    public function __invoke(
        Request $request,
        Migrator $migrator,
        DirectMediaUpload $directUploads,
    ): View
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isPlatformAdmin(),
            403,
        );

        $databaseOnline = false;
        $pendingMigrations = null;

        try {
            DB::connection()->select('select 1');
            $databaseOnline = true;
            $pendingMigrations = $this->pendingMigrations($migrator);
        } catch (Throwable) {
            $databaseOnline = false;
        }

        $mediaStorage = $directUploads->status();

        return view('admin.system', [
            'databaseOnline' => $databaseOnline,
            'databaseDriver' => DB::connection()->getDriverName(),
            'environment' => app()->environment(),
            'laravelVersion' => app()->version(),
            'queueConnection' => (string) config('queue.default'),
            'sessionDriver' => (string) config('session.driver'),
            'pendingMigrations' => $pendingMigrations,
            'mediaStorageConfigured' => $mediaStorage['configured'],
            'mediaStorageDisk' => $mediaStorage['disk'],
            'mediaStorageDriver' => $mediaStorage['driver'],
            'mediaStorageMaxBytes' => $directUploads->maxBytes(),
        ]);
    }

    private function pendingMigrations(Migrator $migrator): int
    {
        $files = $migrator->getMigrationFiles(database_path('migrations'));

        if (! $migrator->repositoryExists()) {
            return count($files);
        }

        return count(array_diff(
            array_keys($files),
            $migrator->getRepository()->getRan(),
        ));
    }
}
