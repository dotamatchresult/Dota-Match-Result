<?php

use App\Models\Challenge;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Services\DailyChallenge\ChallengeNotificationDispatcher;
use App\Services\Messaging\DestinationMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = Destination::factory()->create([
        'code' => 'test_dest_'.fake()->unique()->randomNumber(6, true),
        'target' => '628123456789',
    ]);

    $this->challenge = Challenge::factory()->create([
        'code' => 'total_kills',
    ]);
});

/**
 * Create a pending notification with its destinationChallenge relationship loaded.
 */
function makePendingNotification(string $type, ?Destination $dest = null, ?DestinationChallenge $dc = null, array $attrs = []): ChallengeNotification
{
    $dest ??= Destination::factory()->create([
        'code' => 'helper_dest_'.fake()->unique()->randomNumber(6, true),
    ]);
    $dc ??= DestinationChallenge::factory()->create([
        'destination_id' => $dest->id,
        'challenge_id' => Challenge::factory()->create()->id,
    ]);

    $notification = ChallengeNotification::factory()->create(array_merge([
        'destination_challenge_id' => $dc->id,
        'type' => $type,
        'status' => 'pending',
    ], $attrs));

    $notification->load('destinationChallenge.destination', 'destinationChallenge.challenge');

    return $notification;
}

// --- Successful send ---

test('successful send marks notification as sent', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'current_requirement' => 30,
        'current_progress' => 0,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
        'scheduled_at' => now()->subHour(),
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(1);
    expect($result['failed'])->toBe(0);

    $notification->refresh();
    expect($notification->status)->toBe('sent');
    expect($notification->sent_at)->not->toBeNull();
});

// --- Failed send remains pending ---

test('failed send leaves notification as pending', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'current_requirement' => 30,
        'current_progress' => 0,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
        'scheduled_at' => now()->subHour(),
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('API error'));

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(0);
    expect($result['failed'])->toBe(1);

    $notification->refresh();
    expect($notification->status)->toBe('pending');
    expect($notification->sent_at)->toBeNull();
});

// --- Batching completed notifications ---

test('multiple completed notifications for same destination are batched', function () {
    $dc1 = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'completed',
        'current_requirement' => 30,
        'current_progress' => 30,
    ]);

    $dc2 = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => Challenge::factory()->create(['code' => 'fast_win'])->id,
        'status' => 'completed',
        'current_requirement' => 25,
        'current_progress' => 25,
    ]);

    $n1 = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc1->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $n2 = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc2->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['batched'])->toBe(1);
    expect($result['sent'])->toBe(2);

    $n1->refresh();
    $n2->refresh();
    expect($n1->status)->toBe('sent');
    expect($n2->status)->toBe('sent');
});

// --- Completed notifications for different destinations not batched together ---

test('completed notifications for different destinations are not batched together', function () {
    $dest2 = Destination::factory()->create([
        'code' => 'other_dest_'.fake()->unique()->randomNumber(6, true),
        'target' => '628987654321',
    ]);

    $dc1 = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'status' => 'completed',
        'current_requirement' => 30,
        'current_progress' => 30,
    ]);

    $dc2 = DestinationChallenge::factory()->create([
        'destination_id' => $dest2->id,
        'challenge_id' => Challenge::factory()->create(['code' => 'fast_win'])->id,
        'status' => 'completed',
        'current_requirement' => 25,
        'current_progress' => 25,
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
    $mock->shouldReceive('send')->twice(); // Two separate messages for two destinations

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['batched'])->toBe(2); // Two separate batches (one per destination)
    expect($result['sent'])->toBe(2);
});

// --- assigned_announcement respects scheduled_at ---

test('assigned announcement not sent if scheduled_at is in the future', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'current_requirement' => 30,
        'current_progress' => 0,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
        'scheduled_at' => now()->addHours(2),
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldNotReceive('send');

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    $notification->refresh();
    expect($notification->status)->toBe('pending');
    expect($result['processed'])->toBe(1);
    expect($result['sent'])->toBe(0);
});

// --- assigned_announcement sent if scheduled_at is in the past ---

test('assigned announcement sent if scheduled_at is in the past', function () {
    $dc = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'current_requirement' => 30,
        'current_progress' => 0,
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc->id,
        'type' => 'assigned_announcement',
        'status' => 'pending',
        'scheduled_at' => now()->subHour(),
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(1);
});

// --- backlog_full notification sent successfully ---

test('backlog full notification sent via destination from payload', function () {
    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => null,
        'type' => 'backlog_full',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $this->destination->id,
            'destination_code' => $this->destination->code,
        ],
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result['sent'])->toBe(1);

    $notification->refresh();
    expect($notification->status)->toBe('sent');
    expect($notification->sent_at)->not->toBeNull();
});

// --- Returns correct summary counts ---

test('dispatcher returns correct summary counts', function () {
    $dc1 = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $this->challenge->id,
        'current_requirement' => 30,
        'current_progress' => 30,
    ]);

    ChallengeNotification::factory()->create([
        'destination_challenge_id' => $dc1->id,
        'type' => 'completed',
        'status' => 'pending',
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    $dispatcher = app(ChallengeNotificationDispatcher::class);
    $result = $dispatcher->dispatch();

    expect($result)->toMatchArray([
        'processed' => 1,
        'sent' => 1,
        'batched' => 1,
        'failed' => 0,
    ]);
});
