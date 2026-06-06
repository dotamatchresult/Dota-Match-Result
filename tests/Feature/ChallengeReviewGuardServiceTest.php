<?php

use App\Enums\DestinationType;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\DailyChallenge\ChallengeReviewGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The migration seeds 'whatsapp' and 'telegram' destinations,
    // so we use the existing record rather than creating a new one.
    $this->destination = Destination::where('code', DestinationType::WhatsApp->value)->firstOrFail();

    $this->member = Member::factory()->create([
        'destination' => $this->destination->code,
    ]);

    $this->guardService = app(ChallengeReviewGuardService::class);
});

// --- Normal Review ---

test('review runs normally when no delayed matches exist', function () {
    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->allowed)->toBeTrue();
    expect($decision->blocked)->toBeFalse();
    expect($decision->forceReview)->toBeFalse();
    expect($decision->blockingMatches)->toBeEmpty();
});

test('review runs normally when destination has no members', function () {
    $emptyDest = Destination::where('code', DestinationType::Telegram->value)->firstOrFail();

    $decision = $this->guardService->canReviewDestination($emptyDest);

    expect($decision->allowed)->toBeTrue();
});

// --- Blocked Review ---

test('review is blocked when unresolved Steam match with pending parse exists', function () {
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => 'pending',
    ]);

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->allowed)->toBeFalse();
    expect($decision->blocked)->toBeTrue();
    expect($decision->forceReview)->toBeFalse();
    expect($decision->blockingMatches)->toHaveCount(1);
});

test('review not blocked when match is less than 1 hour old', function () {
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subMinutes(30),
        'finished_at' => now()->subMinutes(30),
        'match_data' => ['duration' => 1800],
        'members' => [$this->member->id],
        'parse_status' => 'pending',
    ]);

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->allowed)->toBeTrue();
});

test('review not blocked when parse_status is parsed', function () {
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => 'parsed',
    ]);

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->allowed)->toBeTrue();
});

test('review not blocked when parse_status is null', function () {
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => null,
    ]);

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->allowed)->toBeTrue();
});

// --- Force Review ---

test('force review after configured threshold exceeded', function () {
    // Create a match that blocks review
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(5),
        'finished_at' => now()->subHours(4),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => 'pending',
    ]);

    // Create a review_delayed notification from 13 hours ago (exceeds 12h threshold)
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

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->allowed)->toBeTrue();
    expect($decision->forceReview)->toBeTrue();
    expect($decision->blocked)->toBeFalse();
});

test('force review not triggered when delay notification is within threshold', function () {
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => 'pending',
    ]);

    // Delay notification only 5 hours old (within 12h threshold)
    $notification = ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);
    $notification->created_at = now()->subHours(5);
    $notification->save();

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->blocked)->toBeTrue();
    expect($decision->forceReview)->toBeFalse();
});

// --- Multiple Blocking Matches ---

test('multiple blocking matches returned in decision', function () {
    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => 'pending',
    ]);

    DotaMatch::create([
        'match_id' => (string) fake()->unique()->randomNumber(9, true),
        'match_timestamp' => now()->subHours(5),
        'finished_at' => now()->subHours(4),
        'match_data' => ['duration' => 3600],
        'members' => [$this->member->id],
        'parse_status' => 'pending',
    ]);

    $decision = $this->guardService->canReviewDestination($this->destination);

    expect($decision->blocked)->toBeTrue();
    expect($decision->blockingMatches)->toHaveCount(2);
});
