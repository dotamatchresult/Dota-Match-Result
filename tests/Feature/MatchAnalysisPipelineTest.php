<?php

use App\Models\Hero;
use App\Services\Analysis\MatchAnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

uses(RefreshDatabase::class);

test('pipeline executes full analysis successfully', function () {
    // Seed heroes
    Hero::factory()->create(['hero_id' => 14, 'localized_name' => 'Pudge']);
    Hero::factory()->create(['hero_id' => 110, 'localized_name' => 'Vengeful Spirit']);
    Hero::factory()->create(['hero_id' => 35, 'localized_name' => 'Sniper']);

    // Stage 1: Diagnosis JSON response; Stage 2: Bullet compression response
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [[
                'message' => [
                    'content' => '{"loss_type_confirmed":"EXECUTION_LOSS","root_cause":"Team lost most teamfight engagements","key_factors":["poor positioning in teamfights"],"momentum":"Dire gained momentum around minute 18","hero_failures":[{"hero":"Pudge","role":"initiator","issue":"ineffective_initiation","team_impact":"lost every fight"}],"enemy_pressure_sources":[{"hero":"Sniper","threat_type":"teamfight_carry","how_it_hurt_us":"dominated team damage"}],"objective_consequences":{"post_pickoff_lost":2,"missed_after_wins":1,"roshan":"enemy","critical_event":"none"},"scaling_assessment":{"pattern":"BleedOut","summary":"fell behind mid and never recovered"}}',
                ],
            ]],
            'usage' => ['total_tokens' => 180],
            'model' => 'gpt-5.4-mini',
        ]),
        CreateResponse::fake([
            'choices' => [[
                'message' => [
                    'content' => "- Hero Pudge tidak maksimal damage output\n- Teamfight di menit 18 jadi turning point\n- Enemy Sniper terlalu bebas farm",
                ],
            ]],
            'usage' => ['total_tokens' => 120],
            'model' => 'gpt-5.4-mini',
        ]),
    ]);

    // Parsed match data
    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'game_mode' => 23,
        'radiant_win' => false,
        'radiant_score' => 28,
        'dire_score' => 35,
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0, // Radiant
                'hero_id' => 14,
                'kills' => 8,
                'deaths' => 7,
                'assists' => 15,
                'hero_damage' => 18000,
                'tower_damage' => 1500,
                'gold_per_min' => 450,
                'xp_per_min' => 550,
                'net_worth' => 13500,
            ],
            [
                'account_id' => 12345678,
                'player_slot' => 128, // Dire
                'hero_id' => 35,
                'kills' => 12,
                'deaths' => 5,
                'assists' => 10,
                'hero_damage' => 25000,
                'tower_damage' => 3000,
                'gold_per_min' => 600,
                'xp_per_min' => 700,
                'net_worth' => 18000,
            ],
        ],
        'teamfights' => [
            [
                'start' => 600,
                'end' => 650,
                'players' => [
                    ['deaths' => 1, 'gold_delta' => -500, 'damage' => 2000],
                    ['deaths' => 0, 'gold_delta' => 800, 'damage' => 3500],
                ],
            ],
            [
                'start' => 1100,
                'end' => 1180,
                'players' => [
                    ['deaths' => 2, 'gold_delta' => -1200, 'damage' => 1500],
                    ['deaths' => 0, 'gold_delta' => 1500, 'damage' => 4000],
                ],
            ],
        ],
        'objectives' => [
            [
                'time' => 620,
                'type' => 'building_kill',
                'key' => 'npc_dota_goodguys_tower1_mid',
            ],
            [
                'time' => 1120,
                'type' => 'CHAT_MESSAGE_ROSHAN_KILL',
                'player_slot' => 128,
            ],
        ],
        'radiant_gold_adv' => [0, 100, -200, -500],
        'radiant_xp_adv' => [0, 50, -100, -300],
    ];

    $memberSteamIds = ['76561198046284805'];

    $pipeline = app(MatchAnalysisPipeline::class);
    $result = $pipeline->analyze($matchData, $memberSteamIds);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['analysis_text', 'computed_metrics', 'metadata'])
        ->and($result['analysis_text'])->toBeString()
        ->and($result['analysis_text'])->not->toBeEmpty()
        ->and($result['computed_metrics'])->toHaveKeys(['pipeline_version', 'loss_type', 'semantic_tags', 'derived_metrics', 'diagnosis', 'teamfight_summaries', 'player_impacts', 'objective_flow'])
        ->and($result['computed_metrics']['pipeline_version'])->toBe('2.0')
        ->and($result['computed_metrics']['loss_type'])->toBeString()
        ->and($result['computed_metrics']['semantic_tags'])->toBeArray()
        ->and($result['computed_metrics']['derived_metrics'])->toHaveKeys(['fight_control_ratio', 'objective_conversion_rate', 'enemy_pickoff_rate', 'protection_index', 'damage_concentration_ratio'])
        ->and($result['computed_metrics']['diagnosis'])->toHaveKeys(['loss_type_confirmed', 'root_cause', 'key_factors', 'momentum', 'enemy_pressure_sources', 'objective_consequences', 'scaling_assessment'])
        ->and($result['computed_metrics']['teamfight_summaries'])->toHaveCount(2)
        ->and($result['computed_metrics']['player_impacts'])->toHaveCount(1)
        ->and($result['metadata']['pipeline_version'])->toBe('2.0')
        ->and($result['metadata']['tokens_used'])->toBeGreaterThan(0)
        ->and($result['metadata'])->toHaveKeys(['stage1_tokens', 'stage2_tokens'])
        ->and($result['metadata']['model'])->toContain('gpt-5.4-mini');
});

