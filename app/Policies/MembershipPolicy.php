<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

class MembershipPolicy
{
    public function view(User $user, Membership $membership): bool
    {
        return $membership->user_id === $user->getKey()
            || $user->canManageOrganization($membership->organization_id);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->canManageOrganization($organization);
    }

    public function update(User $user, Membership $membership): bool
    {
        if ($membership->user_id === $user->getKey()) {
            return $user->isPlatformAdmin();
        }

        return $user->canManageOrganization($membership->organization_id);
    }

    public function delete(User $user, Membership $membership): bool
    {
        if ($membership->user_id === $user->getKey()) {
            return $user->isPlatformAdmin();
        }

        return $user->canManageOrganization($membership->organization_id);
    }
}
