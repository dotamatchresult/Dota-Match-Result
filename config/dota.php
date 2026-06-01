<?php

return [
    'message_enabled' => env('APP_ENV') === 'production',

    // Number of recent matches to check per player
    'match_to_check' => 15,

    'weekly_summary' => [
        'enabled' => true,
        'day_of_week' => '1', // 1=Monday, 2=Tuesday, ..., 7=Sunday
        'time' => '10:00',
    ],

    'github' => [
        'min_files' => 100, // Minimum files changed to trigger a notification
    ],

    'image_preview' => env('IMAGE_PREVIEW_URL', 'https://image-preview-delta.vercel.app/api/preview'),
];
