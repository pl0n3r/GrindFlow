<?php

namespace App\Services\Media;

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

final readonly class VaultOwnershipTriage
{
    public function __construct(private TenantContext $context) {}

    public function queueAmbiguous(MediaAsset $asset): VaultTriageItem
    {
        $organizationId = $this->organizationId();
        $this->assertPersisted($asset, 'asset');
        $this->assertSameTenant($asset, $organizationId, 'asset');

        if ($asset->getAttribute('profile_id') !== null) {
            throw new LogicException('Asset already has an explicit operational profile.');
        }

        return VaultTriageItem::query()->firstOrCreate(
            ['media_asset_id' => $asset->getKey()],
            ['status' => VaultTriageItem::STATUS_PENDING],
        );
    }

    public function assign(
        VaultTriageItem $item,
        OperationalProfile $profile,
        User $actor,
    ): VaultTriageItem {
        $organizationId = $this->organizationId();

        $this->assertPersisted($item, 'triage item');
        $this->assertPersisted($profile, 'operational profile');
        $this->assertPersisted($actor, 'actor');
        $this->assertSameTenant($item, $organizationId, 'triage item');
        $this->assertSameTenant($profile, $organizationId, 'operational profile');

        $actorId = $this->context->actorId();
        if (
            $actorId === null
            || ! hash_equals($actorId, (string) $actor->getKey())
            || ! $actor->canManageOrganization($organizationId)
        ) {
            throw new AuthorizationException('Actor cannot assign Vault ownership.');
        }

        return DB::transaction(function () use ($item, $profile, $actor): VaultTriageItem {
            $lockedItem = VaultTriageItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedItem === null) {
                throw new AuthorizationException('Vault triage item is not visible in this tenant.');
            }

            $asset = MediaAsset::query()
                ->whereKey($lockedItem->media_asset_id)
                ->lockForUpdate()
                ->first();

            if ($asset === null) {
                throw new AuthorizationException('Vault asset is not visible in this tenant.');
            }

            if (
                $lockedItem->status !== VaultTriageItem::STATUS_PENDING
                || $lockedItem->assigned_profile_id !== null
                || $asset->profile_id !== null
            ) {
                throw new LogicException('Vault ownership has already been assigned.');
            }

            $asset->forceFill(['profile_id' => $profile->getKey()])->save();

            $lockedItem->forceFill([
                'assigned_profile_id' => $profile->getKey(),
                'assigned_by_user_id' => $actor->getKey(),
                'assigned_at' => now(),
                'status' => VaultTriageItem::STATUS_ASSIGNED,
            ])->save();

            return $lockedItem->refresh();
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
