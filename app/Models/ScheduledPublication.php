<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPublication extends TenantModel
{
    use HasUuids;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'media_asset_id',
        'publishing_destination_id',
        'scheduled_by_user_id',
        'status',
        'scheduled_for_utc',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for_utc' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * @return BelongsTo<PublishingDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(
            PublishingDestination::class,
            'publishing_destination_id',
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_user_id');
    }
}
