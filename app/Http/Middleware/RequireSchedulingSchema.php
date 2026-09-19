<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireSchedulingSchema
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Schema::hasTable('publishing_destinations')
                && Schema::hasTable('scheduled_publications'),
            503,
        );

        return $next($request);
    }
}
