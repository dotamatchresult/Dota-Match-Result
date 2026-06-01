<?php

use App\DataObjects\Analysis\NormalizedMatch;
use App\Models\Hero;
use App\Models\Member;
use App\Services\Analysis\Agents\NormalizerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('normalizer transforms match data into normalized structure', function () {
    $agent = app(NormalizerAgent::class);

    // Seed heroes
    Hero::factory()->create(['hero_id' => 14, 'localized_name' => 'Pudge']);
    Hero::factory()->create(['hero_id' => 110, 'localized_name' => 'Vengeful Spirit']);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'game_mode' => 23, // Turbo
        'radiant_win' => false,
        'players' => [
            [
                'account_id' => 86019077, // Member
                'player_slot' => 0, // Radiant
                'hero_id' => 14,
                'kills' => 10,
                'deaths' => 5,
                'assists' => 15,
                'hero_damage' => 20000,
                'tower_damage' => 3000,
                'gold_per_min' => 500,
                'xp_per_min' => 600,
                'net_worth' => 15000,
            ],
            [
                'account_id' => 12345678, // Enemy
                'player_slot' => 128, // Dire
                'hero_id' => 110,
                'kills' => 8,
                'deaths' => 6,
                'assists' => 12,
                'hero_damage' => 15000,
                'tower_damage' => 2000,
                'gold_per_min' => 450,
                'xp_per_min' => 550,
                'net_worth' => 13000,
            ],
        ],
        'teamfights' => [['start' => 100]],
        'objectives' => [['time' => 200, 'type' => 'tower']],
        'radiant_gold_adv' => [0, 100, -200],
        'radiant_xp_adv' => [0, 50, -100],
    ];

    $memberSteamIds = ['76561198086019077'];

    $normalized = $agent->normalize($matchData, $memberSteamIds);

    expect($normalized)->toBeInstanceOf(NormalizedMatch::class)
        ->and($normalized->matchId)->toBe('8651445707')
        ->and($normalized->memberTeam)->toBe('radiant')
        ->and($normalized->memberWon)->toBeFalse()
        ->and($normalized->duration)->toBe(1800)
        ->and($normalized->gameMode)->toBe('Turbo')
        ->and($normalized->radiantPlayers)->toHaveCount(1)
        ->and($normalized->direPlayers)->toHaveCount(1)
        ->and($normalized->radiantPlayers[0]->heroName)->toBe('Pudge')
        ->and($normalized->radiantPlayers[0]->team)->toBe('radiant')
        ->and($normalized->direPlayers[0]->heroName)->toBe('Vengeful Spirit')
        ->and($normalized->direPlayers[0]->team)->toBe('dire');
});

test('normalizer maps hero IDs to names from database', function () {
    $agent = app(NormalizerAgent::class);

    Hero::factory()->create(['hero_id' => 35, 'localized_name' => 'Sniper']);

    $matchData = [
        'match_id' => '123',
        'duration' => 1800,
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0,
                'hero_id' => 35,
                'kills' => 0,
                'deaths' => 0,
                'assists' => 0,
            ],
        ],
    ];

    $normalized = $agent->normalize($matchData, ['76561198086019077']);

    expect($normalized->radiantPlayers[0]->heroName)->toBe('Sniper')
        ->and($normalized->radiantPlayers[0]->heroId)->toBe(35);
});

test('normalizer handles unknown hero IDs gracefully', function () {
    $agent = app(NormalizerAgent::class);

    $matchData = [
        'match_id' => '123',
        'duration' => 1800,
        'radiant_win' => true,
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0,
                'hero_id' => 999, // Unknown hero
                'kills' => 0,
                'deaths' => 0,
                'assists' => 0,
            ],
        ],
    ];

    $normalized = $agent->normalize($matchData, ['76561198086019077']);

    expect($normalized->radiantPlayers[0]->heroName)->toBe('Hero #999');
});

test('normalizer separates member and enemy players correctly', function () {
    $agent = app(NormalizerAgent::class);

    Hero::factory()->count(5)->create();

    $matchData = [
        'match_id' => '123',
        'duration' => 1800,
        'radiant_win' => false,
        'players' => [
            ['account_id' => 86019077, 'player_slot' => 0, 'hero_id' => 1, 'kills' => 0, 'deaths' => 0, 'assists' => 0], // Member Radiant
            ['account_id' => 11111111, 'player_slot' => 1, 'hero_id' => 2, 'kills' => 0, 'deaths' => 0, 'assists' => 0], // Member Radiant
            ['account_id' => 22222222, 'player_slot' => 128, 'hero_id' => 3, 'kills' => 0, 'deaths' => 0, 'assists' => 0], // Enemy Dire
            ['account_id' => 33333333, 'player_slot' => 129, 'hero_id' => 4, 'kills' => 0, 'deaths' => 0, 'assists' => 0], // Enemy Dire
        ],
    ];

    $memberSteamIds = [
        '76561198086019077',
        Member::convertAccountIdToSteamId(11111111),
    ];

    $normalized = $agent->normalize($matchData, $memberSteamIds);

    expect($normalized->radiantPlayers)->toHaveCount(2)
        ->and($normalized->direPlayers)->toHaveCount(2)
        ->and($normalized->memberTeam)->toBe('radiant');
});
