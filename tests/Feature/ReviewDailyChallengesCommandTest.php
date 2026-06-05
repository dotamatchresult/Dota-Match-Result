<?php

use App\Models\Challenge;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->destination = Destination::factory()->create([
        'code' => 'test_cmd_'.fake()->unique()->randomNumber(6, true),
    ]);
});

test('command runs and outputs summary', function () {
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

    artisan('challenges:review-daily')
        ->assertSuccessful()
        ->expectsOutputToContain('Reviewed:')
        ->expectsOutputToContain('Incremented:')
        ->expectsOutputToContain('Failed:')
        ->expectsOutputToContain('Recap destinations:');
});

test('command handles empty queue gracefully', function () {
    artisan('challenges:review-daily')
        ->assertSuccessful()
        ->expectsOutputToContain('Reviewed: 0')
        ->expectsOutputToContain('Incremented: 0')
        ->expectsOutputToContain('Failed: 0')
        ->expectsOutputToContain('Recap destinations: 0');
});

test('recap not created when all challenges completed', function () {
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

    artisan('challenges:review-daily')
        ->assertSuccessful()
        ->expectsOutputToContain('Recap destinations: 0');

    $notification = ChallengeNotification::query()
        ->where('type', 'recap')
        ->first();

    expect($notification)->toBeNull();
});
