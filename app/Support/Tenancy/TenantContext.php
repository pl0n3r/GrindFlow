<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    public function runAsUser(User|string $user, Closure $callback): mixed
    {
        $userId = $user instanceof User ? (string) $user->getKey() : $user;

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previousUserId = (string) (DB::scalar(
            "select current_setting('app.current_user_id', true)",
        ) ?? '');

        try {
            DB::select("select set_config('app.current_user_id', ?, false)", [$userId]);

            return $callback();
        } finally {
            DB::select(
                "select set_config('app.current_user_id', ?, false)",
                [$previousUserId],
            );
        }
    }

    public function runWithinOrganization(
        User|string $user,
        string $organizationId,
        Closure $callback,
    ): mixed {
        $userId = $user instanceof User ? (string) $user->getKey() : $user;

        return $this->runAsUser($userId, function () use ($userId, $organizationId, $callback): mixed {
            $isPlatformAdmin = User::query()
                ->whereKey($userId)
                ->where('platform_role', 'admin')
                ->exists();

            $isMember = Membership::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->exists();

            if (! $isPlatformAdmin && ! $isMember) {
                throw new AuthorizationException('The user is not authorized for this organization.');
            }

            return $callback();
        });
    }
}
