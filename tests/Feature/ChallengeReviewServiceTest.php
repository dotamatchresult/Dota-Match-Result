<?php

use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\DailyChallenge\ChallengeReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = Destination::factory()->create([
        'code' => 'test_review_'.fake()->unique()->randomNumber(6, true),
    ]);
});

// --- Increment Logic ---

test('accumulative challenge not completed increments requirement', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'base_requirement' => 30,
        'increment_value' => 10,
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

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['reviewed'])->toBe(1);
    expect($result['incremented'])->toBe(1);
    expect($result['failed'])->toBe(1);

    $dc->refresh();

    expect($dc->current_requirement)->toBe(40);
    expect($dc->failed_days)->toBe(1);
});

test('accumulative challenge at max is not incremented beyond cap', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'base_requirement' => 60,
        'increment_value' => 10,
        'max_requirement' => 60,
    ]);

    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 60,
        'current_progress' => 30,
        'failed_days' => 0,
    ]);

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['incremented'])->toBe(0);
    expect($result['failed'])->toBe(1);

    $dc->refresh();

    expect($dc->current_requirement)->toBe(60);
    expect($dc->failed_days)->toBe(1);
});

test('snapshot challenge not completed keeps requirement unchanged', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'last_hits',
        'increment_value' => 0,
        'base_requirement' => 60,
        'max_requirement' => 60,
    ]);

    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 60,
        'current_progress' => 30,
        'failed_days' => 0,
    ]);

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['incremented'])->toBe(0);
    expect($result['failed'])->toBe(1);

    $dc->refresh();

    expect($dc->current_requirement)->toBe(60);
    expect($dc->failed_days)->toBe(1);
});

test('completed challenge is skipped entirely', function () {
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
        'current_progress' => 30,
        'failed_days' => 0,
    ]);

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['incremented'])->toBe(0);
    expect($result['failed'])->toBe(0);

    $dc->refresh();

    expect($dc->current_requirement)->toBe(30);
    expect($dc->failed_days)->toBe(0);
});

// --- failed_days ---

test('failed_days incremented on each review', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 5,
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
        'failed_days' => 2,
    ]);

    $service = app(ChallengeReviewService::class);
    $service->review();

    $dc->refresh();

    expect($dc->failed_days)->toBe(3);
});

// --- Events ---

test('incremented event created with correct before and after values', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'base_requirement' => 30,
        'increment_value' => 10,
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

    $service = app(ChallengeReviewService::class);
    $service->review();

    $dc->refresh();

    $event = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'incremented')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->value_before)->toBe(30);
    expect($event->value_after)->toBe($dc->current_requirement);
    expect($event->value_after)->toBe(40);
});

test('failed_review event created with correct before and after values', function () {
    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'base_requirement' => 30,
        'increment_value' => 10,
        'max_requirement' => 60,
    ]);

    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 15,
        'failed_days' => 0,
    ]);

    $service = app(ChallengeReviewService::class);
    $service->review();

    $dc->refresh();

    $event = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'failed_review')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->value_before)->toBe(15);
    expect($event->value_after)->toBe(40);
});

// --- Recap Notifications ---

test('recap notification created for destination with failures', function () {
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

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['recap_destinations'])->toBe(1);

    $notification = ChallengeNotification::query()
        ->where('type', 'recap')
        ->where('status', 'pending')
        ->whereNull('destination_challenge_id')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->payload['destination_id'])->toBe($this->destination->id);
    expect($notification->payload['failed_count'])->toBe(1);
    expect($notification->payload['failed_challenges'])->toBeArray()->toHaveCount(1);
    expect($notification->payload['failed_challenges'][0])->toHaveKeys(['description', 'progress', 'requirement']);
    expect($notification->payload['failed_challenges'][0]['progress'])->toBe(10);
    expect($notification->payload['failed_challenges'][0]['requirement'])->toBe(40);
});

test('no recap when all challenges completed', function () {
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
        'current_progress' => 30,
        'failed_days' => 0,
    ]);

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['recap_destinations'])->toBe(0);

    $notification = ChallengeNotification::query()
        ->where('type', 'recap')
        ->first();

    expect($notification)->toBeNull();
});

test('multiple destinations each get separate recap only if they have failures', function () {
    $dest1 = $this->destination;
    $dest2 = Destination::factory()->create([
        'code' => 'test_review_2_'.fake()->unique()->randomNumber(6, true),
    ]);

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    // Destination 1: incomplete → should get recap
    DestinationChallenge::factory()->create([
        'destination_id' => $dest1->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    // Destination 2: completed → no recap
    DestinationChallenge::factory()->create([
        'destination_id' => $dest2->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 30,
        'failed_days' => 0,
    ]);

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['recap_destinations'])->toBe(1);

    $notifications = ChallengeNotification::query()
        ->where('type', 'recap')
        ->get();

    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->payload['destination_id'])->toBe($dest1->id);
});

// --- Idempotency ---

