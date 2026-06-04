<?php

use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
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
