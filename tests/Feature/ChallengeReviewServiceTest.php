<?php

use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
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
