<?php

use App\Enums\DestinationType;
use App\Jobs\RequestMatchParse;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('it dispatches parse request for eligible match older than 2 minutes', function () {
    Queue::fake();

    // Create members with appropriate destinations
    $whatsappMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    $telegramMembers = Member::factory()->count(3)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    // Create a LOSS match older than 2 minutes with members on Dire team that lost
    $match = DotaMatch::factory()->create([
        'created_at' => now()->subMinutes(3),
        'parse_status' => null,
        'match_data' => [
            'radiant_win' => true, // Radiant won, so Dire (members) lost
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => $allMembers->map(fn ($member, $index) => [
                'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                'player_slot' => 128 + $index, // Dire team (128+)
                'hero_id' => 50 + $index,
                'kills' => 3,
                'deaths' => 7,
                'assists' => 5,
                'hero_damage' => 12000,
                'tower_damage' => 1000,
                'hero_healing' => 500,
                'last_hits' => 150,
                'net_worth' => 10000,
                'gold_per_min' => 400,
                'xp_per_min' => 500,
            ])->toArray(),
        ],
        'members' => $allMembers->pluck('id')->toArray(),
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    // Verify parse request was dispatched
    Queue::assertPushed(RequestMatchParse::class, function ($job) use ($match) {
        return $job->dotaMatch->id === $match->id;
    });
});

test('it skips matches newer than 2 minutes', function () {
    Queue::fake();

    // Create members
    $whatsappMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    $telegramMembers = Member::factory()->count(3)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    // Create a match that is only 1 minute old (not eligible yet)
    DotaMatch::factory()->create([
        'created_at' => now()->subMinute(),
        'parse_status' => null,
        'match_data' => [
            'radiant_win' => true, // Members lost
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => $allMembers->map(fn ($member, $index) => [
                'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                'player_slot' => 128 + $index, // Dire team
                'hero_id' => 50 + $index,
                'kills' => 3,
                'deaths' => 7,
                'assists' => 5,
            ])->toArray(),
        ],
        'members' => $allMembers->pluck('id')->toArray(),
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    // Verify no parse requests were dispatched
    Queue::assertNothingPushed();
});

test('it skips matches that already have parse_status', function () {
    Queue::fake();

    $whatsappMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    $telegramMembers = Member::factory()->count(3)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    // Create a match with parse_status already set
    DotaMatch::factory()->create([
        'created_at' => now()->subMinutes(5),
        'parse_status' => 'parsing',
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => $allMembers->map(fn ($member, $index) => [
                'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                'player_slot' => 128 + $index,
                'hero_id' => 50 + $index,
                'kills' => 3,
                'deaths' => 7,
                'assists' => 5,
            ])->toArray(),
        ],
        'members' => $allMembers->pluck('id')->toArray(),
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    // Verify no parse requests were dispatched
    Queue::assertNothingPushed();
});

test('it skips matches that are not losses', function () {
    Queue::fake();

    $whatsappMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    $telegramMembers = Member::factory()->count(3)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    // Create a WON match (members are on Radiant, Radiant won)
    DotaMatch::factory()->create([
        'created_at' => now()->subMinutes(5),
        'parse_status' => null,
        'match_data' => [
            'radiant_win' => true, // Radiant won
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => $allMembers->map(fn ($member, $index) => [
                'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                'player_slot' => $index, // Radiant team (< 128)
                'hero_id' => 50 + $index,
                'kills' => 7,
                'deaths' => 3,
                'assists' => 10,
            ])->toArray(),
        ],
        'members' => $allMembers->pluck('id')->toArray(),
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    // Verify no parse requests were dispatched
    Queue::assertNothingPushed();
});

test('it skips matches with insufficient WhatsApp members', function () {
    Queue::fake();

    // Only 1 WhatsApp member (need 2+)
    $whatsappMembers = Member::factory()->count(1)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    $telegramMembers = Member::factory()->count(3)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    DotaMatch::factory()->create([
        'created_at' => now()->subMinutes(5),
        'parse_status' => null,
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => $allMembers->map(fn ($member, $index) => [
                'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                'player_slot' => 128 + $index, // Dire team (lost)
                'hero_id' => 50 + $index,
                'kills' => 3,
                'deaths' => 7,
                'assists' => 5,
            ])->toArray(),
        ],
        'members' => $allMembers->pluck('id')->toArray(),
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it skips matches with insufficient Telegram members', function () {
    Queue::fake();

    $whatsappMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    // Only 2 Telegram members (need 3+)
    $telegramMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    DotaMatch::factory()->create([
        'created_at' => now()->subMinutes(5),
        'parse_status' => null,
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => $allMembers->map(fn ($member, $index) => [
                'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                'player_slot' => 128 + $index, // Dire team (lost)
                'hero_id' => 50 + $index,
                'kills' => 3,
                'deaths' => 7,
                'assists' => 5,
            ])->toArray(),
        ],
        'members' => $allMembers->pluck('id')->toArray(),
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it handles multiple eligible matches', function () {
    Queue::fake();

    $whatsappMembers = Member::factory()->count(2)->create([
        'destination' => DestinationType::WhatsApp,
    ]);
    $telegramMembers = Member::factory()->count(3)->create([
        'destination' => DestinationType::Telegram,
    ]);

    $allMembers = $whatsappMembers->merge($telegramMembers);

    // Create 3 eligible matches
    $matches = [];
    for ($i = 0; $i < 3; $i++) {
        $matches[] = DotaMatch::factory()->create([
            'created_at' => now()->subMinutes(3 + $i),
            'parse_status' => null,
            'match_data' => [
                'radiant_win' => true,
                'game_mode' => 22,
                'duration' => 1800,
                'radiant_score' => 35,
                'dire_score' => 20,
                'players' => $allMembers->map(fn ($member, $index) => [
                    'account_id' => Member::convertSteamIdToAccountId($member->steam_id),
                    'player_slot' => 128 + $index,
                    'hero_id' => 50 + $index,
                    'kills' => 3,
                    'deaths' => 7,
                    'assists' => 5,
                ])->toArray(),
            ],
            'members' => $allMembers->pluck('id')->toArray(),
        ]);
    }

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    // Verify parse requests were dispatched for all 3 matches
    Queue::assertPushed(RequestMatchParse::class, 3);
});

test('it skips matches with no members', function () {
    Queue::fake();

    DotaMatch::factory()->create([
        'created_at' => now()->subMinutes(5),
        'parse_status' => null,
        'members' => [], // No members
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 22,
            'duration' => 1800,
            'radiant_score' => 35,
            'dire_score' => 20,
            'players' => [],
        ],
    ]);

    $this->artisan('matches:request-parses')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
