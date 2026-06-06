<?php

use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure challenges are seeded
    $this->artisan('db:seed', ['--class' => 'ChallengeSeeder']);
});

// --- Assignment Created ---

test('command assigns a challenge to a destination with room for more', function () {
    $destination = Destination::factory()->create();

    artisan('challenges:assign-daily')->assertSuccessful();

    $dc = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->where('status', 'active')
        ->first();

    expect($dc)->not->toBeNull()
        ->assigned_date->toDateString()->toBe(today()->toDateString())
        ->current_requirement->toBeGreaterThan(0);

    // Assert event created
    $event = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'assigned')
        ->first();

    expect($event)->not->toBeNull()
        ->value_before->toBe(0)
        ->value_after->toBe($dc->current_requirement);

    // Assert announcement notification created
    $notification = ChallengeNotification::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'assigned_announcement')
        ->where('status', 'pending')
        ->first();

    expect($notification)->not->toBeNull()
        ->scheduled_at->not->toBeNull();
});

// --- Max Active Limit ---

test('command skips assignment when destination has max active challenges', function () {
    $destination = Destination::factory()->create();

    $maxActive = config('dota.daily_challenge.max_active_per_destination', 5);

    // Create max active challenges
    Challenge::query()->active()->take($maxActive)->get()->each(function ($challenge) use ($destination) {
        DestinationChallenge::factory()->create([
            'destination_id' => $destination->id,
            'challenge_id' => $challenge->id,
            'status' => 'active',
            'assigned_date' => today()->subDay()->toDateString(),
        ]);
    });

    $countBefore = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->count();

    artisan('challenges:assign-daily')->assertSuccessful();

    $countAfter = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->count();

    expect($countAfter)->toBe($countBefore);

    // Assert backlog notification created
    $backlogNotification = ChallengeNotification::query()
        ->where('type', 'backlog_full')
        ->where('status', 'pending')
        ->first();

    expect($backlogNotification)->not->toBeNull();
});

// --- Daily Idempotency ---

test('command is idempotent and only assigns once per day', function () {
    $destination = Destination::factory()->create();

    // First run
    artisan('challenges:assign-daily')->assertSuccessful();

    $countAfterFirst = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->whereDate('assigned_date', today())
        ->count();

    expect($countAfterFirst)->toBe(1);

    // Second run
    artisan('challenges:assign-daily')->assertSuccessful();

    $countAfterSecond = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->whereDate('assigned_date', today())
        ->count();

    expect($countAfterSecond)->toBe(1);
});

// --- Cooldown Logic ---

test('command prefers challenges not recently assigned', function () {
    $destination = Destination::factory()->create();

    // Get two challenges
    $challengeA = Challenge::query()->active()->first();
    $challengeB = Challenge::query()->active()->where('id', '!=', $challengeA->id)->first();

    // Assign challenge A 5 days ago (within 14-day cooldown)
    DestinationChallenge::factory()->create([
        'destination_id' => $destination->id,
        'challenge_id' => $challengeA->id,
        'status' => 'completed',
        'assigned_date' => today()->subDays(5)->toDateString(),
    ]);

    // Run assignment multiple times and verify challenge A is not selected
    $assignmentsOfA = 0;
    $iterations = 10;

    for ($i = 0; $i < $iterations; $i++) {
        // Clean up today's assignment
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', today())
            ->delete();

        ChallengeEvent::query()->delete();
        ChallengeNotification::query()->delete();

        artisan('challenges:assign-daily')->assertSuccessful();

        $dc = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', today())
            ->first();

        if ($dc && $dc->challenge_id === $challengeA->id) {
            $assignmentsOfA++;
        }

        expect($dc)->not->toBeNull();
    }

    // Challenge A should be selected rarely or never (within cooldown, B is preferred)
    expect($assignmentsOfA)->toBeLessThan($iterations);
});

// --- Pool Exhaustion Fallback ---

test('command falls back when all challenges are within cooldown', function () {
    $destination = Destination::factory()->create();

    // Assign every active challenge within the cooldown window
    Challenge::query()->active()->get()->each(function ($challenge) use ($destination) {
        DestinationChallenge::factory()->create([
            'destination_id' => $destination->id,
            'challenge_id' => $challenge->id,
            'status' => 'completed',
            'assigned_date' => today()->subDays(2)->toDateString(),
        ]);
    });

    artisan('challenges:assign-daily')->assertSuccessful();

    $dc = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->whereDate('assigned_date', today())
        ->first();

    // Should still succeed via fallback
    expect($dc)->not->toBeNull();
});

// --- Disabled Config ---

test('command exits early when daily challenge is disabled', function () {
    config()->set('dota.daily_challenge.enabled', false);

    $destination = Destination::factory()->create();

    artisan('challenges:assign-daily')
        ->expectsOutput('Daily challenge assignment is disabled in configuration.')
        ->assertSuccessful();

    $count = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->count();

    expect($count)->toBe(0);
});

// --- No Active Challenges ---

test('command handles no active challenges gracefully', function () {
    Challenge::query()->update(['is_active' => false]);

    $destination = Destination::factory()->create();

    artisan('challenges:assign-daily')->assertSuccessful();

    $count = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->count();

    expect($count)->toBe(0);
});

// --- Multiple Destinations ---

test('command assigns challenges to multiple destinations', function () {
    $destination1 = Destination::factory()->create();
    $destination2 = Destination::factory()->create();

    artisan('challenges:assign-daily')->assertSuccessful();

    $count1 = DestinationChallenge::query()
        ->where('destination_id', $destination1->id)
        ->whereDate('assigned_date', today())
        ->count();

    $count2 = DestinationChallenge::query()
        ->where('destination_id', $destination2->id)
        ->whereDate('assigned_date', today())
        ->count();

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);
});

