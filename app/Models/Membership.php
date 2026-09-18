<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Membership extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
    ];

    protected static function booted(): void
    {
        static::updating(function (Membership $membership): void {
            if ($membership->isDirty(['organization_id', 'user_id'])) {
                throw new LogicException('Membership identity cannot be changed; replace the membership instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
