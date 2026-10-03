<?php

namespace App\Providers;

use App\Models\Membership;
use App\Models\Organization;
use App\Policies\MembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Services\Distribution\DistributionProviderRegistry;
use App\Services\Distribution\SandboxDistributionProvider;
use App\Support\Security\MobileUploadGrant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(
            MobileUploadGrant::class,
            static fn (): MobileUploadGrant => new MobileUploadGrant(
                (string) config('grindflow.media.guest_upload_signing_key'),
            ),
        );
        $this->app->singleton(DistributionProviderRegistry::class);
    }

    public function boot(): void
    {
        $this->app->make(DistributionProviderRegistry::class)->register(
            'sandbox',
            $this->app->make(SandboxDistributionProvider::class),
        );

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
    }
}
