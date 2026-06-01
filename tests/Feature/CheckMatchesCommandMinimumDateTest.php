<?php

use App\Jobs\ProcessMatchNotification;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Models\Setting;
use App\Services\SteamApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('it filters matches by minimum match date', function () {
    Queue::fake();

    // Create a test member with created_at older than matches
    $member = Member::factory()->create([
        'steam_id' => '123456789',
        'name' => 'Test Player',
        'created_at' => now()->subDays(10), // Created 10 days ago, before any test matches
    ]);

    // Set minimum match date to 3 days ago
    $minimumDate = now()->subDays(3);
    Setting::set('minimum_match_date', $minimumDate->toDateTimeString());

    // Mock Steam API
    $steamApi = Mockery::mock(SteamApiService::class);

    // Prepare test matches - one old (5 days ago), one recent (1 day ago)
    $oldMatchTime = now()->subDays(5)->timestamp;
    $recentMatchTime = now()->subDays(1)->timestamp;

    $steamApi->shouldReceive('getMatchHistory')
        ->once()
        ->with($member->steam_id, config('dota.match_to_check', 10))
        ->andReturn([
            [
                'match_id' => '111111',
                'start_time' => $oldMatchTime,
            ],
            [
                'match_id' => '222222',
                'start_time' => $recentMatchTime,
            ],
        ]);

    // Only the recent match should be processed
    $steamApi->shouldReceive('getMatchDetails')
        ->once()
        ->with('222222')
        ->andReturn([
            'match_id' => '222222',
            'start_time' => $recentMatchTime,
            'radiant_win' => true,
            'players' => [
                [
                    'account_id' => 123456789,
                    'player_slot' => 0,
                    'hero_id' => 1,
                    'kills' => 10,
                    'deaths' => 5,
                    'assists' => 15,
                ],
            ],
        ]);

    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $this->app->instance(SteamApiService::class, $steamApi);

    // Run the command
    $this->artisan('matches:check')
        ->assertSuccessful();

    // Verify only the recent match was created
    expect(DotaMatch::count())->toBe(1);
    expect(DotaMatch::where('match_id', '222222')->exists())->toBeTrue();
    expect(DotaMatch::where('match_id', '111111')->exists())->toBeFalse();

    // Verify job was dispatched for the recent match
    Queue::assertPushed(ProcessMatchNotification::class, 1);
});

test('it fetches all matches when minimum match date is not set', function () {
    Queue::fake();

    // Create member with created_at older than the test match
    $member = Member::factory()->create([
        'steam_id' => '123456789',
        'name' => 'Test Player',
        'created_at' => now()->subDays(60), // Created 60 days ago, before the 30-day old match
    ]);

    // Ensure minimum_match_date is null
    Setting::set('minimum_match_date', null);

    $steamApi = Mockery::mock(SteamApiService::class);

    $oldMatchTime = now()->subDays(30)->timestamp;

    $steamApi->shouldReceive('getMatchHistory')
        ->once()
        ->with($member->steam_id, config('dota.match_to_check', 10))
        ->andReturn([
            [
                'match_id' => '333333',
                'start_time' => $oldMatchTime,
            ],
        ]);

    $steamApi->shouldReceive('getMatchDetails')
        ->once()
        ->with('333333')
        ->andReturn([
            'match_id' => '333333',
            'start_time' => $oldMatchTime,
            'radiant_win' => false,
            'players' => [
                [
                    'account_id' => 123456789,
                    'player_slot' => 128,
                    'hero_id' => 2,
                    'kills' => 5,
                    'deaths' => 10,
                    'assists' => 8,
                ],
            ],
        ]);

    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $this->app->instance(SteamApiService::class, $steamApi);

    $this->artisan('matches:check')
        ->assertSuccessful();

    // Verify the old match was processed
    expect(DotaMatch::count())->toBe(1);
    expect(DotaMatch::where('match_id', '333333')->exists())->toBeTrue();

    Queue::assertPushed(ProcessMatchNotification::class, 1);
});
