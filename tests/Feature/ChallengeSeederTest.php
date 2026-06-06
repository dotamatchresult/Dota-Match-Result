<?php

use App\Models\Challenge;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

// --- Creates Missing Challenges ---

test('seeder creates missing challenges from catalog', function () {
    // Ensure no challenges exist
    expect(Challenge::count())->toBe(0);

    artisan('db:seed', ['--class' => 'ChallengeSeeder'])->assertSuccessful();

    expect(Challenge::count())->toBe(27);
});

// --- Updates Existing Challenges ---

test('seeder updates existing challenges on subsequent runs', function () {
    // Create a challenge with old data
    Challenge::create([
        'code' => 'total_kills',
        'name' => 'Old Name',
        'description' => 'Old description',
        'category' => 'accumulative',
        'weight' => 1,
        'base_requirement' => 1,
        'increment_value' => 1,
        'max_requirement' => 1,
        'configuration' => null,
        'is_active' => true,
    ]);

    artisan('db:seed', ['--class' => 'ChallengeSeeder'])->assertSuccessful();

    $challenge = Challenge::where('code', 'total_kills')->first();

    expect($challenge)->not->toBeNull();
    expect($challenge->name)->toBe('Total Kills'); // Updated from catalog
    expect($challenge->weight)->toBe(15); // Updated from catalog
    expect($challenge->base_requirement)->toBe(30); // Updated from catalog
});

// --- Idempotent ---

test('seeder is idempotent', function () {
    artisan('db:seed', ['--class' => 'ChallengeSeeder'])->assertSuccessful();

    $countAfterFirst = Challenge::count();

    artisan('db:seed', ['--class' => 'ChallengeSeeder'])->assertSuccessful();

    $countAfterSecond = Challenge::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
    expect($countAfterSecond)->toBe(27);
});

// --- Does Not Delete ---

test('seeder does not delete extra rows', function () {
    // Create an extra challenge not in the catalog
    Challenge::create([
        'code' => 'extra_challenge',
        'name' => 'Extra',
        'description' => 'Not in catalog',
        'category' => 'accumulative',
        'weight' => 5,
        'base_requirement' => 1,
        'increment_value' => 1,
        'max_requirement' => 3,
        'configuration' => null,
        'is_active' => true,
    ]);

    artisan('db:seed', ['--class' => 'ChallengeSeeder'])->assertSuccessful();

    // Extra challenge should still exist
    expect(Challenge::where('code', 'extra_challenge')->exists())->toBeTrue();
    // Total should be 28 (27 from catalog + 1 extra)
    expect(Challenge::count())->toBe(28);
});
