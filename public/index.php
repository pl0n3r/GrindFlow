<?php

use App\Support\Deployment\ReleaseCacheGuard;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

$cacheGuard = new ReleaseCacheGuard;

if (
    $cacheGuard->refreshIfNeeded(
        dirname(__DIR__),
        dirname(__DIR__).'/storage',
    )
    && function_exists('opcache_reset')
) {
    opcache_reset();
}

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
