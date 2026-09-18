<?php

return [
    'media' => [
        'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
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
