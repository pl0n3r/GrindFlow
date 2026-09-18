<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class RunMigrationsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isPlatformAdmin(),
            403,
        );

        $lockPath = storage_path('framework/grindflow-migrate.lock');
        $directory = dirname($lockPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException('Unable to create the migration lock.');
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return redirect()
                ->route('admin.system')
                ->withErrors([
                    'migration' => 'Otra migracion ya esta en ejecucion.',
                ]);
        }

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);

            if ($exitCode !== 0) {
                throw new RuntimeException('Database migration failed.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return redirect()
            ->route('admin.system')
            ->with('status', 'Migraciones de base de datos completadas.');
    }
}
