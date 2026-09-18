<?php

namespace App\Providers;

use App\Models\Membership;
use App\Models\Organization;
use App\Policies\MembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Support\Deployment\ReleaseCacheGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            $refreshed = app(ReleaseCacheGuard::class)->refreshIfNeeded();

            if ($refreshed && function_exists('opcache_reset')) {
                opcache_reset();
            }
        }

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
    }
}
