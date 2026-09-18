<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaIngestion extends TenantModel
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'requested_by_user_id',
        'media_asset_id',
        'source_type',
        'source_ref',
        'source_disk',
        'source_key',
        'original_filename',
        'mime_type',
        'byte_size',
        'idempotency_key',
        'status',
        'attempts',
        'last_error',
        'delete_source_after_ingest',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'attempts' => 'integer',
            'delete_source_after_ingest' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
