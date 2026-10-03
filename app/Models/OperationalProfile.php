<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Tenant-scoped operational identity, intentionally distinct from membership.
 */
class OperationalProfile extends TenantModel
{
    use HasUuids;

    #[\Override]
    protected $fillable = [
        'name',
        'slug',
    ];
}
