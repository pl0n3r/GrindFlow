<?php

namespace App\Support\Vault;

use App\Models\MediaAsset;
use App\Models\OperationalProfile;
use App\Models\User;
use App\Models\VaultTriageItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class VaultTriageQueue
{
    public function __construct(private TenantContext $context) {}

    public function enqueueAmbiguous(MediaAsset $asset): VaultTriageItem
    {
        $organizationId = $this->organizationId();
        $this->assertPersisted($asset, 'asset');
        $this->assertSameTenant($asset, $organizationId, 'asset');

        return VaultTriageItem::query()->firstOrCreate(
            ['media_asset_id' => $asset->getKey()],
            ['status' => VaultTriageItem::STATUS_PENDING],
        );
    }

    public function assignProfile(
        VaultTriageItem $item,
        OperationalProfile $profile,
        User $operator,
    ): VaultTriageItem {
        $organizationId = $this->organizationId();

        $this->assertPersisted($item, 'triage item');
        $this->assertPersisted($profile, 'operational profile');
        $this->assertPersisted($operator, 'operator');
        $this->assertSameTenant($item, $organizationId, 'triage item');
        $this->assertSameTenant($profile, $organizationId, 'operational profile');

        $actorId = $this->context->actorId();
        if (
            $actorId === null
            || ! hash_equals($actorId, (string) $operator->getKey())
            || ! $operator->canManageOrganization($organizationId)
        ) {
            throw new AuthorizationException('Operator cannot assign Vault triage ownership.');
        }

        return DB::transaction(function () use ($item, $profile, $operator): VaultTriageItem {
            $locked = VaultTriageItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new AuthorizationException('Vault triage item is not visible in this tenant.');
            }

            if (
                $locked->status !== VaultTriageItem::STATUS_PENDING
                || $locked->operational_profile_id !== null
            ) {
                throw new LogicException('Vault triage ownership has already been assigned.');
            }

            $locked->forceFill([
                'operational_profile_id' => $profile->getKey(),
                'assigned_by_user_id' => $operator->getKey(),
                'assigned_at' => now(),
                'status' => VaultTriageItem::STATUS_ASSIGNED,
            ])->save();

            return $locked->refresh();
        });
    }

    private function organizationId(): string
    {
        $organizationId = $this->context->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException('Tenant context is required for Vault triage.');
        }

        return $organizationId;
    }

    private function assertPersisted(Model $model, string $label): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException($label.' must be persisted.');
        }
    }

    private function assertSameTenant(Model $model, string $organizationId, string $label): void
    {
        $modelOrganizationId = $model->getAttribute('organization_id');

        if (
            ! is_string($modelOrganizationId)
            || ! hash_equals($organizationId, $modelOrganizationId)
        ) {
            throw new AuthorizationException($label.' belongs to another tenant.');
        }
    }
}
