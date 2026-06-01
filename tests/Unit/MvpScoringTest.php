<?php

use App\DataObjects\FantasyWeights;
use App\Jobs\ProcessMatchNotification;
use App\Models\DotaMatch;

test('calculateTeamStats aggregates stats correctly for both teams', function () {
    $allPlayers = [
        // Radiant players (player_slot < 128)
        ['player_slot' => 0, 'hero_damage' => 10000, 'tower_damage' => 2000, 'hero_healing' => 1000],
        ['player_slot' => 1, 'hero_damage' => 15000, 'tower_damage' => 3000, 'hero_healing' => 500],
        ['player_slot' => 2, 'hero_damage' => 8000, 'tower_damage' => 1000, 'hero_healing' => 3000],
        ['player_slot' => 3, 'hero_damage' => 12000, 'tower_damage' => 5000, 'hero_healing' => 0],
        ['player_slot' => 4, 'hero_damage' => 9000, 'tower_damage' => 1500, 'hero_healing' => 2000],
        // Dire players (player_slot >= 128)
        ['player_slot' => 128, 'hero_damage' => 11000, 'tower_damage' => 2500, 'hero_healing' => 800],
        ['player_slot' => 129, 'hero_damage' => 14000, 'tower_damage' => 3500, 'hero_healing' => 600],
        ['player_slot' => 130, 'hero_damage' => 7000, 'tower_damage' => 800, 'hero_healing' => 2500],
        ['player_slot' => 131, 'hero_damage' => 13000, 'tower_damage' => 4000, 'hero_healing' => 100],
        ['player_slot' => 132, 'hero_damage' => 10000, 'tower_damage' => 2000, 'hero_healing' => 1500],
    ];

    $matchData = ['radiant_score' => 45, 'dire_score' => 30];

    $job = new ProcessMatchNotification(new DotaMatch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('calculateTeamStats');
    $method->setAccessible(true);

    $stats = $method->invoke($job, $allPlayers, $matchData);

    expect($stats)->toHaveKeys(['radiant', 'dire'])
        ->and($stats['radiant']['hero_damage'])->toBe(54000)
        ->and($stats['radiant']['tower_damage'])->toBe(12500)
        ->and($stats['radiant']['hero_healing'])->toBe(6500)
        ->and($stats['dire']['hero_damage'])->toBe(55000)
        ->and($stats['dire']['tower_damage'])->toBe(12800)
        ->and($stats['dire']['hero_healing'])->toBe(5500);
});

test('calculatePlayerEfficiency computes correctly with healing multiplier', function () {
    $player = [
        'hero_damage' => 20000,
        'hero_healing' => 5000,
        'net_worth' => 15000,
    ];

    $job = new ProcessMatchNotification(new DotaMatch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('calculatePlayerEfficiency');
    $method->setAccessible(true);

    $efficiency = $method->invoke($job, $player);

    // Expected: (20000 + 5000*2) / 15000 = 30000 / 15000 = 2.0
    expect($efficiency)->toBe(2.0);
});

test('calculatePlayerEfficiency handles zero net worth gracefully', function () {
    $player = [
        'hero_damage' => 10000,
        'hero_healing' => 2000,
        'net_worth' => 0,
    ];

    $job = new ProcessMatchNotification(new DotaMatch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('calculatePlayerEfficiency');
    $method->setAccessible(true);

    $efficiency = $method->invoke($job, $player);

    // Expected: (10000 + 2000*2) / 1 = 14000 / 1 = 14000
    expect($efficiency)->toBe(14000.0);
});

test('calculatePlayerEfficiency handles missing stats', function () {
    $player = ['net_worth' => 10000];

    $job = new ProcessMatchNotification(new DotaMatch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('calculatePlayerEfficiency');
    $method->setAccessible(true);

    $efficiency = $method->invoke($job, $player);

    // Expected: (0 + 0*2) / 10000 = 0
    expect($efficiency)->toBe(0.0);
});

test('FantasyWeights::weights returns normalized weights summing to 1.0', function () {
    $weights = FantasyWeights::weights();

    $sum = array_sum($weights);

    expect($sum)->toBe(1.0)
        ->and($weights)->toHaveKeys([
            'kill_participation',
            'hero_damage_share',
            'tower_damage_share',
            'healing_impact',
            'kda_normalized',
            'efficiency_score',
            'economy_percentile',
        ]);
});
