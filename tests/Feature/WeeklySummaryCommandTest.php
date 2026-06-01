<?php

use App\Enums\DestinationType;
use App\Models\DotaMatch;
use App\Models\Hero;
use App\Models\Member;
use App\Models\Setting;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create heroes for testing
    Hero::factory()->create([
        'hero_id' => 52,
        'name' => 'npc_dota_hero_queenofpain',
        'localized_name' => 'Queen of Pain',
    ]);

    Hero::factory()->create([
        'hero_id' => 5,
        'name' => 'npc_dota_hero_lion',
        'localized_name' => 'Lion',
    ]);

    // Set default settings
    Setting::set('weekly_summary_enabled', true);
    Setting::set('weekly_summary_day', 1);
    Setting::set('weekly_summary_time', '10:00');
    Setting::set('fonnte_phone_number', '628123456789');
    Setting::set('telegram_group_id', '-1001234567890');
    Setting::set('telegram_bot_token', 'test_token');
    Setting::set('telegram_bot_ai_token', 'test_ai_token');
});

test('command runs successfully when enabled', function () {
    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});

test('command skips when weekly_summary_enabled is false', function () {
    Setting::set('weekly_summary_enabled', false);

    $this->artisan('matches:weekly-summary')
        ->expectsOutput('Weekly summary is disabled. Skipping...')
        ->assertSuccessful();
});

test('command skips when no matches found', function () {
    // No matches created
    $this->artisan('matches:weekly-summary')
        ->expectsOutputToContain('No matches found for the week')
        ->assertSuccessful();
});

test('command generates WhatsApp summary for WhatsApp members only', function () {
    // Create WhatsApp member
    $whatsappMember = Member::factory()->create([
        'steam_id' => '76561197960265728',
        'name' => 'WhatsApp User',
        'destination' => DestinationType::WhatsApp,
    ]);

    // Create Telegram member
    $telegramMember = Member::factory()->create([
        'steam_id' => '76561197960265729',
        'name' => 'Telegram User',
        'destination' => DestinationType::Telegram,
    ]);

    // Create matches for previous week
    $lastMonday = now()->subWeek()->startOfWeek();

    $match = DotaMatch::factory()->create([
        'match_id' => '8651445707',
        'match_timestamp' => $lastMonday->copy()->addDays(2),
        'members' => [$whatsappMember->id, $telegramMember->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 37,
            'dire_score' => 28,
            'players' => [
                [
                    'account_id' => 0, // WhatsApp member
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 11,
                    'deaths' => 8,
                    'assists' => 18,
                    'hero_damage' => 31886,
                    'tower_damage' => 1755,
                    'hero_healing' => 0,
                    'last_hits' => 120,
                    'net_worth' => 28331,
                    'gold_per_min' => 1085,
                    'xp_per_min' => 2153,
                ],
                [
                    'account_id' => 1, // Telegram member
                    'player_slot' => 1,
                    'hero_id' => 5,
                    'kills' => 7,
                    'deaths' => 6,
                    'assists' => 24,
                    'hero_damage' => 28566,
                    'tower_damage' => 229,
                    'hero_healing' => 7279,
                    'last_hits' => 37,
                    'net_worth' => 25176,
                    'gold_per_min' => 856,
                    'xp_per_min' => 1902,
                ],
            ],
        ],
    ]);

    // Mock services
    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')
        ->times(3) // 3 messages for WhatsApp
        ->andReturn(true);

    $telegramMock = mock(TelegramService::class);
    $telegramMock->shouldReceive('sendMessage')
        ->times(3)
        ->andReturn(true);

    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});

test('message contains Indonesian keywords', function () {
    $whatsappMember = Member::factory()->create([
        'steam_id' => '76561197960265728',
        'name' => 'Test User',
        'destination' => DestinationType::WhatsApp,
    ]);

    $lastMonday = now()->subWeek()->startOfWeek();

    DotaMatch::factory()->create([
        'match_timestamp' => $lastMonday->copy()->addDays(2),
        'members' => [$whatsappMember->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 37,
            'dire_score' => 28,
            'players' => [
                [
                    'account_id' => 0,
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 11,
                    'deaths' => 8,
                    'assists' => 18,
                    'hero_damage' => 31886,
                    'tower_damage' => 1755,
                    'hero_healing' => 0,
                    'last_hits' => 120,
                    'net_worth' => 28331,
                    'gold_per_min' => 1085,
                    'xp_per_min' => 2153,
                ],
            ],
        ],
    ]);

    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) {
            return str_contains($message, 'RINGKASAN MINGGUAN')
                || str_contains($message, 'PENGHARGAAN')
                || str_contains($message, 'RINGKASAN INDIVIDU');
        })
        ->andReturn(true);

    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});

test('Fantasy Score calculation works correctly', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561197960265728',
        'name' => 'Test Player',
        'destination' => DestinationType::WhatsApp,
    ]);

    $lastMonday = now()->subWeek()->startOfWeek();

    // Create 2 matches for minimum requirement
    for ($i = 0; $i < 2; $i++) {
        DotaMatch::factory()->create([
            'match_timestamp' => $lastMonday->copy()->addDays($i + 1),
            'members' => [$member->id],
            'notified_at' => now(),
            'match_data' => [
                'radiant_win' => true,
                'game_mode' => 23,
                'duration' => 1440,
                'radiant_score' => 37,
                'dire_score' => 28,
                'players' => [
                    [
                        'account_id' => 0,
                        'player_slot' => 0,
                        'hero_id' => 52,
                        'kills' => 15,
                        'deaths' => 3,
                        'assists' => 20,
                        'hero_damage' => 50000,
                        'tower_damage' => 10000,
                        'hero_healing' => 0,
                        'last_hits' => 200,
                        'net_worth' => 35000,
                        'gold_per_min' => 800,
                        'xp_per_min' => 2000,
                    ],
                ],
            ],
        ]);
    }

    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) {
            // Check for Fantasy Score in awards message
            return str_contains($message, 'Fantasy Score:');
        })
        ->andReturn(true);

    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});

