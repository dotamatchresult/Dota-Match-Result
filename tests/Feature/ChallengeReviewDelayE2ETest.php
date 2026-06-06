<?php

use App\Enums\DestinationType;
use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\DailyChallenge\ChallengeReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = Destination::where('code', DestinationType::WhatsApp->value)->firstOrFail();
    $this->reviewService = app(ChallengeReviewService::class);
});

// ──────────────────────────────────────────────────────
// Delayed Review — Blocked
// ──────────────────────────────────────────────────────

test('review blocked when pending parse match exists older than 1 hour', function () {
    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'configuration' => ['metric' => 'kills'],
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

    // Create a match that blocks review
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    $result = $this->reviewService->review();

    // reviewed counts ALL active challenges for today (not just processed ones)
    expect($result['reviewed'])->toBe(1);
    expect($result['deferred'])->toBe(1);
    expect($result['incremented'])->toBe(0);

    // Challenge not incremented (review was blocked)
    $dc->refresh();
    expect($dc->current_requirement)->toBe(30);
    expect($dc->failed_days)->toBe(0);

    // review_delayed notification created
    $notification = ChallengeNotification::query()
        ->where('type', 'review_delayed')
        ->where('status', 'pending')
        ->first();
    expect($notification)->not->toBeNull();
    expect($notification->payload['destination_id'])->toBe($this->destination->id);
});

test('duplicate review_delayed notifications not created', function () {
    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'configuration' => ['metric' => 'kills'],
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

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    // Run review twice
    $this->reviewService->review();
    $this->reviewService->review();

    // Only one review_delayed notification
    $count = ChallengeNotification::query()
        ->where('type', 'review_delayed')
        ->count();
    expect($count)->toBe(1);
});

// ──────────────────────────────────────────────────────
// Resolution Path
// ──────────────────────────────────────────────────────

test('deferred review executes after match becomes parsed', function () {
    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'configuration' => ['metric' => 'kills'],
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

    // Create a match that was blocking but is now resolved
    $match = DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'parsed', // Resolved
    ]);

    // Create a review_delayed notification
    ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [(int) $match->match_id],
        ],
    ]);

    artisan('challenges:process-deferred-reviews')
        ->assertSuccessful();

    // Notification should be marked as sent
    $notification = ChallengeNotification::query()
        ->where('type', 'review_delayed')
        ->first();
    expect($notification->status)->toBe('sent');

    // Challenge should have been reviewed
    $dc->refresh();
    expect($dc->current_requirement)->toBe(40);
    expect($dc->failed_days)->toBe(1);
});

// ──────────────────────────────────────────────────────
// Force Review
// ──────────────────────────────────────────────────────

test('force review executes after delay exceeds threshold', function () {
    // Freeze time at 23:00 so subHours(13) = 10:00 today (within date range)
    $this->travelTo(now()->setTime(23, 0, 0));

    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'configuration' => ['metric' => 'kills'],
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

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(15),
        'finished_at' => now()->subHours(14),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    // Create a review_delayed notification from 13 hours ago (10:00 today)
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

    $result = $this->reviewService->review();

    expect($result['forced'])->toBe(1);

    // Challenge incremented (forced review proceeded)
    $dc->refresh();
    expect($dc->current_requirement)->toBe(40);
    expect($dc->failed_days)->toBe(1);

    // review_forced events created
    $forcedEvent = ChallengeEvent::query()
        ->where('type', 'review_forced')
        ->first();
    expect($forcedEvent)->not->toBeNull();
});

// ──────────────────────────────────────────────────────
// No Recap When Blocked
// ──────────────────────────────────────────────────────

test('no recap notification created when review is blocked', function () {
    $member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'configuration' => ['metric' => 'kills'],
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

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    $result = $this->reviewService->review();

    expect($result['recap_destinations'])->toBe(0);

    $recap = ChallengeNotification::query()
        ->where('type', 'recap')
        ->first();
    expect($recap)->toBeNull();
});
