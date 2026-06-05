<?php

use App\Models\ChallengeNotification;
use App\Services\Messaging\DestinationMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

// --- Command runs successfully ---

test('command processes pending notifications and outputs summary', function () {
    $destination = \App\Models\Destination::factory()->create([
        'code' => 'cmd_test_'.fake()->unique()->randomNumber(6, true),
        'target' => '628123456789',
    ]);

    $notification = ChallengeNotification::factory()->create([
        'destination_challenge_id' => null,
        'type' => 'backlog_full',
        'status' => 'pending',
        'payload' => [
            'destination_id' => $destination->id,
        ],
    ]);

    $mock = mock(DestinationMessageService::class);
    $mock->shouldReceive('send')->once();

    app()->instance(DestinationMessageService::class, $mock);

    artisan('challenges:send-notifications')
        ->assertSuccessful()
        ->expectsOutputToContain('Processed: 1')
        ->expectsOutputToContain('Sent: 1')
        ->expectsOutputToContain('Failed: 0');
});

// --- Command handles empty queue ---

test('command handles empty queue gracefully', function () {
    artisan('challenges:send-notifications')
        ->assertSuccessful()
        ->expectsOutputToContain('Processed: 0')
        ->expectsOutputToContain('Sent: 0')
        ->expectsOutputToContain('Failed: 0');
});
