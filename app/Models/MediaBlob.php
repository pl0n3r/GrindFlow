<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaBlob extends TenantModel
{
    use HasUuids;

    protected $fillable = [
        'storage_disk',
        'storage_key',
        'sha256',
        'byte_size',
        'mime_type',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }
}
