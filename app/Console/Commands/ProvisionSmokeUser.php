<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProvisionSmokeUser extends Command
{
    protected $signature = 'grindflow:provision-smoke-user';

    protected $description = 'Reconcile the synthetic production smoke identity safely and idempotently.';

    public function handle(): int
    {
        // This is request-local diagnostic state, never persisted in .env or DB.
        config(['grindflow.smoke_provision_failure_code' => null]);

        $email = Str::lower(trim((string) config('grindflow.smoke_user.email')));
        $password = (string) config('grindflow.smoke_user.password');
        $name = trim((string) config('grindflow.smoke_user.name'));
        $phase = (string) config('grindflow.phase');

        if ($phase !== 'construccion') {
            $this->error('Smoke identity provisioning is disabled outside construction phase.');
            config(['grindflow.smoke_provision_failure_code' => 'provision-phase-disabled']);

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('SMOKE_USER_PASSWORD is required; no production data was changed.');
            config(['grindflow.smoke_provision_failure_code' => 'provision-password-missing']);

            return self::FAILURE;
        }

        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[^@\\s]+@grindflow[.]test$/i', $email) !== 1
        ) {
            $this->error('SMOKE_USER_EMAIL must use the reserved grindflow.test synthetic domain.');
            config(['grindflow.smoke_provision_failure_code' => 'provision-email-invalid']);

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('SMOKE_USER_NAME must not be empty.');
            config(['grindflow.smoke_provision_failure_code' => 'provision-name-invalid']);

            return self::FAILURE;
        }

        try {
            $changed = $this->withFilesystemLock(
                fn (): bool => $this->reconcile($email, $password, $name),
            );
        } catch (Throwable $exception) {
            // Query exceptions take precedence over any framework-supplied message.
            if ($exception instanceof QueryException) {
                $code = 'provision-database-failed';
            } else {
                $code = match ($exception->getMessage()) {
                    'Unable to create deployment lock directory.' => 'provision-lock-directory-failed',
                    'Unable to open smoke-user provisioning lock.' => 'provision-lock-open-failed',
                    'Timed out waiting for smoke-user provisioning lock.' => 'provision-lock-timeout',
                    'Synthetic smoke identity is linked to an organization.' => 'provision-membership-conflict',
                    'Unable to write private smoke-user rollback backup.' => 'provision-backup-write-failed',
                    'Unable to secure private smoke-user rollback backup.' => 'provision-backup-permission-failed',
                    default => 'provision-failed',
                };
            }
            config(['grindflow.smoke_provision_failure_code' => $code]);
            $this->error('Synthetic smoke identity reconciliation failed safely.');

            return self::FAILURE;
        }

        $this->info(
            $changed
                ? 'Synthetic smoke identity reconciled; private rollback backup recorded.'
                : 'Synthetic smoke identity already reconciled.',
        );

        return self::SUCCESS;
    }

    /**
     * @param  callable(): bool  $callback
     */
    private function withFilesystemLock(callable $callback): bool
    {
        $directory = storage_path('framework');

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0775, true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException('Unable to create deployment lock directory.');
        }

        $handle = fopen($directory.'/grindflow-smoke-user.lock', 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open smoke-user provisioning lock.');
        }

        $deadline = microtime(true) + 5.0;

        try {
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for smoke-user provisioning lock.');
                }

                usleep(100_000);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function reconcile(string $email, string $password, string $name): bool
    {
        return DB::transaction(function () use ($email, $password, $name): bool {
            /** @var User|null $user */
            $user = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            // Never turn an organization-associated account into a platform admin
            // controlled by the smoke secret. The user row is transaction-locked.
            if ($user !== null && $user->memberships()->exists()) {
                throw new RuntimeException('Synthetic smoke identity is linked to an organization.');
            }

            $passwordMatches = $user !== null
                && Hash::check($password, (string) $user->getAuthPassword())
                && Hash::needsRehash((string) $user->getAuthPassword()) === false;

            $alreadyReconciled = $user !== null
                && $user->name === $name
                && $user->email_verified_at !== null
                && $user->platform_role === UserRole::Admin
                && $passwordMatches;

            if ($alreadyReconciled) {
                return false;
            }

            $this->backupBeforeMutation($user);

            $user ??= new User;
            $user->name = $name;
            $user->email = $email;
            $user->email_verified_at ??= now();
            $user->platform_role = UserRole::Admin;

            if (! $passwordMatches) {
                $user->password = $password;
            }

            $user->save();

            return true;
        }, 3);
    }

    private function backupBeforeMutation(?User $user): void
    {
        $snapshot = [
            'version' => 1,
            'captured_at' => now('UTC')->toIso8601String(),
            'exists' => $user !== null,
            'user' => $user === null ? null : [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->getRawOriginal('email_verified_at'),
                'password' => (string) $user->getAuthPassword(),
                'platform_role' => $user->platform_role->value,
                'remember_token' => $user->getRememberToken(),
                'created_at' => $user->getRawOriginal('created_at'),
                'updated_at' => $user->getRawOriginal('updated_at'),
            ],
        ];

        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $encrypted = Crypt::encryptString($encoded);
        $path = 'operations/smoke-user-backups/'
            .now('UTC')->format('Ymd\\THis\\Z')
            .'-'.Str::uuid().'.json.enc';

        $disk = Storage::disk('local');

        if ($disk->put($path, $encrypted) !== true) {
            throw new RuntimeException('Unable to write private smoke-user rollback backup.');
        }

        $absolutePath = $disk->path($path);

        if (@chmod($absolutePath, 0600) === false) {
            $disk->delete($path);

            throw new RuntimeException('Unable to secure private smoke-user rollback backup.');
        }
    }
}
