<?php

use App\Services\OpenAiService;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;

beforeEach(function () {
    // Ensure OPENAI_API_KEY is set for tests
    config(['openai.api_key' => 'test-api-key']);
});

test('service can be instantiated with client contract', function () {
    $service = app(OpenAiService::class);

    expect($service)->toBeInstanceOf(OpenAiService::class);
});

test('client is properly injected from container', function () {
    $client = app(ClientContract::class);

    expect($client)->toBeInstanceOf(ClientContract::class);
});

test('openai api key is loaded from config', function () {
    $apiKey = config('openai.api_key');

    expect($apiKey)->not->toBeEmpty()
        ->and($apiKey)->toBe('test-api-key');
});

test('service uses injected client for chat requests', function () {
    OpenAI::fake([
        CreateResponse::fake([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Test analysis response',
                    ],
                ],
            ],
        ]),
    ]);

    $service = app(OpenAiService::class);
    $matchData = [
        'match_id' => '12345',
        'radiant_win' => false,
        'duration' => 2000,
        'players' => [],
    ];

    $result = $service->analyzeDefeat($matchData, ['76561198123456789']);

    expect($result)->toBeString()
        ->and($result)->toContain('Test analysis response');

    OpenAI::assertSent(function ($method, $parameters) {
        return $method === 'chat'
            && $parameters['model'] === 'gpt-5.4-mini';
    });
});
