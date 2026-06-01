<?php

use App\DataObjects\Analysis\GatekeeperResult;
use App\Services\Analysis\Agents\MatchGatekeeperAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('gatekeeper passes valid parsed defeat match', function () {
    $agent = app(MatchGatekeeperAgent::class);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800, // 30 minutes
        'radiant_win' => false,
        'teamfights' => [['start' => 100, 'end' => 150]],
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0, // Radiant
            ],
        ],
    ];

    $memberSteamIds = ['76561198086019077']; // Member is on Radiant (lost)

    $result = $agent->validate($matchData, $memberSteamIds);

    expect($result)->toBeInstanceOf(GatekeeperResult::class)
        ->and($result->passed)->toBeTrue()
        ->and($result->reason)->toBeNull();
});

test('gatekeeper rejects match that is too short', function () {
    $agent = app(MatchGatekeeperAgent::class);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 300, // 5 minutes (remake)
        'radiant_win' => false,
        'teamfights' => [['start' => 100, 'end' => 150]],
        'players' => [],
    ];

    $result = $agent->validate($matchData, []);

    expect($result->passed)->toBeFalse()
        ->and($result->rejectCode)->toBe('duration_too_short')
        ->and($result->reason)->toContain('too short');
});

test('gatekeeper rejects match without teamfights', function () {
    $agent = app(MatchGatekeeperAgent::class);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'radiant_win' => false,
        'teamfights' => [], // No teamfights
        'players' => [],
    ];

    $result = $agent->validate($matchData, []);

    expect($result->passed)->toBeFalse()
        ->and($result->rejectCode)->toBe('missing_teamfights');
});

test('gatekeeper rejects match without outcome', function () {
    $agent = app(MatchGatekeeperAgent::class);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'teamfights' => [['start' => 100]],
        'players' => [],
        // Missing radiant_win
    ];

    $result = $agent->validate($matchData, []);

    expect($result->passed)->toBeFalse()
        ->and($result->rejectCode)->toBe('outcome_unknown');
});

test('gatekeeper rejects victory matches', function () {
    $agent = app(MatchGatekeeperAgent::class);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'radiant_win' => true, // Radiant won
        'teamfights' => [['start' => 100]],
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0, // Member is Radiant (won)
            ],
        ],
    ];

    $memberSteamIds = ['76561198086019077'];

    $result = $agent->validate($matchData, $memberSteamIds);

    expect($result->passed)->toBeFalse()
        ->and($result->rejectCode)->toBe('victory_not_defeat')
        ->and($result->reason)->toContain('won');
});

test('gatekeeper rejects when member team cannot be determined', function () {
    $agent = app(MatchGatekeeperAgent::class);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'radiant_win' => false,
        'teamfights' => [['start' => 100]],
        'players' => [
            [
                'account_id' => 12345678, // Different account
                'player_slot' => 0,
            ],
        ],
    ];

    $memberSteamIds = ['76561198086019077']; // Not in match

    $result = $agent->validate($matchData, $memberSteamIds);

    expect($result->passed)->toBeFalse()
        ->and($result->rejectCode)->toBe('team_unknown');
});
