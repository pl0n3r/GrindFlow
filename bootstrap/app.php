<?php

use App\Http\Middleware\ApplyTenantUserContext;
use App\Http\Middleware\ResolveOrganizationContext;
use App\Support\Diagnostics\DiagnosticLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.user' => ApplyTenantUserContext::class,
            'tenant.organization' => ResolveOrganizationContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry reporta los errores reales de producción; en otros entornos
        // su DSN es null y no envía nada (config/sentry.php).
        if (class_exists(Integration::class)) {
            Integration::handles($exceptions);
        }

        $exceptions->report(function (Throwable $exception): void {
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            if ($status < 500) {
                return;
            }

            $request = app()->bound('request') ? app('request') : null;

            app(DiagnosticLog::class)->record(
                $exception,
                $request instanceof Request ? $request : null,
            );
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
