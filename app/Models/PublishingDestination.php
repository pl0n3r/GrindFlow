<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublishingDestination extends TenantModel
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'name',
        'provider',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<ScheduledPublication, $this>
     */
    public function scheduledPublications(): HasMany
    {
        return $this->hasMany(ScheduledPublication::class);
    }
}
