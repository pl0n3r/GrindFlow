<?php

return [
    'default' => env('CACHE_STORE', 'array'),
    // OIDC bootstrap rate limiting must persist across requests.
    'limiter' => env('CACHE_LIMITER', 'file'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'grindflow-cache-'),
];
