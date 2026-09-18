<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationContext
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $organizationId = (string) $request->route('organizationId');

        $organization = Organization::query()
            ->visibleTo($user)
            ->whereKey($organizationId)
            ->firstOrFail();

        $request->attributes->set('tenantOrganization', $organization);

        return $this->tenantContext->runWithinOrganization(
            $user,
            (string) $organization->getKey(),
            fn (): Response => $next($request),
        );
    }
}
