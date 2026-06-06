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
        'assignment_time' => '08:00',
        'announcement_time' => '08:00',
        'review_time' => '23:00',
        'timezone' => 'Asia/Jakarta',
        'assignment_history_days' => 14,
        'review_force_after_hours' => 12,
        'evaluators' => [
            // Existing
            'total_kills' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'total_denies' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'total_heal' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'hero_win' => \App\Services\ChallengeEvaluators\HeroWinEvaluator::class,
            'item_win' => \App\Services\ChallengeEvaluators\ItemWinEvaluator::class,
            'last_hits' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'zero_death_win' => \App\Services\ChallengeEvaluators\ZeroDeathWinEvaluator::class,
            'fast_win' => \App\Services\ChallengeEvaluators\FastWinEvaluator::class,
            // Accumulative Team (new)
            'total_assists' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'total_hero_damage' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'total_tower_damage' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'total_last_hits' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            'total_net_worth' => \App\Services\ChallengeEvaluators\AccumulativeTeamMetricEvaluator::class,
            // Single Match Team (new)
            'team_assists_match' => \App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator::class,
            'team_kills_match' => \App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator::class,
            'team_last_hits_match' => \App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator::class,
            'team_denies_match' => \App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator::class,
            'team_hero_damage_match' => \App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator::class,
            'team_tower_damage_match' => \App\Services\ChallengeEvaluators\SingleMatchTeamMetricEvaluator::class,
            // Single Match Individual (new)
            'player_kills_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_assists_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_last_hits_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_hero_damage_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_tower_damage_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_net_worth_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_gpm_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
            'player_xpm_match' => \App\Services\ChallengeEvaluators\SingleMatchIndividualMetricEvaluator::class,
        ],
    ],
];
