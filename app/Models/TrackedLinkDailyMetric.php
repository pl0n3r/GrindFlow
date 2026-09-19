<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $metric_date
 * @property int $clicks
 */
class TrackedLinkDailyMetric extends TenantModel
{
    use HasUuids;

    protected $fillable = [
        'tracked_link_id',
        'metric_date',
        'clicks',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'immutable_date',
            'clicks' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TrackedLink, $this>
     */
    public function trackedLink(): BelongsTo
    {
        return $this->belongsTo(TrackedLink::class);
    }
}
