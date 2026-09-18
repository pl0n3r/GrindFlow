<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->isPlatformAdmin()
            || $user->memberships()->where('organization_id', $organization->getKey())->exists();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->canManageOrganization($organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->isPlatformAdmin();
    }
}
