<?php

use App\Enums\DestinationType;
use App\Models\Challenge;
use App\Models\ChallengeEvent;
use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\DailyChallenge\ChallengeProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure the whatsapp destination exists (unique code constraint)
    $this->destination = Destination::query()->firstOrCreate(
        ['code' => Destination::CODE_WHATSAPP],
        ['name' => 'WhatsApp', 'target' => '628123456789']
    );
});

// --- Progress Persisted ---

test('progress is persisted when a qualifying match is processed', function () {
    $heroId = 14;

    $member = Member::factory()->create([
        'steam_id' => '76561197960265729',
        'destination' => DestinationType::WhatsApp,
    ]);
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => $heroId],
        'base_requirement' => 2,
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'current_requirement' => 2,
        'current_progress' => 0,
        'progress_data' => null,
    ]);

    $dotaMatch = DotaMatch::create([
        'match_id' => '9000000001',
        'match_data' => [
            'radiant_win' => true,
            'players' => [
                [
                    'account_id' => $accountId,
                    'player_slot' => 0,
                    'hero_id' => $heroId,
                    'kills' => 5,
                    'deaths' => 2,
                    'assists' => 10,
                ],
            ],
        ],
        'members' => [$member->id],
    ]);

    $service = app(ChallengeProgressService::class);
    $service->processMatch($dotaMatch);

    $destinationChallenge->refresh();

    expect($destinationChallenge->current_progress)->toBe(1);
    expect($destinationChallenge->progress_data['contributors'][$member->id])->toBe(1);
    expect($destinationChallenge->progress_data['matches'])->toContain((int) $dotaMatch->match_id);
});

// --- Progress Event Created ---

test('progress event is created when progress is applied', function () {
    $heroId = 14;

    $member = Member::factory()->create([
        'steam_id' => '76561197960265730',
        'destination' => DestinationType::WhatsApp,
    ]);
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => $heroId],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'current_progress' => 0,
    ]);

    $dotaMatch = DotaMatch::create([
        'match_id' => '9000000002',
        'match_data' => [
            'radiant_win' => true,
            'players' => [
                [
                    'account_id' => $accountId,
                    'player_slot' => 0,
                    'hero_id' => $heroId,
                    'kills' => 3,
                    'deaths' => 1,
                    'assists' => 5,
                ],
            ],
        ],
        'members' => [$member->id],
    ]);

    $service = app(ChallengeProgressService::class);
    $service->processMatch($dotaMatch);

    $event = ChallengeEvent::query()
        ->where('destination_challenge_id', $destinationChallenge->id)
        ->where('match_id', $dotaMatch->id)
        ->where('type', 'progress')
        ->first();

    expect($event)->not->toBeNull()
        ->value_before->toBe(0)
        ->value_after->toBe(1);
});

// --- Completion Detected ---

test('completion is detected when progress meets requirement', function () {
    $heroId = 14;

    $member = Member::factory()->create([
        'steam_id' => '76561197960265731',
        'destination' => DestinationType::WhatsApp,
    ]);
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => $heroId],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'current_requirement' => 1, // Only 1 win needed
        'current_progress' => 0,
    ]);

    $dotaMatch = DotaMatch::create([
        'match_id' => '9000000003',
        'match_data' => [
            'radiant_win' => true,
            'players' => [
                [
                    'account_id' => $accountId,
                    'player_slot' => 0,
                    'hero_id' => $heroId,
                    'kills' => 5,
                    'deaths' => 2,
                    'assists' => 10,
                ],
            ],
        ],
        'members' => [$member->id],
    ]);

    $service = app(ChallengeProgressService::class);
    $service->processMatch($dotaMatch);

    $destinationChallenge->refresh();

    expect($destinationChallenge->status)->toBe('completed');
    expect($destinationChallenge->completed_at)->not->toBeNull();

    // Assert completed event created
    $completedEvent = ChallengeEvent::query()
        ->where('destination_challenge_id', $destinationChallenge->id)
        ->where('type', 'completed')
        ->first();

    expect($completedEvent)->not->toBeNull();
});

// --- Completion Notification Queued ---

test('completion notification is queued when challenge is completed', function () {
    $heroId = 14;

    $member = Member::factory()->create([
        'steam_id' => '76561197960265732',
        'destination' => DestinationType::WhatsApp,
    ]);
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => $heroId],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'current_requirement' => 1,
        'current_progress' => 0,
    ]);

    $dotaMatch = DotaMatch::create([
        'match_id' => '9000000004',
        'match_data' => [
            'radiant_win' => true,
            'players' => [
                [
                    'account_id' => $accountId,
                    'player_slot' => 0,
                    'hero_id' => $heroId,
                    'kills' => 5,
                    'deaths' => 2,
                    'assists' => 10,
                ],
            ],
        ],
        'members' => [$member->id],
    ]);

    $service = app(ChallengeProgressService::class);
    $service->processMatch($dotaMatch);

    $notification = ChallengeNotification::query()
        ->where('destination_challenge_id', $destinationChallenge->id)
        ->where('type', 'completed')
        ->where('status', 'pending')
        ->first();

    expect($notification)->not->toBeNull();
});

// --- Duplicate Processing Skipped ---

test('duplicate processing is skipped', function () {
    $heroId = 14;

    $member = Member::factory()->create([
        'steam_id' => '76561197960265733',
        'destination' => DestinationType::WhatsApp,
    ]);
    $accountId = Member::convertSteamIdToAccountId($member->steam_id);

    $challenge = Challenge::factory()->create([
        'code' => 'hero_win',
        'configuration' => ['hero_id' => $heroId],
    ]);

    $destinationChallenge = DestinationChallenge::factory()->create([
        'destination_id' => $this->destination->id,
        'challenge_id' => $challenge->id,
        'status' => 'active',
        'current_requirement' => 3,
        'current_progress' => 0,
    ]);

    $dotaMatch = DotaMatch::create([
        'match_id' => '9000000005',
        'match_data' => [
            'radiant_win' => true,
            'players' => [
                [
                    'account_id' => $accountId,
                    'player_slot' => 0,
                    'hero_id' => $heroId,
                    'kills' => 5,
                    'deaths' => 2,
                    'assists' => 10,
                ],
            ],
        ],
        'members' => [$member->id],
    ]);

    $service = app(ChallengeProgressService::class);

    // First pass
    $service->processMatch($dotaMatch);
    $destinationChallenge->refresh();
    expect($destinationChallenge->current_progress)->toBe(1);

    // Second pass — should be skipped
    $service->processMatch($dotaMatch);
    $destinationChallenge->refresh();
    expect($destinationChallenge->current_progress)->toBe(1); // Still 1

    // Only one progress event
    $progressEvents = ChallengeEvent::query()
        ->where('destination_challenge_id', $destinationChallenge->id)
        ->where('match_id', $dotaMatch->id)
        ->where('type', 'progress')
        ->count();

    expect($progressEvents)->toBe(1);
});
