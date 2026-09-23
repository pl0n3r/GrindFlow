<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
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
        $email = Str::lower(trim((string) config('grindflow.smoke_user.email')));
        $password = (string) config('grindflow.smoke_user.password');
        $name = trim((string) config('grindflow.smoke_user.name'));
        $phase = (string) config('grindflow.phase');

        if ($phase !== 'construccion') {
            $this->error('Smoke identity provisioning is disabled outside construction phase.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('SMOKE_USER_PASSWORD is required; no production data was changed.');

            return self::FAILURE;
        }

        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[^@\\s]+@grindflow[.]test$/i', $email) !== 1
        ) {
            $this->error('SMOKE_USER_EMAIL must use the reserved grindflow.test synthetic domain.');

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('SMOKE_USER_NAME must not be empty.');

            return self::FAILURE;
        }

        try {
            $changed = $this->withFilesystemLock(
                fn (): bool => $this->reconcile($email, $password, $name),
            );
        } catch (Throwable) {
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
