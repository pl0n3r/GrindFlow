<?php

namespace App\Queue\Middleware;

use App\Contracts\OrganizationAwareJob;
use App\Support\Tenancy\TenantContext;
use Closure;

class UseOrganizationContext
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(OrganizationAwareJob $job, Closure $next): mixed
    {
        return $this->tenantContext->runWithinOrganization(
            $job->actorId(),
            $job->organizationId(),
            fn (): mixed => $next($job),
        );
    }
}
