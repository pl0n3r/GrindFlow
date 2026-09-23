<?php

use App\Support\Deployment\ReleaseCacheGuard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$cacheGuard = new ReleaseCacheGuard;

$releaseChanged = $cacheGuard->refreshIfNeeded(
    dirname(__DIR__),
    dirname(__DIR__).'/storage',
);

if ($releaseChanged && function_exists('opcache_reset')) {
    opcache_reset();
}

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

if ($releaseChanged) {
    try {
        /** @var ConsoleKernel $console */
        $console = $app->make(ConsoleKernel::class);
        $console->bootstrap();

        if (
            $app->environment('production')
            && config('grindflow.phase') === 'construccion'
            && trim((string) config('grindflow.smoke_user.password')) !== ''
            && $console->call('grindflow:provision-smoke-user') !== 0
        ) {
            error_log('GrindFlow post-deploy smoke identity reconciliation failed safely.');
        }
    } catch (Throwable) {
        // Never turn an operational reconciliation failure into a customer 5xx.
        // Production Smoke remains the fail-closed signal for the deployment.
        error_log('GrindFlow post-deploy smoke identity reconciliation failed safely.');
    }
}

$app->handleRequest(Request::capture());
