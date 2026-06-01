<?php

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\NormalizedPlayer;
use App\Services\Analysis\Agents\TeamfightAggregatorAgent;

test('aggregator processes teamfights into summaries', function () {
    $agent = app(TeamfightAggregatorAgent::class);

    $normalized = new NormalizedMatch(
        matchId: '123',
        memberTeam: 'radiant',
        memberWon: false,
        duration: 1800,
        gameMode: 'Turbo',
        radiantPlayers: [
            new NormalizedPlayer(1, 'Pudge', 'radiant', 5, 3, 10, 10000, 1000, 400, 500, 12000),
        ],
        direPlayers: [
            new NormalizedPlayer(2, 'Sniper', 'dire', 8, 2, 5, 15000, 2000, 450, 550, 15000),
        ],
        teamfights: [
            [
                'start' => 100,
                'end' => 150,
                'players' => [
                    ['deaths' => 0, 'gold_delta' => 500, 'damage' => 2000], // Radiant p1
                    ['deaths' => 0, 'gold_delta' => 300, 'damage' => 1500], // Radiant p2
                    ['deaths' => 1, 'gold_delta' => -400, 'damage' => 800], // Dire p1
                    ['deaths' => 1, 'gold_delta' => -400, 'damage' => 500], // Dire p2
                ],
            ],
        ],
        objectives: [],
        goldAdvantage: [],
        xpAdvantage: [],
    );

    $summaries = $agent->aggregate($normalized);

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->startTime)->toBe(100)
        ->and($summaries[0]->endTime)->toBe(150)
        ->and($summaries[0]->duration)->toBe(50)
        ->and($summaries[0]->outcome)->toBeIn(['radiant_win', 'dire_win', 'even']);
});

test('aggregator classifies swing magnitude correctly', function () {
    $agent = app(TeamfightAggregatorAgent::class);

    $normalized = new NormalizedMatch(
        matchId: '123',
        memberTeam: 'dire',
        memberWon: false,
        duration: 1800,
        gameMode: 'Turbo',
        radiantPlayers: [],
        direPlayers: [],
        teamfights: [
            [
                'start' => 100,
                'end' => 150,
                'players' => [
                    ['deaths' => 0, 'gold_delta' => 0, 'damage' => 5000],   // Radiant p1
                    ['deaths' => 0, 'gold_delta' => 0, 'damage' => 4000],   // Radiant p2
                    ['deaths' => 0, 'gold_delta' => 0, 'damage' => 1000],   // Radiant p3
                    ['deaths' => 0, 'gold_delta' => 0, 'damage' => 800],    // Radiant p4
                    ['deaths' => 0, 'gold_delta' => 0, 'damage' => 500],    // Radiant p5
                    ['deaths' => 3, 'gold_delta' => -2500, 'damage' => 200], // Dire p1 (big swing)
                    ['deaths' => 2, 'gold_delta' => -1200, 'damage' => 150], // Dire p2
                    ['deaths' => 0, 'gold_delta' => -100, 'damage' => 100],  // Dire p3
                    ['deaths' => 0, 'gold_delta' => -100, 'damage' => 50],   // Dire p4
                    ['deaths' => 0, 'gold_delta' => -100, 'damage' => 50],   // Dire p5
                ],
            ],
        ],
        objectives: [],
        goldAdvantage: [],
        xpAdvantage: [],
    );

    $summaries = $agent->aggregate($normalized);

    expect($summaries[0]->swing)->toBe('big')
        ->and($summaries[0]->isMajorSwing())->toBeTrue();
});

test('aggregator identifies key heroes by damage', function () {
    $agent = app(TeamfightAggregatorAgent::class);

    $normalized = new NormalizedMatch(
        matchId: '123',
        memberTeam: 'radiant',
        memberWon: false,
        duration: 1800,
        gameMode: 'Turbo',
        radiantPlayers: [
            new NormalizedPlayer(1, 'Lina', 'radiant', 5, 3, 10, 10000, 1000, 400, 500, 12000),
            new NormalizedPlayer(2, 'Crystal Maiden', 'radiant', 2, 5, 15, 5000, 500, 350, 450, 8000),
        ],
        direPlayers: [
            new NormalizedPlayer(3, 'Invoker', 'dire', 8, 2, 5, 18000, 1500, 500, 600, 18000),
        ],
        teamfights: [
            [
                'start' => 500,
                'end' => 550,
                'players' => [
                    ['deaths' => 0, 'gold_delta' => 300, 'damage' => 3000], // Lina (high damage)
                    ['deaths' => 1, 'gold_delta' => -200, 'damage' => 800], // CM (low damage)
                    ['deaths' => 0, 'gold_delta' => 500, 'damage' => 4000], // Invoker (highest damage)
                ],
            ],
        ],
        objectives: [],
        goldAdvantage: [],
        xpAdvantage: [],
        // Fight players[] uses indices 0=Lina, 1=CM, 2=Invoker in this test
        heroIndexMap: [0 => 'Lina', 1 => 'Crystal Maiden', 2 => 'Invoker'],
    );

    $summaries = $agent->aggregate($normalized);

    expect($summaries[0]->keyHeroes)->toContain('Lina')
        ->and($summaries[0]->keyHeroes)->toContain('Invoker');
});

test('aggregator handles empty teamfights gracefully', function () {
    $agent = app(TeamfightAggregatorAgent::class);

    $normalized = new NormalizedMatch(
        matchId: '123',
        memberTeam: 'radiant',
        memberWon: false,
        duration: 1800,
        gameMode: 'Turbo',
        radiantPlayers: [],
        direPlayers: [],
        teamfights: [], // No teamfights
        objectives: [],
        goldAdvantage: [],
        xpAdvantage: [],
    );

    $summaries = $agent->aggregate($normalized);

    expect($summaries)->toBeEmpty();
});
