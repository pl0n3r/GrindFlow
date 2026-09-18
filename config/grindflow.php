<?php

return [
    'media' => [
        'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
        'direct_upload_disk' => env('MEDIA_DIRECT_UPLOAD_DISK', 'media'),
        'staging_disk' => env('MEDIA_STAGING_DISK', 'media'),
        'connector_max_bytes' => (int) env(
            'MEDIA_CONNECTOR_MAX_BYTES',
            2_147_483_648,
        ),
        'direct_upload_max_bytes' => (int) env(
            'MEDIA_DIRECT_UPLOAD_MAX_BYTES',
            2_147_483_648,
        ),
        'direct_upload_ttl_minutes' => (int) env(
            'MEDIA_DIRECT_UPLOAD_TTL_MINUTES',
            15,
        ),
        'allowed_mimetypes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ],
    ],
];
