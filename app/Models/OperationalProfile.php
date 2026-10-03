<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OperationalProfile extends TenantModel
{
    use HasUuids;

    #[\Override]
    protected $fillable = [
        'name',
        'slug',
    ];
}
