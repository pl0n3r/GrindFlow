<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * @return Attribute<?CarbonImmutable, DateTimeInterface|string|null>
     */
    protected function scheduledForUtc(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?CarbonImmutable => $value === null
                ? null
                : CarbonImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $value,
                    'UTC',
                ),
            set: static function (
                DateTimeInterface|string|null $value,
            ): ?string {
                if ($value === null) {
                    return null;
                }

                $date = $value instanceof DateTimeInterface
                    ? CarbonImmutable::instance($value)
                    : CarbonImmutable::parse($value, 'UTC');

                return $date
                    ->setTimezone('UTC')
                    ->format('Y-m-d H:i:s');
            },
        );
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
