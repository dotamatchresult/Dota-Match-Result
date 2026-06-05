<?php

use App\Models\Challenge;
use App\Models\DestinationChallenge;
use App\Models\Hero;
use App\Models\Item;
use App\Services\DailyChallenge\ChallengeDescriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed a known hero and item for deterministic tests
    Hero::query()->firstOrCreate(
        ['hero_id' => 14],
        ['name' => 'npc_dota_hero_pudge', 'localized_name' => 'Pudge']
    );

    Item::query()->firstOrCreate(
        ['item_id' => 116],
        ['name' => 'item_butterfly', 'dname' => 'Butterfly', 'cost' => 5000]
    );
});

// --- hero_win with metadata ---

test('hero_win returns correct description with metadata hero_id', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 2,
        'progress_data' => ['metadata' => ['hero_id' => 14]],
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan 2 pertandingan menggunakan Pudge');
});

// --- hero_win fallback to configuration ---

test('hero_win falls back to configuration when metadata missing', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => 14],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 2,
        'progress_data' => null,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan 2 pertandingan menggunakan Pudge');
});

// --- hero_win fallback when hero not found ---

test('hero_win falls back to generic name when hero not found', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => 99999],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 2,
        'progress_data' => null,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan 2 pertandingan menggunakan Hero yang ditentukan');
});

// --- item_win with metadata ---

test('item_win returns correct description with metadata item_id', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'item_win',
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 3,
        'progress_data' => ['metadata' => ['item_id' => 116]],
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan 3 pertandingan dengan membawa Butterfly');
});

// --- item_win fallback when metadata missing ---

test('item_win falls back to configuration when metadata missing', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'item_win',
        'configuration' => ['item_id' => 116],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 3,
        'progress_data' => null,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan 3 pertandingan dengan membawa Butterfly');
});

// --- item_win fallback when item not found ---

test('item_win falls back to generic name when item not found', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'item_win',
        'configuration' => ['item_id' => 99999],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 3,
        'progress_data' => null,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan 3 pertandingan dengan membawa Item yang ditentukan');
});

// --- total_kills ---

test('total_kills returns correct description', function () {
    $challenge = Challenge::factory()->create(['code' => 'total_kills']);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 30,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Dapatkan 30 total kill');
});

// --- total_denies ---

test('total_denies returns correct description', function () {
    $challenge = Challenge::factory()->create(['code' => 'total_denies']);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 20,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Dapatkan 20 total deny');
});

// --- total_heal with number formatting ---

test('total_heal formats large numbers', function () {
    $challenge = Challenge::factory()->create(['code' => 'total_heal']);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 10000,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Pulihkan 10.000 HP');
});

// --- last_hits ---

test('last_hits returns correct description', function () {
    $challenge = Challenge::factory()->create(['code' => 'last_hits']);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 60,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Dapatkan 60 last hit dalam satu pertandingan');
});

// --- zero_death_win ---

test('zero_death_win returns correct description', function () {
    $challenge = Challenge::factory()->create(['code' => 'zero_death_win']);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 1,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan pertandingan tanpa mati');
});

// --- fast_win ---

test('fast_win returns correct description', function () {
    $challenge = Challenge::factory()->create(['code' => 'fast_win']);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 25,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Menangkan pertandingan dalam waktu kurang dari 25 menit');
});

// --- Unknown code fallback ---

test('unknown code falls back to challenge description', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'unknown_challenge',
        'description' => 'Complete {requirement} objectives',
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'challenge_id' => $challenge->id,
        'current_requirement' => 5,
    ]);

    $service = app(ChallengeDescriptionService::class);
    $description = $service->describe($destinationChallenge);

    expect($description)->toBe('Complete 5 objectives');
});
