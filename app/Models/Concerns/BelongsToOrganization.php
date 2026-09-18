<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $organizationId = app(TenantContext::class)->organizationId();

            if ($organizationId === null) {
                throw new AuthorizationException('Tenant context is required to create this record.');
            }

            $requestedOrganizationId = $model->getAttribute('organization_id');

            if (
                $requestedOrganizationId !== null
                && (string) $requestedOrganizationId !== $organizationId
            ) {
                throw new AuthorizationException('Cross-tenant record creation is not allowed.');
            }

            $model->setAttribute('organization_id', $organizationId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new LogicException('organization_id is immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
