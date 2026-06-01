<?php

use App\Jobs\ProcessNewsNotification;
use App\Models\Setting;
use App\Models\SteamNews;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it sends notifications to both whatsapp and telegram', function () {
    Setting::set('fonnte_phone_number', '628123456789');
    Setting::set('telegram_group_id', '-1001234567890');

    $steamNews = SteamNews::factory()->create([
        'gid' => '1234567890',
        'title' => 'Test News',
        'url' => 'https://example.com/news',
        'contents' => '[p]This is test content.[/p]',
        'notified_at' => null,
    ]);

    // Mock services
    $fonnteService = Mockery::mock(FonnteService::class);
    $fonnteService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(true);

    $telegramService = Mockery::mock(TelegramService::class);
    $telegramService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(true);

    $this->app->instance(FonnteService::class, $fonnteService);
    $this->app->instance(TelegramService::class, $telegramService);

    // Execute job
    $job = new ProcessNewsNotification($steamNews);
    $job->handle($fonnteService, $telegramService);

    // Verify notified_at was updated
    $steamNews->refresh();
    expect($steamNews->notified_at)->not->toBeNull();
});

test('it formats message correctly', function () {
    Setting::set('fonnte_phone_number', '628123456789');
    Setting::set('telegram_group_id', '-1001234567890');

    $steamNews = SteamNews::factory()->create([
        'title' => 'DotA 2 Update - 7.40c',
        'url' => 'https://example.com/patch',
        'contents' => '[p]Fixed bugs and improved performance.[/p][list][*]Bug fix 1[/*][*]Bug fix 2[/*][/list]',
    ]);

    $fonnteService = Mockery::mock(FonnteService::class);
    $fonnteService->shouldReceive('sendMessage')
        ->once()
        ->with(Mockery::any(), Mockery::on(function ($message) {
            return str_contains($message, 'DotA 2 Update - 7.40c')
                && str_contains($message, 'Fixed bugs')
                && str_contains($message, '📰')
                && str_contains($message, 'https://example.com/patch');
        }))
        ->andReturn(true);

    $telegramService = Mockery::mock(TelegramService::class);
    $telegramService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(true);

    $this->app->instance(FonnteService::class, $fonnteService);
    $this->app->instance(TelegramService::class, $telegramService);

    $job = new ProcessNewsNotification($steamNews);
    $job->handle($fonnteService, $telegramService);
});

test('it retries on failure', function () {
    Setting::set('fonnte_phone_number', '628123456789');

    $steamNews = SteamNews::factory()->create([
        'notified_at' => null,
    ]);

    // Both services fail
    $fonnteService = Mockery::mock(FonnteService::class);
    $fonnteService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(false);

    $telegramService = Mockery::mock(TelegramService::class);
    $telegramService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(false);

    $this->app->instance(FonnteService::class, $fonnteService);
    $this->app->instance(TelegramService::class, $telegramService);

    $job = new ProcessNewsNotification($steamNews);

    // Should throw exception to trigger retry
    expect(fn () => $job->handle($fonnteService, $telegramService))
        ->toThrow(Exception::class);

    // Verify notified_at was NOT updated
    $steamNews->refresh();
    expect($steamNews->notified_at)->toBeNull();
});

test('it updates notified_at when at least one platform succeeds', function () {
    Setting::set('fonnte_phone_number', '628123456789');

    $steamNews = SteamNews::factory()->create([
        'notified_at' => null,
    ]);

    // WhatsApp succeeds, Telegram fails
    $fonnteService = Mockery::mock(FonnteService::class);
    $fonnteService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(true);

    $telegramService = Mockery::mock(TelegramService::class);
    $telegramService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(false);

    $this->app->instance(FonnteService::class, $fonnteService);
    $this->app->instance(TelegramService::class, $telegramService);

    $job = new ProcessNewsNotification($steamNews);
    $job->handle($fonnteService, $telegramService);

    // Should still mark as notified since one platform succeeded
    $steamNews->refresh();
    expect($steamNews->notified_at)->not->toBeNull();
});

test('it strips bbcode from contents', function () {
    Setting::set('fonnte_phone_number', '628123456789');
    Setting::set('telegram_group_id', '-1001234567890');

    $steamNews = SteamNews::factory()->create([
        'contents' => '[p]Test [b]bold[/b] and [url=https://example.com]link[/url] text.[/p]',
    ]);

    $fonnteService = Mockery::mock(FonnteService::class);
    $fonnteService->shouldReceive('sendMessage')
        ->once()
        ->with(Mockery::any(), Mockery::on(function ($message) {
            // Should not contain BBCode tags
            return ! str_contains($message, '[p]')
                && ! str_contains($message, '[b]')
                && ! str_contains($message, '[url=')
                && str_contains($message, 'Test');
        }))
        ->andReturn(true);

    $telegramService = Mockery::mock(TelegramService::class);
    $telegramService->shouldReceive('sendMessage')
        ->once()
        ->andReturn(true);

    $this->app->instance(FonnteService::class, $fonnteService);
    $this->app->instance(TelegramService::class, $telegramService);

    $job = new ProcessNewsNotification($steamNews);
    $job->handle($fonnteService, $telegramService);
});
