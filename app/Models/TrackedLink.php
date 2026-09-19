<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedLink extends TenantModel
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'created_by_user_id',
        'token',
        'label',
        'destination_url',
        'channel',
        'campaign',
        'status',
    ];

    /**
     * @return HasMany<TrackedLinkDailyMetric, $this>
     */
    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(TrackedLinkDailyMetric::class);
    }
}