test('pipeline rejects match from gatekeeper', function () {
    $pipeline = app(MatchAnalysisPipeline::class);

    // Match too short
    $matchData = [
        'match_id' => '123',
        'duration' => 400, // 6 minutes (remake)
        'radiant_win' => false,
        'teamfights' => [],
        'players' => [],
    ];

    $result = $pipeline->analyze($matchData, []);

    expect($result)->toBeNull();
});

test('pipeline handles LLM failure gracefully', function () {
    Hero::factory()->create(['hero_id' => 14, 'localized_name' => 'Pudge']);

    // Mock OpenAI to throw exception
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [], // Empty choices
        ]),
    ]);

    $matchData = [
        'match_id' => '8651445707',
        'duration' => 1800,
        'game_mode' => 23,
        'radiant_win' => false,
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0,
                'hero_id' => 14,
                'kills' => 5,
                'deaths' => 3,
                'assists' => 10,
                'hero_damage' => 10000,
                'tower_damage' => 1000,
                'gold_per_min' => 400,
                'xp_per_min' => 500,
                'net_worth' => 12000,
            ],
        ],
        'teamfights' => [
            [
                'start' => 500,
                'end' => 550,
                'players' => [
                    ['deaths' => 0, 'gold_delta' => 300, 'damage' => 2000],
                ],
            ],
        ],
        'objectives' => [],
    ];

    $pipeline = app(MatchAnalysisPipeline::class);
    $result = $pipeline->analyze($matchData, ['76561198086019077']);

    expect($result)->toBeNull();
});

test('pipeline computes teamfight summaries correctly', function () {
    Hero::factory()->create(['hero_id' => 14, 'localized_name' => 'Pudge']);

    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"loss_type_confirmed":"EXECUTION_LOSS","root_cause":"Poor teamfight execution","key_factors":["lost fights"],"momentum":"Dire gained momentum early"}']]],
            'usage' => ['total_tokens' => 150],
            'model' => 'gpt-5.4-mini',
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '- Test analysis']]],
            'usage' => ['total_tokens' => 80],
            'model' => 'gpt-5.4-mini',
        ]),
    ]);

    $matchData = [
        'match_id' => '123',
        'duration' => 1800,
        'game_mode' => 23,
        'radiant_win' => false,
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0,
                'hero_id' => 14,
                'kills' => 5,
                'deaths' => 3,
                'assists' => 10,
                'hero_damage' => 10000,
                'tower_damage' => 1000,
                'gold_per_min' => 400,
                'xp_per_min' => 500,
                'net_worth' => 12000,
            ],
        ],
        'teamfights' => [
            [
                'start' => 600,
                'end' => 650,
                'players' => [
                    ['deaths' => 1, 'gold_delta' => -500, 'damage' => 2000],
                ],
            ],
        ],
        'objectives' => [],
    ];

    $pipeline = app(MatchAnalysisPipeline::class);
    $result = $pipeline->analyze($matchData, ['76561198046284805']);

    $teamfightSummaries = $result['computed_metrics']['teamfight_summaries'];

    expect($teamfightSummaries)->toHaveCount(1)
        ->and($teamfightSummaries[0])->toHaveKeys(['time', 'outcome', 'swing', 'key_heroes'])
        ->and($teamfightSummaries[0]['time'])->toBe('10:00')
        ->and($teamfightSummaries[0]['outcome'])->toBeIn(['radiant_win', 'dire_win', 'even'])
        ->and($teamfightSummaries[0]['swing'])->toBeIn(['big', 'medium', 'small']);
});

test('pipeline computes player impacts correctly', function () {
    Hero::factory()->create(['hero_id' => 14, 'localized_name' => 'Pudge']);

    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '{"loss_type_confirmed":"EXECUTION_LOSS","root_cause":"Poor teamfight execution","key_factors":["lost fights"],"momentum":"Dire gained momentum"}']]],
            'usage' => ['total_tokens' => 150],
            'model' => 'gpt-5.4-mini',
        ]),
        CreateResponse::fake([
            'choices' => [['message' => ['content' => '- Test']]],
            'usage' => ['total_tokens' => 80],
            'model' => 'gpt-5.4-mini',
        ]),
    ]);

    $matchData = [
        'match_id' => '123',
        'duration' => 1800,
        'game_mode' => 23,
        'radiant_win' => false,
        'players' => [
            [
                'account_id' => 86019077,
                'player_slot' => 0,
                'hero_id' => 14,
                'kills' => 10,
                'deaths' => 3,
                'assists' => 15,
                'hero_damage' => 25000,
                'tower_damage' => 3000,
                'gold_per_min' => 550,
                'xp_per_min' => 650,
                'net_worth' => 18000,
            ],
        ],
        'teamfights' => [
            ['start' => 500, 'end' => 550, 'players' => [['deaths' => 0, 'gold_delta' => 500, 'damage' => 3000]]],
        ],
        'objectives' => [],
    ];

    $pipeline = app(MatchAnalysisPipeline::class);
    $result = $pipeline->analyze($matchData, ['76561198046284805']);

    $playerImpacts = $result['computed_metrics']['player_impacts'];

    expect($playerImpacts)->toHaveCount(1)
        ->and($playerImpacts[0])->toHaveKeys(['hero', 'impact_score', 'impact_label', 'kda'])
        ->and($playerImpacts[0]['hero'])->toBe('Pudge')
        ->and($playerImpacts[0]['impact_score'])->toBeNumeric()
        ->and($playerImpacts[0]['impact_label'])->toBeIn(['high', 'medium', 'low'])
        ->and($playerImpacts[0]['kda'])->toBeGreaterThan(0);
});
