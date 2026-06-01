<?php

use App\Jobs\ProcessNewsNotification;
use App\Models\Setting;
use App\Models\SteamNews;
use App\Services\GitHubService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Helpers
 */
function makeCommit(string $sha, string $message): array
{
    return [
        'sha' => $sha,
        'commit' => [
            'message' => $message,
            'committer' => ['date' => now()->toIso8601String()],
        ],
    ];
}

/**
 * FetchGitHubCommitsCommand tests
 */
test('first run processes all commits and creates steam news when threshold met', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('aaa111', '6644 | 124 files | M Protobufs/demo.proto'),
            makeCommit('bbb222', '6638 | 9 files | M game/core/pak01_dir.txt'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    // SHA updated to latest (index 0)
    expect(Setting::get('steamdb_dota2_commit_sha'))->toBe('aaa111');

    // 124 + 9 = 133 files → above default threshold of 10
    $news = SteamNews::where('feedname', 'github')->first();
    expect($news)->not->toBeNull();
    expect($news->gid)->toBe('aaa111');
    expect($news->author)->toBe('SteamDB');
    expect($news->title)->toContain('133');

    Queue::assertPushed(ProcessNewsNotification::class, 1);
});

test('returns success with no news when no commits in last hour', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    expect(SteamNews::where('feedname', 'github')->count())->toBe(0);
    Queue::assertNotPushed(ProcessNewsNotification::class);
});

test('sums file counts from all commits within the last hour', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('sha-c', '6720 | 17 files | M DumpSource2/schemas/...'),
            makeCommit('sha-b', '6718 | 24 files | M game/core/pak01_dir.txt'),
            makeCommit('sha-a', '6715 | 12 files | M Protobufs/demo.proto'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    // 17 + 24 + 12 = 53 files
    $news = SteamNews::where('feedname', 'github')->first();
    expect($news)->not->toBeNull();
    expect($news->title)->toContain('53');
    expect($news->gid)->toBe('sha-c');
    expect(Setting::get('steamdb_dota2_commit_sha'))->toBe('sha-c');

    Queue::assertPushed(ProcessNewsNotification::class, 1);
});

test('does not create steam news when total files below threshold', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('aaa111', '6644 | 3 files | M some/file.proto'),
            makeCommit('bbb222', '6638 | 2 files | M another/file.txt'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    // SHA still updated
    expect(Setting::get('steamdb_dota2_commit_sha'))->toBe('aaa111');

    // No SteamNews created — 5 files < 10
    expect(SteamNews::where('feedname', 'github')->count())->toBe(0);

    Queue::assertNotPushed(ProcessNewsNotification::class);
});

test('does not create duplicate steam news for the same latest sha', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    // Pre-create a SteamNews with the same gid that would be generated
    SteamNews::factory()->create(['gid' => 'aaa111', 'feedname' => 'github']);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('aaa111', '6644 | 50 files | M Protobufs/demo.proto'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    // Only one SteamNews record — no duplicate
    expect(SteamNews::where('feedname', 'github')->count())->toBe(1);

    Queue::assertNotPushed(ProcessNewsNotification::class);
});

test('returns failure and does not update sha when api fails', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);
    Setting::set('steamdb_dota2_commit_sha', 'previous-sha');

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response(null, 500),
    ]);

    $this->artisan('github:fetch-commits')->assertFailed();

    // SHA must not be updated on failure
    expect(Setting::get('steamdb_dota2_commit_sha'))->toBe('previous-sha');
    Queue::assertNotPushed(ProcessNewsNotification::class);
});

test('exits early when github commits disabled', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', false);

    Http::fake();

    $this->artisan('github:fetch-commits')->assertFailed();

    Http::assertNothingSent();
    Queue::assertNotPushed(ProcessNewsNotification::class);
});

/**
 * GitHubService unit tests
 */
test('extracts file count from steamdb commit message format', function () {
    $service = app(GitHubService::class);

    expect($service->extractFileCount('6644 | 124 files | M Protobufs/demo.proto'))->toBe(124);
    expect($service->extractFileCount('6638 | 9 files | M game/core/pak01_dir.txt'))->toBe(9);
    expect($service->extractFileCount('6718 | 17 files | M DumpSource2/schemas'))->toBe(17);
});

test('extracts file count from singular file message', function () {
    $service = app(GitHubService::class);

    expect($service->extractFileCount('6700 | 1 file | M some/file.go'))->toBe(1);
});

test('returns zero when no file count found in message', function () {
    $service = app(GitHubService::class);

    expect($service->extractFileCount('Initial commit'))->toBe(0);
    expect($service->extractFileCount(''))->toBe(0);
});

/**
 * Content scale tests
 */
test('creates correct content tier for minor update', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('sha-1', '100 | 15 files | M some/file'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    $news = SteamNews::where('feedname', 'github')->first();
    expect($news->contents)->toContain('perubahan kecil');
});

test('creates correct content tier for moderate update', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('sha-1', '100 | 50 files | M some/file'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    $news = SteamNews::where('feedname', 'github')->first();
    expect($news->contents)->toContain('Patch kecil');
});

test('creates correct content tier for significant update', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('sha-1', '100 | 150 files | M some/file'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    $news = SteamNews::where('feedname', 'github')->first();
    expect($news->contents)->toContain('Update besar');
});

test('creates correct content tier for massive update', function () {
    Queue::fake();
    Setting::set('github_commits_enabled', true);

    Http::fake([
        'api.github.com/repos/SteamDatabase/GameTracking-Dota2/commits*' => Http::response([
            makeCommit('sha-1', '100 | 350 files | M some/file'),
        ], 200),
    ]);

    $this->artisan('github:fetch-commits')->assertSuccessful();

    $news = SteamNews::where('feedname', 'github')->first();
    expect($news->contents)->toContain('MASIF');
});

/**
 * Schedule condition tests
 */
test('schedule runs github:fetch-commits when no steam news created this hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'github:fetch-commits'));

    expect($event)->not->toBeNull();
    expect($event->filtersPass(app()))->toBeTrue();
});

test('schedule skips github:fetch-commits when steam news was created this hour', function () {
    SteamNews::factory()->create([
        'feedname' => 'steam_community_announcements',
        'created_at' => now(),
    ]);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'github:fetch-commits'));

    expect($event)->not->toBeNull();
    expect($event->filtersPass(app()))->toBeFalse();
});

test('schedule runs github:fetch-commits when only github feedname records exist this hour', function () {
    SteamNews::factory()->create([
        'feedname' => 'github',
        'created_at' => now(),
    ]);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'github:fetch-commits'));

    expect($event)->not->toBeNull();
    expect($event->filtersPass(app()))->toBeTrue();
});

test('schedule runs github:fetch-commits when steam news was created in a previous hour', function () {
    SteamNews::factory()->create([
        'feedname' => 'steam_community_announcements',
        'created_at' => now()->subHour(),
    ]);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'github:fetch-commits'));

    expect($event)->not->toBeNull();
    expect($event->filtersPass(app()))->toBeTrue();
});