test('members with less than 2 matches excluded from awards', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561197960265728',
        'name' => 'Solo Player',
        'destination' => DestinationType::WhatsApp,
    ]);

    $lastMonday = now()->subWeek()->startOfWeek();

    // Create only 1 match
    DotaMatch::factory()->create([
        'match_timestamp' => $lastMonday->copy()->addDays(2),
        'members' => [$member->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 37,
            'dire_score' => 28,
            'players' => [
                [
                    'account_id' => 0,
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 11,
                    'deaths' => 8,
                    'assists' => 18,
                    'hero_damage' => 31886,
                    'tower_damage' => 1755,
                    'hero_healing' => 0,
                    'last_hits' => 120,
                    'net_worth' => 28331,
                    'gold_per_min' => 1085,
                    'xp_per_min' => 2153,
                ],
            ],
        ],
    ]);

    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) {
            // Awards message should mention no qualified members
            if (str_contains($message, 'PENGHARGAAN')) {
                return str_contains($message, 'Tidak ada member yang memenuhi kriteria minimum');
            }

            return true;
        })
        ->andReturn(true);

    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});

test('date range calculation uses previous week', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561197960265728',
        'destination' => DestinationType::WhatsApp,
    ]);

    // Create match in current week (should not be included)
    DotaMatch::factory()->create([
        'match_timestamp' => now()->startOfWeek()->addDays(2),
        'members' => [$member->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 37,
            'dire_score' => 28,
            'players' => [
                [
                    'account_id' => 0,
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 10,
                    'deaths' => 5,
                    'assists' => 15,
                    'hero_damage' => 25000,
                    'tower_damage' => 1000,
                    'hero_healing' => 0,
                    'last_hits' => 100,
                    'net_worth' => 20000,
                    'gold_per_min' => 600,
                    'xp_per_min' => 1500,
                ],
            ],
        ],
    ]);

    // Create match in previous week (should be included)
    $lastMonday = now()->subWeek()->startOfWeek();
    DotaMatch::factory()->create([
        'match_timestamp' => $lastMonday->copy()->addDays(2),
        'members' => [$member->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 37,
            'dire_score' => 28,
            'players' => [
                [
                    'account_id' => 0,
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 10,
                    'deaths' => 5,
                    'assists' => 15,
                    'hero_damage' => 25000,
                    'tower_damage' => 1000,
                    'hero_healing' => 0,
                    'last_hits' => 100,
                    'net_worth' => 20000,
                    'gold_per_min' => 600,
                    'xp_per_min' => 1500,
                ],
            ],
        ],
    ]);

    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) {
            // Should show only 1 match (from previous week)
            if (str_contains($message, 'RINGKASAN MINGGUAN')) {
                return str_contains($message, 'Total Pertandingan: 1');
            }

            return true;
        })
        ->andReturn(true);

    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});

test('most picked hero includes win rate', function () {
    $member = Member::factory()->create([
        'steam_id' => '76561197960265728',
        'destination' => DestinationType::WhatsApp,
    ]);

    $lastMonday = now()->subWeek()->startOfWeek();

    // Create 2 matches with same hero
    DotaMatch::factory()->create([
        'match_timestamp' => $lastMonday->copy()->addDays(1),
        'members' => [$member->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => true,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 37,
            'dire_score' => 28,
            'players' => [
                [
                    'account_id' => 0,
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 10,
                    'deaths' => 5,
                    'assists' => 15,
                    'hero_damage' => 25000,
                    'tower_damage' => 1000,
                    'hero_healing' => 0,
                    'last_hits' => 100,
                    'net_worth' => 20000,
                    'gold_per_min' => 600,
                    'xp_per_min' => 1500,
                ],
            ],
        ],
    ]);

    DotaMatch::factory()->create([
        'match_timestamp' => $lastMonday->copy()->addDays(2),
        'members' => [$member->id],
        'notified_at' => now(),
        'match_data' => [
            'radiant_win' => false,
            'game_mode' => 23,
            'duration' => 1440,
            'radiant_score' => 28,
            'dire_score' => 37,
            'players' => [
                [
                    'account_id' => 0,
                    'player_slot' => 0,
                    'hero_id' => 52,
                    'kills' => 8,
                    'deaths' => 10,
                    'assists' => 12,
                    'hero_damage' => 20000,
                    'tower_damage' => 500,
                    'hero_healing' => 0,
                    'last_hits' => 80,
                    'net_worth' => 15000,
                    'gold_per_min' => 500,
                    'xp_per_min' => 1200,
                ],
            ],
        ],
    ]);

    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')
        ->withArgs(function ($phone, $message) {
            // Check for hero with win rate format
            if (str_contains($message, 'RINGKASAN MINGGUAN')) {
                return str_contains($message, 'Queen of Pain (2x, 50% WR)');
            }

            return true;
        })
        ->andReturn(true);

    $this->artisan('matches:weekly-summary')
        ->assertSuccessful();
});
