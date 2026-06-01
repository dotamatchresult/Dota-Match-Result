<?php

use App\Jobs\ProcessNewsNotification;
use App\Models\Setting;
use App\Models\SteamNews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('it fetches and stores new steam news', function () {
    Queue::fake();

    Setting::set('steam_news_enabled', true);
    Setting::set('steam_news_fetch_count', 5);
    Setting::set('minimum_match_date', now()->subDays(30)->toDateTimeString());

    // Mock Steam API response
    Http::fake([
        'api.steampowered.com/ISteamNews/GetNewsForApp/v0002/*' => Http::response([
            'appnews' => [
                'newsitems' => [
                    [
                        'gid' => '1234567890',
                        'title' => 'DotA 2 Update - Test',
                        'url' => 'https://example.com/news/1',
                        'author' => 'Test Author',
                        'contents' => '[p]This is a test patch.[/p]',
                        'feedname' => 'steam_community_announcements',
                        'date' => now()->subDays(1)->timestamp,
                        'tags' => ['patchnotes'],
                    ],
                    [
                        'gid' => '9876543210',
                        'title' => 'Non-official news',
                        'url' => 'https://example.com/news/2',
                        'author' => 'External',
                        'contents' => 'This should be filtered',
                        'feedname' => 'PCGamesN',
                        'date' => now()->subDays(1)->timestamp,
                        'tags' => [],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('steam:fetch-news')
        ->assertSuccessful();

    // Verify only official news was stored
    expect(SteamNews::count())->toBe(1);
    expect(SteamNews::where('gid', '1234567890')->exists())->toBeTrue();
    expect(SteamNews::where('gid', '9876543210')->exists())->toBeFalse();

    // Verify notification job was dispatched
    Queue::assertPushed(ProcessNewsNotification::class, 1);

    // Verify last check timestamp was updated
    expect(Setting::get('last_steam_news_check'))->not->toBeNull();
});

test('it filters news by minimum match date', function () {
    Queue::fake();

    $minimumDate = now()->subDays(3);
    Setting::set('steam_news_enabled', true);
    Setting::set('steam_news_fetch_count', 5);
    Setting::set('minimum_match_date', $minimumDate->toDateTimeString());

    Http::fake([
        'api.steampowered.com/ISteamNews/GetNewsForApp/v0002/*' => Http::response([
            'appnews' => [
                'newsitems' => [
                    [
                        'gid' => '111111',
                        'title' => 'Old News',
                        'url' => 'https://example.com/old',
                        'author' => 'Author',
                        'contents' => 'Old content',
                        'feedname' => 'steam_community_announcements',
                        'date' => now()->subDays(5)->timestamp, // Older than minimum
                        'tags' => [],
                    ],
                    [
                        'gid' => '222222',
                        'title' => 'Recent News',
                        'url' => 'https://example.com/recent',
                        'author' => 'Author',
                        'contents' => 'Recent content',
                        'feedname' => 'steam_community_announcements',
                        'date' => now()->subDays(1)->timestamp, // Newer than minimum
                        'tags' => [],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('steam:fetch-news')
        ->assertSuccessful();

    // Only recent news should be stored
    expect(SteamNews::count())->toBe(1);
    expect(SteamNews::where('gid', '222222')->exists())->toBeTrue();
    expect(SteamNews::where('gid', '111111')->exists())->toBeFalse();
});

test('it skips duplicate news', function () {
    Queue::fake();

    Setting::set('steam_news_enabled', true);
    Setting::set('steam_news_fetch_count', 5);

    // Create existing news
    SteamNews::factory()->create([
        'gid' => '1234567890',
        'title' => 'Existing News',
    ]);

    Http::fake([
        'api.steampowered.com/ISteamNews/GetNewsForApp/v0002/*' => Http::response([
            'appnews' => [
                'newsitems' => [
                    [
                        'gid' => '1234567890',
                        'title' => 'Same News',
                        'url' => 'https://example.com',
                        'author' => 'Author',
                        'contents' => 'Content',
                        'feedname' => 'steam_community_announcements',
                        'date' => now()->timestamp,
                        'tags' => [],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('steam:fetch-news')
        ->assertSuccessful();

    // Should still be only 1 record
    expect(SteamNews::count())->toBe(1);

    // No new notifications should be dispatched
    Queue::assertNotPushed(ProcessNewsNotification::class);
});

test('it fails when steam news is disabled', function () {
    Setting::set('steam_news_enabled', false);

    $this->artisan('steam:fetch-news')
        ->assertFailed();

    expect(SteamNews::count())->toBe(0);
});

test('it handles api failures gracefully', function () {
    Queue::fake();

    Setting::set('steam_news_enabled', true);

    Http::fake([
        'api.steampowered.com/ISteamNews/GetNewsForApp/v0002/*' => Http::response([], 500),
    ]);

    $this->artisan('steam:fetch-news')
        ->assertFailed();

    expect(SteamNews::count())->toBe(0);
    Queue::assertNothingPushed();
});
