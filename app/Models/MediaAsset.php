<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends TenantModel
{
    use HasUuids;

    public const STATUS_READY = 'ready';

    public const STATUS_DUPLICATE = 'duplicate';

    protected $fillable = [
        'media_blob_id',
        'duplicate_of',
        'ingested_by_user_id',
        'original_filename',
        'source_type',
        'source_ref',
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
     * @return BelongsTo<MediaBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(MediaBlob::class, 'media_blob_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of');
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ingestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ingested_by_user_id');
    }
}
