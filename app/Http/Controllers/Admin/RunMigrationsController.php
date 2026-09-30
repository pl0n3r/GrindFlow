<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Operations\MigrationReadiness;
use App\Support\Operations\ProductionWritePolicy;
use App\Support\Operations\VerifiedBackupEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class RunMigrationsController extends Controller
{
    public function __invoke(
        Request $request,
        MigrationReadiness $readiness,
        ProductionWritePolicy $writePolicy,
        VerifiedBackupEvidence $backupEvidence,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isPlatformAdmin(),
            403,
        );

        $validated = $request->validate([
            'backup_receipt' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'],
            'confirmation' => ['required', 'in:MIGRAR'],
            'migration_batch' => [
                'required',
                'regex:/\\A[a-f0-9]{64}\\z/',
            ],
        ]);

        $lockPath = storage_path('framework/grindflow-migrate.lock');
        $directory = dirname($lockPath);

        if (is_dir($directory) === false) {
            mkdir($directory, 0775, true);
        }

        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException('Unable to create the migration lock.');
        }

        if (flock($lock, LOCK_EX | LOCK_NB) === false) {
            fclose($lock);

            return to_route('admin.system')
                ->withErrors([
                    'migration' => 'Otra migracion ya esta en ejecucion.',
                ]);
        }

        try {
            $snapshot = $readiness->snapshot();

            if ($snapshot['names'] === []) {
                return to_route('admin.system')
                    ->withErrors([
                        'migration' => 'No hay migraciones pendientes.',
                    ]);
            }

            if (hash_equals(
                $snapshot['fingerprint'],
                (string) $validated['migration_batch'],
            ) === false) {
                return to_route('admin.system')
                    ->withErrors([
                        'migration' => 'El lote de migraciones cambió. Recarga System y revísalo de nuevo.',
                    ]);
            }

            try {
                $backupEvidence->assertValid(
                    (string) $validated['backup_receipt'],
                    $snapshot['fingerprint'],
                );
                $writePolicy->assertAutonomousWriteAllowed(
                    operation: 'migration',
                    destructive: false,
                    bulk: true,
                    versioned: true,
                    backupVerified: true,
                    lockHeld: true,
                );
            } catch (RuntimeException $exception) {
                return to_route('admin.system')
                    ->withErrors(['migration' => $exception->getMessage()]);
            }

            $exitCode = Artisan::call('migrate', ['--force' => true]);

            if ($exitCode !== 0) {
                throw new RuntimeException('Database migration failed.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return to_route('admin.system')
            ->with('status', 'Migraciones de base de datos completadas.');
    }
}
