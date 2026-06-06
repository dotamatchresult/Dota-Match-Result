<?php

use App\Enums\DestinationType;
use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = Destination::where('code', DestinationType::WhatsApp->value)->firstOrFail();
});

// --- Deferred Review Success ---

test('deferred review succeeds after match resolution', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    // Create a match that was blocking but is now resolved (no longer pending)
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'parsed', // Now resolved
    ]);

    // Create a review_delayed notification
    $notification = ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);

    artisan('challenges:process-deferred-reviews')
        ->assertSuccessful()
        ->expectsOutputToContain('Resolved:');

    // Notification should be marked as sent
    $notification->refresh();
    expect($notification->status)->toBe('sent');

    // Challenge should have been reviewed
    $dc->refresh();
    expect($dc->current_requirement)->toBe(40);
    expect($dc->failed_days)->toBe(1);
});

// --- Still Blocked ---

test('deferred review remains blocked if matches still unresolved', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    // Create a match still pending (not resolved)
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    // Create a review_delayed notification
    $notification = ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);

    artisan('challenges:process-deferred-reviews')
        ->assertSuccessful()
        ->expectsOutputToContain('Still blocked:');

    // Notification should still be pending
    $notification->refresh();
    expect($notification->status)->toBe('pending');
});

// --- Force Review ---

test('force review triggered after threshold via deferred command', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    // Create an old review_delayed notification
    $notification = ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);
    $notification->created_at = now()->subHours(13);
    $notification->save();

    artisan('challenges:process-deferred-reviews')
        ->assertSuccessful()
        ->expectsOutputToContain('Forced:');

    // Notification should be marked as sent
    $notification->refresh();
    expect($notification->status)->toBe('sent');

    // Challenge should have been force-reviewed
    $dc->refresh();
    expect($dc->current_requirement)->toBe(40);
    expect($dc->failed_days)->toBe(1);

    // review_forced event should exist
    $forcedEvent = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'review_forced')
        ->first();

    expect($forcedEvent)->not->toBeNull();
});

// --- Empty Queue ---

test('command handles empty queue gracefully', function () {
    artisan('challenges:process-deferred-reviews')
        ->assertSuccessful()
        ->expectsOutputToContain('No deferred reviews to process.');
});

// --- Notification Marked Sent ---

test('review_delayed notification marked sent after processing', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'parsed',
    ]);

    $notification = ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);

    artisan('challenges:process-deferred-reviews')
        ->assertSuccessful();

    $notification->refresh();
    expect($notification->status)->toBe('sent');
});
