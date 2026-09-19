<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPublicationLink extends TenantModel
{
    use HasUuids;

    protected $fillable = [
        'scheduled_publication_id',
        'tracked_link_id',
    ];

    /**
     * @return BelongsTo<ScheduledPublication, $this>
     */
    public function scheduledPublication(): BelongsTo
    {
        return $this->belongsTo(ScheduledPublication::class);
    }

    /**
     * @return BelongsTo<TrackedLink, $this>
     */
    public function trackedLink(): BelongsTo
    {
        return $this->belongsTo(TrackedLink::class);
    }
}
