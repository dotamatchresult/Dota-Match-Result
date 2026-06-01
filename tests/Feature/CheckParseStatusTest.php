<?php

use App\Jobs\AnalyzeMatchWithAI;
use App\Jobs\CheckParseStatus;
use App\Jobs\RetryMatchParse;
use App\Models\DotaMatch;
use App\Services\OpenDotaParseService;
use App\Services\SteamApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

$fakeMatchDetails = fn (string $matchId) => [
    'match_id' => $matchId,
    'radiant_win' => true,
    'game_mode' => 23,
    'duration' => 1800,
    'radiant_score' => 30,
    'dire_score' => 20,
    'first_blood_time' => 45,
    'radiant_gold_adv' => [100, 200, 300],
    'radiant_xp_adv' => [50, 150, 250],
    'teamfights' => [
        [
            'start' => 600,
            'end' => 660,
            'players' => array_fill(0, 10, ['deaths' => 0, 'gold_delta' => 100, 'damage' => 500]),
        ],
    ],
    'objectives' => [
        ['time' => 800, 'type' => 'building_kill', 'key' => 'npc_dota_tower_radiant_1'],
    ],
    'players' => [
        [
            'account_id' => 52079951,
            'player_slot' => 0,
            'hero_id' => 10,
            'kills' => 5,
            'deaths' => 2,
            'assists' => 8,
            'hero_damage' => 15000,
            'tower_damage' => 3000,
            'hero_healing' => 500,
            'last_hits' => 120,
            'level' => 25,
            'net_worth' => 18000,
            'gold_per_min' => 600,
            'xp_per_min' => 700,
        ],
    ],
];

it('stores all required match_data fields for AI analysis when parse completes', function () use ($fakeMatchDetails) {
    Queue::fake();

    $match = DotaMatch::factory()->create([
        'match_id' => '99887766',
        'parse_job_id' => 'job-abc',
        'parse_status' => 'pending',
    ]);

    $details = $fakeMatchDetails('99887766');

    $parseService = Mockery::mock(OpenDotaParseService::class);
    $parseService->shouldReceive('checkParseStatus')->with('job-abc')->andReturn(null);

    $steamApi = Mockery::mock(SteamApiService::class);
    $steamApi->shouldReceive('getMatchDetails')->with('99887766')->andReturn($details);

    (new CheckParseStatus($match))->handle($parseService, $steamApi);

    $match->refresh();

    $stored = $match->match_data;

    // Top-level identifiers and scalars
    expect($stored['match_id'])->toBe('99887766')
        ->and($stored['radiant_win'])->toBeTrue()
        ->and($stored['game_mode'])->toBe(23)
        ->and($stored['duration'])->toBe(1800)
        ->and($stored['radiant_score'])->toBe(30)
        ->and($stored['dire_score'])->toBe(20)
        ->and($stored['first_blood_time'])->toBe(45);

    // Required by NormalizerAgent for gold/xp curves
    expect($stored['radiant_gold_adv'])->toBeArray()->toHaveCount(3)
        ->and($stored['radiant_xp_adv'])->toBeArray()->toHaveCount(3);

    // Required by TeamfightAggregatorAgent
    expect($stored['teamfights'])->toBeArray()->toHaveCount(1);

    // Required by ObjectiveFlowAgent
    expect($stored['objectives'])->toBeArray()->toHaveCount(1);

    // Player fields including level
    $player = $stored['players'][0];
    expect($player['account_id'])->toBe(52079951)
        ->and($player['level'])->toBe(25)
        ->and($player['hero_id'])->toBe(10)
        ->and($player['kills'])->toBe(5)
        ->and($player['deaths'])->toBe(2)
        ->and($player['assists'])->toBe(8)
        ->and($player['hero_damage'])->toBe(15000)
        ->and($player['tower_damage'])->toBe(3000)
        ->and($player['hero_healing'])->toBe(500)
        ->and($player['last_hits'])->toBe(120)
        ->and($player['net_worth'])->toBe(18000)
        ->and($player['gold_per_min'])->toBe(600)
        ->and($player['xp_per_min'])->toBe(700);

    expect($match->parse_status)->toBe('parsed');

    Queue::assertPushed(AnalyzeMatchWithAI::class);
});

it('dispatches RetryMatchParse when match is still parsing', function () {
    Queue::fake();

    $match = DotaMatch::factory()->create([
        'match_id' => '11223344',
        'parse_job_id' => 'job-xyz',
        'parse_status' => 'pending',
        'parse_retry_count' => 0,
    ]);

    $parseService = Mockery::mock(OpenDotaParseService::class);
    $parseService->shouldReceive('checkParseStatus')->with('job-xyz')->andReturn(['status' => 'processing']);

    $steamApi = Mockery::mock(SteamApiService::class);

    (new CheckParseStatus($match))->handle($parseService, $steamApi);

    Queue::assertPushed(RetryMatchParse::class);
    Queue::assertNotPushed(AnalyzeMatchWithAI::class);
});

it('dispatches RetryMatchParse when re-fetching parsed match details fails', function () {
    Queue::fake();

    $match = DotaMatch::factory()->create([
        'match_id' => '55667788',
        'parse_job_id' => 'job-fail',
        'parse_status' => 'pending',
        'parse_retry_count' => 0,
    ]);

    $parseService = Mockery::mock(OpenDotaParseService::class);
    $parseService->shouldReceive('checkParseStatus')->with('job-fail')->andReturn(null);

    $steamApi = Mockery::mock(SteamApiService::class);
    $steamApi->shouldReceive('getMatchDetails')->with('55667788')->andReturn(null);

    (new CheckParseStatus($match))->handle($parseService, $steamApi);

    Queue::assertPushed(RetryMatchParse::class);
    Queue::assertNotPushed(AnalyzeMatchWithAI::class);
});
