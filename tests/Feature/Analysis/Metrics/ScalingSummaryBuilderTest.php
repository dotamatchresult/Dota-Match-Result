<?php

use App\DataObjects\Analysis\NormalizedMatch;
use App\Enums\ScalingPhase;
use App\Enums\ScalingTrendPattern;
use App\Services\Analysis\Metrics\ScalingSummaryBuilder;

function makeScalingMatch(array $goldAdv, string $memberTeam = 'radiant'): NormalizedMatch
{
    return new NormalizedMatch(
        matchId: 'test',
        memberTeam: $memberTeam,
        memberWon: false,
        duration: 3000,
        gameMode: 'All Pick',
        radiantPlayers: [],
        direPlayers: [],
        teamfights: [],
        objectives: [],
        goldAdvantage: $goldAdv,
        xpAdvantage: [],
    );
}

// Build gold advantage indexed by minute
function goldAdv(int $earlyVal, int $midVal, int $lateVal): array
{
    $adv = [];

    for ($m = 0; $m <= 14; $m++) {
        $adv[$m] = $earlyVal;
    }
    for ($m = 15; $m <= 29; $m++) {
        $adv[$m] = $midVal;
    }
    for ($m = 30; $m <= 42; $m++) {
        $adv[$m] = $lateVal;
    }

    return $adv;
}

test('pattern is NeverAhead when behind all game', function () {
    $match = makeScalingMatch(goldAdv(-3000, -4000, -5000));
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->trendPattern)->toBe(ScalingTrendPattern::NeverAhead)
        ->and($descriptor->earlyPhase)->toBe(ScalingPhase::Behind)
        ->and($descriptor->collapseMinute)->toBeNull();
});

test('pattern is SteadyLead when ahead or even throughout', function () {
    $match = makeScalingMatch(goldAdv(3000, 2500, 1800));
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->trendPattern)->toBe(ScalingTrendPattern::SteadyLead)
        ->and($descriptor->earlyPhase)->toBe(ScalingPhase::Ahead)
        ->and($descriptor->latePhase)->toBe(ScalingPhase::Ahead);
});

test('pattern is EarlyThrow when had early lead but finished behind', function () {
    $match = makeScalingMatch(goldAdv(3000, 500, -3000));
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->trendPattern)->toBe(ScalingTrendPattern::EarlyThrow)
        ->and($descriptor->earlyPhase)->toBe(ScalingPhase::Ahead)
        ->and($descriptor->latePhase)->toBe(ScalingPhase::Behind)
        ->and($descriptor->collapseMinute)->not->toBeNull();
});

test('pattern is ComebackAttempt when behind early, led mid, collapsed late', function () {
    $match = makeScalingMatch(goldAdv(-3000, 2000, -3000));
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->trendPattern)->toBe(ScalingTrendPattern::ComebackAttempt)
        ->and($descriptor->midPhase)->toBe(ScalingPhase::Ahead);
});

test('pattern is BleedOut when gradually declined to behind', function () {
    $match = makeScalingMatch(goldAdv(500, -500, -3000));
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->trendPattern)->toBe(ScalingTrendPattern::BleedOut)
        ->and($descriptor->earlyPhase)->toBe(ScalingPhase::Even)
        ->and($descriptor->latePhase)->toBe(ScalingPhase::Behind);
});

test('collapseMinute is null when team never held a positive lead', function () {
    $match = makeScalingMatch(goldAdv(-2000, -3000, -4000));
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->collapseMinute)->toBeNull();
});

test('collapseMinute returns first minute gold crosses negative', function () {
    // positive up to minute 10, then negative
    $adv = [];
    for ($m = 0; $m <= 9; $m++) {
        $adv[$m] = 2000;
    }
    for ($m = 10; $m <= 40; $m++) {
        $adv[$m] = -2000;
    }

    $match = makeScalingMatch($adv);
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->collapseMinute)->toBe(10);
});

test('gold advantage is negated correctly for dire member team', function () {
    // From dire perspective: radiant_gold_adv = -3000 → dire perspective = +3000 (Ahead)
    $match = makeScalingMatch(goldAdv(-3000, -2500, -2000), memberTeam: 'dire');
    $builder = new ScalingSummaryBuilder;
    $descriptor = $builder->build($match);

    expect($descriptor->earlyPhase)->toBe(ScalingPhase::Ahead)
        ->and($descriptor->trendPattern)->toBe(ScalingTrendPattern::SteadyLead);
});

test('toPayload returns all required keys as strings', function () {
    $match = makeScalingMatch(goldAdv(2000, 1000, -2000));
    $builder = new ScalingSummaryBuilder;
    $payload = $builder->build($match)->toPayload();

    expect($payload)->toHaveKeys(['early', 'mid', 'late', 'collapse_at', 'pattern'])
        ->and($payload['early'])->toBe('Ahead')
        ->and($payload['pattern'])->toBe('EarlyThrow');
});
