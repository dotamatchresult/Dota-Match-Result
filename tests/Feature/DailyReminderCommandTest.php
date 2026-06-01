<?php

use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Models\Reminder;
use App\Models\Setting;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

const DAILY_REMINDER_STEAM_ID = '76561198256821667';

beforeEach(function () {
    Setting::set('telegram_bot_token', 'test_token');
    Setting::set('telegram_bot_ai_token', 'test_ai_token');

    Destination::query()->updateOrCreate(
        ['code' => Destination::CODE_WHATSAPP],
        ['name' => 'WhatsApp', 'target' => '6285700000000'],
    );

    Destination::query()->updateOrCreate(
        ['code' => Destination::CODE_TELEGRAM],
        ['name' => 'Telegram', 'target' => '1187374141'],
    );

    Reminder::factory()->create([
        'steam_id' => DAILY_REMINDER_STEAM_ID,
        'max_matches' => 2,
    ]);
});

test('command exits early when no reminders configured', function () {
    Reminder::query()->delete();

    $this->artisan('matches:daily-reminder')
        ->expectsOutput('No reminders configured.')
        ->assertSuccessful();
});

test('command warns when member not found in database', function () {
    $this->artisan('matches:daily-reminder')
        ->expectsOutput('Member not found for steam_id: '.DAILY_REMINDER_STEAM_ID)
        ->assertSuccessful();
});

test('command sends message when member played exactly at the limit', function () {
    $member = Member::factory()->create([
        'steam_id' => DAILY_REMINDER_STEAM_ID,
        'name' => 'TestUser',
        'destination' => Destination::CODE_WHATSAPP,
    ]);

    DotaMatch::factory()->count(2)->create([
        'members' => [$member->id],
        'match_timestamp' => today(),
        'match_data' => ['duration' => 1800, 'players' => [], 'radiant_win' => true, 'game_mode' => 23, 'radiant_score' => 20, 'dire_score' => 15],
    ]);

    $fonnte = mock(FonnteService::class);
    $fonnte->shouldReceive('sendMessage')
        ->once()
        ->with('6285700000000', \Mockery::type('string'))
        ->andReturn(true);

    $telegram = mock(TelegramService::class);
    $telegram->shouldNotReceive('sendMessage');

    $this->artisan('matches:daily-reminder')->assertSuccessful();
});

test('command sends message when member exceeds daily limit', function () {
    $whatsappMember = Member::factory()->create([
        'steam_id' => DAILY_REMINDER_STEAM_ID,
        'name' => 'TestUser',
        'destination' => Destination::CODE_WHATSAPP,
    ]);

    Member::factory()->create([
        'steam_id' => DAILY_REMINDER_STEAM_ID,
        'name' => 'TestUser',
        'destination' => Destination::CODE_TELEGRAM,
    ]);

    DotaMatch::factory()->count(3)->create([
        'members' => [$whatsappMember->id],
        'match_timestamp' => today(),
        'match_data' => ['duration' => 1800, 'players' => [], 'radiant_win' => true, 'game_mode' => 23, 'radiant_score' => 20, 'dire_score' => 15],
    ]);

    $fonnte = mock(FonnteService::class);
    $fonnte->shouldReceive('sendMessage')
        ->once()
        ->with('6285700000000', \Mockery::type('string'))
        ->andReturn(true);

    $telegram = mock(TelegramService::class);
    $telegram->shouldReceive('sendMessage')
        ->once()
        ->with(\Mockery::type('string'), 'ai', '1187374141')
        ->andReturn(true);

    $this->artisan('matches:daily-reminder')->assertSuccessful();
});

test('command skips matches from previous days', function () {
    $member = Member::factory()->create([
        'steam_id' => DAILY_REMINDER_STEAM_ID,
        'name' => 'TestUser',
        'destination' => Destination::CODE_WHATSAPP,
    ]);

    DotaMatch::factory()->count(3)->create([
        'members' => [$member->id],
        'match_timestamp' => today()->subDay(),
        'match_data' => ['duration' => 1800, 'players' => [], 'radiant_win' => true, 'game_mode' => 23, 'radiant_score' => 20, 'dire_score' => 15],
    ]);

    $fonnte = mock(FonnteService::class);
    $fonnte->shouldNotReceive('sendMessage');

    $telegram = mock(TelegramService::class);
    $telegram->shouldNotReceive('sendMessage');

    $this->artisan('matches:daily-reminder')->assertSuccessful();
});

test('message contains player name, match count and duration', function () {
    $member = Member::factory()->create([
        'steam_id' => DAILY_REMINDER_STEAM_ID,
        'name' => 'Budi',
        'destination' => Destination::CODE_WHATSAPP,
    ]);

    // 4 matches × 1800s each = 7200s = 2 jam
    DotaMatch::factory()->count(4)->create([
        'members' => [$member->id],
        'match_timestamp' => today(),
        'match_data' => ['duration' => 1800, 'players' => [], 'radiant_win' => true, 'game_mode' => 23, 'radiant_score' => 20, 'dire_score' => 15],
    ]);

    $sentMessage = '';

    $fonnte = mock(FonnteService::class);
    $fonnte->shouldReceive('sendMessage')
        ->once()
        ->andReturnUsing(function (string $phone, string $message) use (&$sentMessage) {
            $sentMessage = $message;

            return true;
        });

    mock(TelegramService::class)->shouldReceive('sendMessage')->andReturn(true);

    $this->artisan('matches:daily-reminder')->assertSuccessful();

    expect($sentMessage)->toContain('Budi');
    expect($sentMessage)->toContain('4');
    expect($sentMessage)->toContain('jam');
});
