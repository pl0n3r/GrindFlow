<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

class TenantContext
{
    private ?string $actorId = null;

    private ?string $organizationId = null;

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    public function runAsUser(User|string $user, Closure $callback): mixed
    {
        $previousActorId = $this->actorId;
        $this->actorId = $user instanceof User ? (string) $user->getKey() : $user;

        try {
            return $callback();
        } finally {
            $this->actorId = $previousActorId;
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

            $previousOrganizationId = $this->organizationId;
            $this->organizationId = $organizationId;

            try {
                return $callback();
            } finally {
                $this->organizationId = $previousOrganizationId;
            }
        });
    }
}
