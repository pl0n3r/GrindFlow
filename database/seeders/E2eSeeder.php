<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class E2eSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('E2E data can only be seeded in local or testing environments.');
        }

        $password = (string) env('E2E_USER_PASSWORD', '');

        if ($password === '') {
            throw new RuntimeException('E2E_USER_PASSWORD is required.');
        }

        $email = (string) env('E2E_USER_EMAIL', 'e2e-browser@grindflow.test');
        $name = (string) env('E2E_USER_NAME', 'E2E Browser User');
        $organizationName = (string) env('E2E_ORG_NAME', 'E2E Browser Workspace');
        $organizationSlug = (string) env('E2E_ORG_SLUG', 'e2e-browser-workspace');

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = $password;
        $user->email_verified_at = now();
        $user->platform_role = UserRole::Model;
        $user->save();

        $organization = Organization::query()->firstOrNew(['slug' => $organizationSlug]);
        $organization->name = $organizationName;
        $organization->type = OrganizationType::Studio;
        $organization->save();

        Membership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            ['role' => UserRole::Studio],
        );
    }
}
