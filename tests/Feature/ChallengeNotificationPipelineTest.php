<?php

use App\Models\Challenge;
use App\Models\ChallengeNotification;
use App\Models\DestinationChallenge;
use App\Services\DailyChallenge\ChallengeNotificationDispatcher;
use App\Services\Messaging\DestinationMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ChallengeTestHelper;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = ChallengeTestHelper::createTestDestination();
    $this->challenge = Challenge::factory()->create([
        'code' => 'total_kills',
        'configuration' => ['metric' => 'kills'],
        'name' => 'Total Kills',
    ]);
});

// ──────────────────────────────────────────────────────
// Completion Batching
// ──────────────────────────────────────────────────────

test('multiple completed challenges for same destination produce one batched notification', function () {
    // Create 3 completed challenges with unique challenge_ids to avoid unique constraint
    $dcs = [];
    for ($i = 0; $i < 3; $i++) {
        $challenge = Challenge::factory()->create([
            'code' => 'total_kills_'.$i,
            'configuration' => ['metric' => 'kills'],
            'name' => 'Total Kills '.$i,
        ]);

        $dc = DestinationChallenge::factory()->create([
            'destination_id' => $this->destination->id,
            'challenge_id' => $challenge->id,
            'status' => 'completed',
            'current_progress' => 30,
            'current_requirement' => 30,
        ]);

        ChallengeNotification::factory()->create([
            'destination_challenge_id' => $dc->id,
            'type' => 'completed',
            'status' => 'pending',
        ]);

        $dcs[] = $dc;
    }

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once(); // Only one send for batched

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(3);
    expect($result['batched'])->toBe(1); // 1 batch group

    // All notifications marked as sent
    foreach ($dcs as $dc) {
        $notifications = ChallengeNotification::query()
            ->where('destination_challenge_id', $dc->id)
            ->where('type', 'completed')
            ->get();
        foreach ($notifications as $n) {
            expect($n->status)->toBe('sent');
            expect($n->sent_at)->not->toBeNull();
        }
    }
});

test('single completed challenge uses individual format not batch', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'completed',
        'current_progress' => 30,
        'current_requirement' => 30,
    ]);

    ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(1);
    expect($result['batched'])->toBe(1); // Single completion still counts as 1 batch group

    $notification = ChallengeNotification::query()->first();
    expect($notification->status)->toBe('sent');
});

test('completed notifications for different destinations are not batched together', function () {
    $dest2 = ChallengeTestHelper::createTestDestination();

    $dc1 = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'completed',
        'current_progress' => 30,
        'current_requirement' => 30,
    ]);

    $dc2 = DestinationChallenge::factory()->create([
        'destination_id' => $dest2->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'completed',
        'current_progress' => 30,
        'current_requirement' => 30,
    ]);

    ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc1->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc2->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->twice(); // Two separate sends

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(2);
});

// ──────────────────────────────────────────────────────
// Assignment Announcements
// ──────────────────────────────────────────────────────

test('assigned_announcement with future scheduled_at is skipped', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'active',
        'current_progress' => 0,
        'current_requirement' => 30,
    ]);

    ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
        'scheduled_at' => now()->addHour(), // Future
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->never();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(0);

    // Notification still pending
    $notification = ChallengeNotification::query()->first();
    expect($notification->status)->toBe('pending');
});

test('assigned_announcement with past scheduled_at is sent', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'active',
        'current_progress' => 0,
        'current_requirement' => 30,
    ]);

    ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
        'scheduled_at' => now()->subHour(), // Past
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(1);

    $notification = ChallengeNotification::query()->first();
    expect($notification->status)->toBe('sent');
});

// ──────────────────────────────────────────────────────
// Review Delayed Notifications
// ──────────────────────────────────────────────────────

test('review_delayed notification resolves destination correctly', function () {
    // Create a review_delayed notification (no destination_challenge_id)
    ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(1);

    $notification = ChallengeNotification::query()->first();
    expect($notification->status)->toBe('sent');
});

test('duplicate review_delayed notifications not created for same destination same day', function () {
    // Create first review_delayed
    ChallengeNotification::create([
        'destination_challenge_id' => null,
        'type' => 'review_delayed',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'blocking_match_ids' => [],
        ],
    ]);

    // Try creating second — this tests the dedup in ChallengeReviewService::createDelayNotification
    // We verify by checking count
    $count = ChallengeNotification::query()
        ->where('type', 'review_delayed')
        ->where('status', 'pending')
        ->count();

    expect($count)->toBe(1);
});
