<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MobileUploadGrantUse extends TenantModel
{
    use HasUuids;

    #[\Override]
    protected $fillable = [
        'nonce',
        'file_count',
        'byte_count',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_count' => 'integer',
            'byte_count' => 'integer',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
