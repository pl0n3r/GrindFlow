<?php

return [
    'security' => [
        'encryption_master_key' => env('ENCRYPTION_MASTER_KEY'),
    ],

    'connectors' => [
        'dropbox' => [
            'app_key' => env('DROPBOX_APP_KEY'),
            'app_secret' => env('DROPBOX_APP_SECRET'),
            'refresh_margin_seconds' => (int) env(
                'DROPBOX_REFRESH_MARGIN_SECONDS',
                300,
            ),
            'oauth_state_ttl_seconds' => (int) env(
                'DROPBOX_OAUTH_STATE_TTL_SECONDS',
                600,
            ),
        ],
        'google_drive' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'refresh_margin_seconds' => (int) env(
                'GOOGLE_REFRESH_MARGIN_SECONDS',
                300,
            ),
            'oauth_state_ttl_seconds' => (int) env(
                'GOOGLE_OAUTH_STATE_TTL_SECONDS',
                600,
            ),
        ],
    ],

    'media' => [
        'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
        'direct_upload_disk' => env('MEDIA_DIRECT_UPLOAD_DISK', 'media'),
        'staging_disk' => env('MEDIA_STAGING_DISK', 'media'),
        'ffprobe' => [
            'enabled' => (bool) env('MEDIA_FFPROBE_ENABLED', false),
            'binary' => env('MEDIA_FFPROBE_BINARY', 'ffprobe'),
            'timeout_seconds' => (int) env(
                'MEDIA_FFPROBE_TIMEOUT_SECONDS',
                30,
            ),
        ],
        'connector_max_bytes' => (int) env(
            'MEDIA_CONNECTOR_MAX_BYTES',
            2_147_483_648,
        ),
        'connector_scan_interval_minutes' => (int) env(
            'MEDIA_CONNECTOR_SCAN_INTERVAL_MINUTES',
            15,
        ),
        'connector_scan_batch_size' => (int) env(
            'MEDIA_CONNECTOR_SCAN_BATCH_SIZE',
            20,
        ),
        'connector_scan_page_budget' => (int) env(
            'MEDIA_CONNECTOR_SCAN_PAGE_BUDGET',
            20,
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
