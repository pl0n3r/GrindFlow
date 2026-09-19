<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireTrafficSchema
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Schema::hasTable('tracked_links')
                && Schema::hasTable('tracked_link_daily_metrics')
                && Schema::hasTable('tracked_link_dedupes'),
            503,
        );

        return $next($request);
    }
}
