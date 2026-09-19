<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable, tenant-owned record of a delivery attempt transition.
 *
 * Only allowlisted event types and safe error codes belong in this ledger.
 */
class PublicationDeliveryEvent extends TenantModel
{
    use HasUuids;

    protected $fillable = [
        'publication_delivery_id',
        'event_type',
        'provider_attempt',
        'event_number',
        'error_code',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Delivery events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Delivery events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'provider_attempt' => 'integer',
            'event_number' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PublicationDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PublicationDelivery::class, 'publication_delivery_id');
    }
}
