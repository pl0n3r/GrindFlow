<?php

namespace App\Support\Testing;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class E2eAdminManager
{
    public const EMAIL = 'e2e-admin@grindflow.test';

    public const ORGANIZATION_SLUG = 'e2e-admin-workspace';

    /**
     * @return array{email: string, password: string}
     */
    public function reset(): array
    {
        $password = Str::random(24);

        DB::transaction(function () use ($password): void {
            $user = User::query()->firstOrNew(['email' => self::EMAIL]);
            $user->name = 'E2E Admin';
            $user->password = $password;
            $user->email_verified_at = now();
            $user->platform_role = UserRole::Admin;
            $user->save();

            $organization = Organization::query()->firstOrNew([
                'slug' => self::ORGANIZATION_SLUG,
            ]);
            $organization->name = 'E2E Admin Workspace';
            $organization->type = OrganizationType::Studio->value;
            $organization->save();

            Membership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                ['role' => UserRole::Admin],
            );
        });

        return [
            'email' => self::EMAIL,
            'password' => $password,
        ];
    }

    public function exists(): bool
    {
        return User::query()
            ->where('email', self::EMAIL)
            ->exists();
    }
}
