<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateE2eAdmin extends Command
{
    protected $signature = 'grindflow:e2e-admin
        {--allow-production : Explicitly allow creating/resetting the E2E admin in production}';

    protected $description = 'Create or reset the temporary GrindFlow E2E admin account.';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('allow-production')) {
            $this->error('Refusing to create an E2E admin in production without --allow-production.');

            return self::FAILURE;
        }

        $email = 'e2e-admin@grindflow.test';
        $password = Str::random(24);

        DB::transaction(function () use ($email, $password): void {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->name = 'E2E Admin';
            $user->password = $password;
            $user->email_verified_at = now();
            $user->platform_role = UserRole::Admin;
            $user->save();

            $organization = Organization::query()->firstOrNew([
                'slug' => 'e2e-admin-workspace',
            ]);
            $organization->name = 'E2E Admin Workspace';
            $organization->type = OrganizationType::Studio;
            $organization->save();

            Membership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                ['role' => UserRole::Admin],
            );
        });

        $this->newLine();
        $this->info('E2E admin ready.');
        $this->line("Email: {$email}");
        $this->line("Password: {$password}");
        $this->warn('Copy the password now. Running this command again rotates it.');

        return self::SUCCESS;
    }
}
