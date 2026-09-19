<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireFinanceSchema
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Schema::hasTable('revenue_allocations'),
            503,
        );

        return $next($request);
    }
}
