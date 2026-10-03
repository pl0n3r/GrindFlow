<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultTriageItem extends TenantModel
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ASSIGNED = 'assigned';

    #[\Override]
    protected $fillable = [
        'media_asset_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * @return BelongsTo<OperationalProfile, $this>
     */
    public function operationalProfile(): BelongsTo
    {
        return $this->belongsTo(OperationalProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
