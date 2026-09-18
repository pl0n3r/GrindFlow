<?php

return [
    'media' => [
        'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_upload_kb' => (int) env('MEDIA_UPLOAD_MAX_KB', 7168),
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
