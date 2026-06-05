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

    'daily_challenge' => [
        'enabled' => env('DAILY_CHALLENGE_ENABLED', true),
        'max_active_per_destination' => 5,
        'assignment_time' => '00:00',
        'announcement_time' => '08:00',
        'review_time' => '23:00',
        'timezone' => 'Asia/Jakarta',
        'assignment_history_days' => 14,
        'evaluators' => [
            'total_kills' => 'total_kills',
            'total_denies' => 'total_denies',
            'total_heal' => 'total_heal',
            'hero_win' => \App\Services\ChallengeEvaluators\HeroWinEvaluator::class,
            'item_win' => \App\Services\ChallengeEvaluators\ItemWinEvaluator::class,
            'last_hits' => 'last_hits',
            'zero_death_win' => 'zero_death_win',
            'fast_win' => 'fast_win',
        ],
    ],
];
