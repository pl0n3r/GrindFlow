<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaConnection extends TenantModel
{
    use HasUuids;

    public const PROVIDER_DROPBOX = 'dropbox';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';

    protected $fillable = [
        'authorized_by_user_id',
        'provider',
        'label',
        'account_identifier',
        'cursor',
        'root_path',
        'status',
        'scan_interval_minutes',
        'next_scan_at',
        'last_scan_at',
        'consecutive_failures',
        'last_error',
        'scopes',
        'metadata',
    ];

    protected $hidden = [
        'access_ciphertext',
        'refresh_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'next_scan_at' => 'datetime',
            'last_scan_at' => 'datetime',
            'scan_interval_minutes' => 'integer',
            'consecutive_failures' => 'integer',
            'scopes' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function cryptoContext(): string
    {
        return sprintf(
            'grindflow:cloud:%s:%s',
            (string) $this->organization_id,
            $this->provider,
        );
    }
}