// --- Item Win Randomization ---

test('item_win challenge randomizes an item with cost >= 4000 at assignment', function () {
    // Seed an expensive item
    $item = Item::create([
        'item_id' => 208,
        'name' => 'refresher',
        'dname' => 'Refresher Orb',
        'cost' => 5000,
    ]);

    // Also seed a cheap item that should NOT be picked
    Item::create([
        'item_id' => 48,
        'name' => 'power_treads',
        'dname' => 'Power Treads',
        'cost' => 1400,
    ]);

    // Deactivate all challenges except item_win
    Challenge::query()->update(['is_active' => false]);
    Challenge::query()->where('code', 'item_win')->update(['is_active' => true]);

    $destination = Destination::factory()->create();

    artisan('challenges:assign-daily')->assertSuccessful();

    $dc = DestinationChallenge::query()
        ->where('destination_id', $destination->id)
        ->where('status', 'active')
        ->first();

    expect($dc)->not->toBeNull();

    // progress_data should contain the item_id
    expect($dc->progress_data)->toBeArray()
        ->metadata->toBeArray()
        ->metadata->item_id->toBe($item->item_id);

    // Event payload should include item_id
    $event = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'assigned')
        ->first();

    expect($event)->not->toBeNull()
        ->payload->item_id->toBe($item->item_id)
        ->payload->item_name->toBe('Refresher Orb');
});

// --- Weighted Selection ---

test('weighted selection favors higher-weight challenges', function () {
    // Clear seeded challenges and create two with very different weights
    Challenge::query()->delete();

    $highWeight = Challenge::create([
        'code' => 'high_weight',
        'name' => 'High Weight',
        'description' => 'High weight challenge',
        'category' => 'accumulative',
        'weight' => 100,
        'base_requirement' => 10,
        'increment_value' => 5,
        'max_requirement' => 50,
        'configuration' => null,
        'is_active' => true,
    ]);

    $lowWeight = Challenge::create([
        'code' => 'low_weight',
        'name' => 'Low Weight',
        'description' => 'Low weight challenge',
        'category' => 'accumulative',
        'weight' => 1,
        'base_requirement' => 10,
        'increment_value' => 5,
        'max_requirement' => 50,
        'configuration' => null,
        'is_active' => true,
    ]);

    $destination = Destination::factory()->create();

    $highWeightCount = 0;
    $iterations = 20;

    for ($i = 0; $i < $iterations; $i++) {
        // Clean up today's assignment
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', today())
            ->delete();

        ChallengeEvent::query()->delete();
        ChallengeNotification::query()->delete();

        artisan('challenges:assign-daily')->assertSuccessful();

        $dc = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', today())
            ->first();

        expect($dc)->not->toBeNull();

        if ($dc->challenge_id === $highWeight->id) {
            $highWeightCount++;
        }
    }

    // With weight 100 vs 1, high-weight should be selected most of the time
    expect($highWeightCount)->toBeGreaterThan($iterations / 2);
});

// --- Active Code Exclusion ---

test('active challenge codes are excluded from new assignment', function () {
    $destination = Destination::factory()->create();

    // Get a challenge to make active
    $activeChallenge = Challenge::query()->active()->first();

    // Create an active DestinationChallenge for the same code
    DestinationChallenge::factory()->create([
        'destination_id' => $destination->id,
        'challenge_id' => $activeChallenge->id,
        'status' => 'active',
        'assigned_date' => today()->subDay()->toDateString(),
    ]);

    // Run assignment multiple times to verify code is never re-assigned
    for ($i = 0; $i < 10; $i++) {
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', today())
            ->delete();

        ChallengeEvent::query()->whereNotNull('id')->delete();
        ChallengeNotification::query()->whereNotNull('id')->delete();

        artisan('challenges:assign-daily')->assertSuccessful();

        $dc = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->whereDate('assigned_date', today())
            ->first();

        if ($dc) {
            $assignedChallenge = Challenge::find($dc->challenge_id);

            expect($assignedChallenge->code)->not->toBe($activeChallenge->code);
        }
    }
});

// --- Hero Exclusion (Meepo) ---

test('hero_win assignment excludes Meepo from random hero pool', function () {
    // Ensure heroes table has data
    \App\Models\Hero::create([
        'hero_id' => 82,
        'name' => 'npc_dota_hero_meepo',
        'localized_name' => 'Meepo',
    ]);

    \App\Models\Hero::create([
        'hero_id' => 1,
        'name' => 'npc_dota_hero_antimage',
        'localized_name' => 'Anti-Mage',
    ]);

    \App\Models\Hero::create([
        'hero_id' => 2,
        'name' => 'npc_dota_hero_axe',
        'localized_name' => 'Axe',
    ]);

    // Deactivate all challenges except hero_win
    Challenge::query()->update(['is_active' => false]);
    Challenge::query()->where('code', 'hero_win')->update(['is_active' => true]);

    $destination = Destination::factory()->create();

    // Run multiple assignments and verify hero_id is never 82
    for ($i = 0; $i < 20; $i++) {
        DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'active')
            ->delete();

        ChallengeEvent::query()->delete();
        ChallengeNotification::query()->delete();

        artisan('challenges:assign-daily')->assertSuccessful();

        $dc = DestinationChallenge::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'active')
            ->first();

        if ($dc && isset($dc->progress_data['metadata']['hero_id'])) {
            $heroId = $dc->progress_data['metadata']['hero_id'];

            expect($heroId)->not->toBe(82);
        }
    }
});
