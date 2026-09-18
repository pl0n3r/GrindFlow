<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_STORAGE_ACCESS_KEY_ID')
                ?: env('AWS_ACCESS_KEY_ID')
                ?: env('R2_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_STORAGE_SECRET_ACCESS_KEY')
                ?: env('AWS_SECRET_ACCESS_KEY')
                ?: env('R2_SECRET_ACCESS_KEY'),
            'region' => env('MEDIA_STORAGE_REGION')
                ?: env('AWS_DEFAULT_REGION')
                ?: env('R2_REGION', 'auto'),
            'bucket' => env('MEDIA_STORAGE_BUCKET')
                ?: env('AWS_BUCKET')
                ?: env('R2_BUCKET'),
            'url' => env('MEDIA_STORAGE_URL')
                ?: env('AWS_URL')
                ?: env('R2_PUBLIC_BASE_URL'),
            'endpoint' => env('MEDIA_STORAGE_ENDPOINT')
                ?: env('AWS_ENDPOINT')
                ?: env('R2_ENDPOINT'),
            'use_path_style_endpoint' => env(
                'MEDIA_STORAGE_USE_PATH_STYLE_ENDPOINT',
                env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            ),
            'throw' => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
