<?php

use App\Exceptions\InsufficientCreditException;
use App\Jobs\AnalyzeMatchWithAI;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\Analysis\Agents\BulletCompressorAgent;
use App\Services\Analysis\InsufficientCreditDetector;
use App\Services\Analysis\MatchAnalysisPipeline;
use App\Services\FonnteService;
use App\Services\TelegramService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Testing\ClientFake;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function makeParsedTelegramMatch(): DotaMatch
{
    $members = collect(range(1, 3))->map(fn () => Member::factory()->create(['destination' => 'telegram']));

    return DotaMatch::factory()->create([
        'members' => $members->pluck('id')->all(),
        'parse_status' => 'parsed',
    ]);
}

function pipelineOutOfCredit(): void
{
    mock(MatchAnalysisPipeline::class)
        ->shouldReceive('analyze')
        ->andThrow(new InsufficientCreditException('You exceeded your current quota'));
}

beforeEach(function () {
    Cache::forget(AnalyzeMatchWithAI::INSUFFICIENT_CREDIT_CACHE_KEY);
});

test('detector recognises quota errors from different providers', function () {
    $quota = new ErrorException(['message' => 'You exceeded your current quota', 'code' => 'insufficient_quota'], new Response(429));
    $paymentRequired = new ErrorException(['message' => 'Payment needed'], new Response(402));
    $gateway = new Exception('Insufficient credits. Add more at example.com');

    expect(InsufficientCreditDetector::matches($quota))->toBeTrue()
        ->and(InsufficientCreditDetector::matches($paymentRequired))->toBeTrue()
        ->and(InsufficientCreditDetector::matches($gateway))->toBeTrue()
        ->and(InsufficientCreditDetector::matches(new Exception('Connection timed out')))->toBeFalse();
});

test('bullet compressor rethrows insufficient credit instead of swallowing it', function () {
    // Bind the fake on the contract the agents inject (OpenAI::fake() does not replace it).
    app()->instance(ClientContract::class, new ClientFake([
        new ErrorException(['message' => 'Insufficient Balance', 'code' => 'invalid_request_error'], new Response(402)),
    ]));

    $agent = app(BulletCompressorAgent::class);
    $context = new \App\DataObjects\Analysis\CompressionContext(
        gameMode: 'Turbo',
        memberTeam: 'Radiant',
        durationMinutes: 30,
        lossTypeConfirmed: 'EXECUTION_LOSS',
        rootCause: 'x',
        keyFactors: [],
        momentum: 'x',
        memberHeroes: ['Pudge'],
        heroFindings: [],
    );

    expect(fn () => $agent->compress($context))->toThrow(InsufficientCreditException::class);
});

test('out of credit sends the message once and respects the cooldown', function () {
    $match = makeParsedTelegramMatch();
    pipelineOutOfCredit();

    $telegram = mock(TelegramService::class);
    $telegram->shouldReceive('sendMessage')
        ->once()
        ->with('Saldo AI sudah habis lur, saatnya minta sumbangan 🪙', 'ai')
        ->andReturn(true);
    $fonnte = mock(FonnteService::class);
    $fonnte->shouldNotReceive('sendMessage');

    $job = new AnalyzeMatchWithAI($match);
    $job->handle(app(MatchAnalysisPipeline::class), $fonnte, $telegram);
    $job->handle(app(MatchAnalysisPipeline::class), $fonnte, $telegram);

    expect(Cache::has(AnalyzeMatchWithAI::INSUFFICIENT_CREDIT_CACHE_KEY))->toBeTrue()
        ->and($match->fresh()->ai_analysis)->toBeNull();
});

test('cooldown key is forgotten when no platform accepted the message', function () {
    $match = makeParsedTelegramMatch();
    pipelineOutOfCredit();

    $telegram = mock(TelegramService::class);
    $telegram->shouldReceive('sendMessage')->once()->andReturn(false);
    $fonnte = mock(FonnteService::class);

    (new AnalyzeMatchWithAI($match))->handle(app(MatchAnalysisPipeline::class), $fonnte, $telegram);

    expect(Cache::has(AnalyzeMatchWithAI::INSUFFICIENT_CREDIT_CACHE_KEY))->toBeFalse();
});

test('a successful analysis clears the cooldown key', function () {
    $match = makeParsedTelegramMatch();
    Cache::put(AnalyzeMatchWithAI::INSUFFICIENT_CREDIT_CACHE_KEY, now()->timestamp, now()->addWeek());

    mock(MatchAnalysisPipeline::class)
        ->shouldReceive('analyze')
        ->andReturn([
            'analysis_text' => '- ok',
            'computed_metrics' => [],
            'metadata' => [],
        ]);

    $telegram = mock(TelegramService::class);
    $telegram->shouldReceive('sendMessage')->once()->andReturn(true);
    $fonnte = mock(FonnteService::class);

    (new AnalyzeMatchWithAI($match))->handle(app(MatchAnalysisPipeline::class), $fonnte, $telegram);

    expect(Cache::has(AnalyzeMatchWithAI::INSUFFICIENT_CREDIT_CACHE_KEY))->toBeFalse();
});