test('review is idempotent on same day', function () {
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

    $service = app(ChallengeReviewService::class);

    // First run
    $result1 = $service->review();
    expect($result1['failed'])->toBe(1);

    $dc->refresh();
    expect($dc->failed_days)->toBe(1);
    expect($dc->current_requirement)->toBe(40);

    $incrementedCount = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'incremented')
        ->count();

    $failedReviewCount = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'failed_review')
        ->count();

    expect($incrementedCount)->toBe(1);
    expect($failedReviewCount)->toBe(1);

    // Second run — should be a no-op
    $result2 = $service->review();
    expect($result2['failed'])->toBe(0);
    expect($result2['incremented'])->toBe(0);

    $dc->refresh();
    expect($dc->failed_days)->toBe(1);
    expect($dc->current_requirement)->toBe(40);

    $incrementedCountAfter = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'incremented')
        ->count();

    $failedReviewCountAfter = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'failed_review')
        ->count();

    expect($incrementedCountAfter)->toBe(1);
    expect($failedReviewCountAfter)->toBe(1);
});

// --- Guard Integration: Blocked Review ---

/**
 * Helper to create a destination with a valid DestinationType enum code
 * and a challenge assigned to it. Returns [destination, destinationChallenge].
 */
function createBlockableSetup(): array
{
    // Use the pre-seeded 'whatsapp' destination from the migration
    $dest = Destination::where('code', \App\Enums\DestinationType::WhatsApp->value)->firstOrFail();

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $dest->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    $member = Member::factory()->create([
        'destination' => $dest->code,
    ]);

    return [$dest, $dc, $member];
}

test('review creates review_delayed notification when blocked by pending match', function () {
    [$dest, $dc, $member] = createBlockableSetup();

    // Create a blocking match for this destination's member
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['deferred'])->toBe(1);
    expect($result['incremented'])->toBe(0);
    expect($result['failed'])->toBe(0);

    $notification = ChallengeNotification::query()
        ->where('type', 'review_delayed')
        ->where('status', 'pending')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->payload['destination_id'])->toBe($dest->id);
    expect($notification->payload['blocking_match_ids'])->toBeArray();
});

test('review skips increment and failed_days when blocked', function () {
    [$dest, $dc, $member] = createBlockableSetup();

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    $service = app(ChallengeReviewService::class);
    $service->review();

    $dc->refresh();

    // Should NOT have been incremented
    expect($dc->current_requirement)->toBe(30);
    expect($dc->failed_days)->toBe(0);

    // No recap notification should have been created for this destination
    $recapNotification = ChallengeNotification::query()
        ->where('type', 'recap')
        ->whereJsonContains('payload->destination_id', $dest->id)
        ->first();

    expect($recapNotification)->toBeNull();
});

// --- Guard Integration: Force Review ---

test('review creates review_forced events and proceeds when forceReview', function () {
    [$dest, $dc, $member] = createBlockableSetup();

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    $notification = ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $dest->id,
            'blocking_match_ids' => [],
        ],
    ]);
    $notification->created_at = now()->subHours(13);
    $notification->save();

    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['forced'])->toBe(1);
    expect($result['incremented'])->toBe(1);
    expect($result['failed'])->toBe(1);

    // review_forced event should have been created
    $forcedEvent = ChallengeEvent::query()
        ->where('destination_challenge_id', $dc->id)
        ->where('type', 'review_forced')
        ->first();

    expect($forcedEvent)->not->toBeNull();

    // Normal review should still have run
    $dc->refresh();
    expect($dc->current_requirement)->toBe(40);
    expect($dc->failed_days)->toBe(1);
});

// --- Guard Integration: Deduplication ---

test('duplicate review_delayed notifications are not created', function () {
    [$dest, $dc, $member] = createBlockableSetup();

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$member->id],
        'parse_status' => 'pending',
    ]);

    $service = app(ChallengeReviewService::class);

    // Run review twice (same day)
    $service->review();
    $service->review();

    $notifications = ChallengeNotification::query()
        ->where('type', 'review_delayed')
        ->whereDate('created_at', now()->toDateString())
        ->get();

    expect($notifications)->toHaveCount(1);
});

// --- Guard Integration: Recap still works ---

test('recap notification still created for non-blocked destinations', function () {
    $dest = Destination::where('code', \App\Enums\DestinationType::WhatsApp->value)->firstOrFail();

    $challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'increment_value' => 10,
        'base_requirement' => 30,
        'max_requirement' => 60,
    ]);

    DestinationChallenge::factory()->create([
        'destination_id' => $dest->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'assigned_date' => now()->toDateString(),
        'current_requirement' => 30,
        'current_progress' => 10,
        'failed_days' => 0,
    ]);

    // No blocking match — review should proceed normally
    $service = app(ChallengeReviewService::class);
    $result = $service->review();

    expect($result['deferred'])->toBe(0);
    expect($result['recap_destinations'])->toBe(1);

    $recapNotification = ChallengeNotification::query()
        ->where('type', 'recap')
        ->first();

    expect($recapNotification)->not->toBeNull();
});
