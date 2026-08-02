<?php

use App\Models\Destination;
use App\Services\FonnteService;
use App\Services\Messaging\DestinationMessageService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

test('send throws when telegram transport reports failure', function () {
    $destination = Destination::query()->firstOrCreate(['code' => Destination::CODE_TELEGRAM]);

    $telegramMock = mock(TelegramService::class);
    $telegramMock->shouldReceive('sendMessage')->once()->andReturn(false);

    app()->instance(TelegramService::class, $telegramMock);

    $service = app(DestinationMessageService::class);

    expect(fn () => $service->send($destination, 'hello'))->toThrow(RuntimeException::class);
});

test('send does not throw when telegram transport reports success', function () {
    $destination = Destination::query()->firstOrCreate(['code' => Destination::CODE_TELEGRAM]);

    $telegramMock = mock(TelegramService::class);
    $telegramMock->shouldReceive('sendMessage')->once()->andReturn(true);

    app()->instance(TelegramService::class, $telegramMock);

    $service = app(DestinationMessageService::class);

    $service->send($destination, 'hello');
})->throwsNoExceptions();

test('send throws when whatsapp transport reports failure', function () {
    $destination = Destination::query()->firstOrCreate(['code' => Destination::CODE_WHATSAPP]);

    $fonnteMock = mock(FonnteService::class);
    $fonnteMock->shouldReceive('sendMessage')->once()->andReturn(false);

    app()->instance(FonnteService::class, $fonnteMock);

    $service = app(DestinationMessageService::class);

    expect(fn () => $service->send($destination, 'hello'))->toThrow(RuntimeException::class);
});
