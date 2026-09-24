<?php

/*
 * Errores de producción hacia Sentry (proyecto GrindFlow en pl0n3r.sentry.io).
 *
 * El DSN solo permite ENVIAR eventos a ese proyecto (no lee datos), por eso
 * vive como valor por defecto versionado. Solo se activa en production;
 * SENTRY_LARAVEL_DSN en el entorno lo reemplaza y un valor vacío lo apaga.
 * Privacidad: sin PII, sin cuerpos de request ni SQL con parámetros.
 */

$release = require __DIR__.'/version.php';

return [
    'dsn' => env('APP_ENV') === 'production'
        ? env('SENTRY_LARAVEL_DSN', 'https://4e981c4c3adfc32afc86670945a7e4a4@o4512139951865856.ingest.us.sentry.io/4512139977621504')
        : null,

    'release' => 'grindflow@'.($release['number'] ?? '0.0.0-dev'),

    'environment' => 'production',

    'send_default_pii' => false,

    'max_request_body_size' => 'none',

    'traces_sample_rate' => 0.0,

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => false,
    ],

    'ignore_exceptions' => [],
];
