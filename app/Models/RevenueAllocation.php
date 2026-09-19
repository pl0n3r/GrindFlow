<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $amount_minor
 * @property CarbonImmutable $occurred_on
 */
class RevenueAllocation extends TenantModel
{
    use HasUuids;

    protected $fillable = [
        'created_by_user_id',
        'beneficiary_user_id',
        'reversal_of_id',
        'source_label',
        'amount_minor',
        'currency',
        'occurred_on',
        'note',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Revenue allocations are append-only; create a reversal instead.',
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Revenue allocations are append-only and cannot be deleted.',
            );
        });
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    /**
     * @return BelongsTo<RevenueAllocation, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<RevenueAllocation, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
