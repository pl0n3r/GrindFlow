<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireDistributionSchema
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Schema::hasTable('publishing_destinations')
                && Schema::hasTable('scheduled_publications')
                && Schema::hasTable('publication_deliveries'),
            503,
        );

        return $next($request);
    }
}
