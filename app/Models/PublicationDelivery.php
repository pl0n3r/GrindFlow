<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $attempts
 * @property ?CarbonImmutable $next_attempt_at
 * @property ?CarbonImmutable $claimed_until
 * @property ?CarbonImmutable $published_at
 */
class PublicationDelivery extends TenantModel
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_AUTHENTICATION_FAILED = 'authentication_failed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scheduled_publication_id',
        'idempotency_key',
        'status',
        'attempts',
        'next_attempt_at',
        'claimed_until',
        'published_at',
        'external_publication_id',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'claimed_until' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PublicationDeliveryEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PublicationDeliveryEvent::class, 'publication_delivery_id')
            ->orderBy('event_number');
    }

    /**
     * @return BelongsTo<ScheduledPublication, $this>
     */
    public function scheduledPublication(): BelongsTo
    {
        return $this->belongsTo(ScheduledPublication::class);
    }
}
