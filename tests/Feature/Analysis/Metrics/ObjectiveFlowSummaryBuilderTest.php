<?php

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveEvent;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\DataObjects\Analysis\TeamfightSummary;
use App\Services\Analysis\Metrics\ObjectiveFlowSummaryBuilder;

function makeObjectiveFlow(array $events, array $momentumShifts = [], ?string $critical = null): ObjectiveFlow
{
    return new ObjectiveFlow(
        events: $events,
        momentumShifts: $momentumShifts,
        criticalObjective: $critical,
    );
}

function makeMinimalMatch(string $memberTeam = 'radiant'): NormalizedMatch
{
    return new NormalizedMatch(
        matchId: 'test',
        memberTeam: $memberTeam,
        memberWon: false,
        duration: 2400,
        gameMode: 'All Pick',
        radiantPlayers: [],
        direPlayers: [],
        teamfights: [],
        objectives: [],
        goldAdvantage: [],
        xpAdvantage: [],
    );
}

function makeFight(int $startTime, int $endTime, string $outcome = 'even'): TeamfightSummary
{
    return new TeamfightSummary(
        index: 0,
        startTime: $startTime,
        endTime: $endTime,
        duration: $endTime - $startTime,
        outcome: $outcome,
        swing: 'medium',
        direKills: 1,
        radiantKills: 1,
        direGoldDelta: 0,
        radiantGoldDelta: 0,
        keyHeroes: [],
    );
}

test('roshanControl returns us when member team took roshan', function () {
    $events = [
        new ObjectiveEvent(time: 2000, type: 'roshan', team: 'radiant'),
    ];
    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build(makeObjectiveFlow($events), [], makeMinimalMatch('radiant'));

    expect($summary->roshanControl)->toBe('us');
});

test('roshanControl returns enemy when opponent took roshan', function () {
    $events = [
        new ObjectiveEvent(time: 2000, type: 'roshan', team: 'dire'),
    ];
    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build(makeObjectiveFlow($events), [], makeMinimalMatch('radiant'));

    expect($summary->roshanControl)->toBe('enemy');
});

test('roshanControl returns contested when both teams took roshan', function () {
    $events = [
        new ObjectiveEvent(time: 2000, type: 'roshan', team: 'radiant'),
        new ObjectiveEvent(time: 3200, type: 'roshan', team: 'dire'),
    ];
    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build(makeObjectiveFlow($events), [], makeMinimalMatch('radiant'));

    expect($summary->roshanControl)->toBe('contested');
});

test('roshanControl returns none when no roshan events', function () {
    $events = [
        new ObjectiveEvent(time: 800, type: 'tower', team: 'dire'),
    ];
    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build(makeObjectiveFlow($events), [], makeMinimalMatch('radiant'));

    expect($summary->roshanControl)->toBe('none');
});

test('postPickoffObjectives counts unlinked enemy objectives', function () {
    $events = [
        new ObjectiveEvent(time: 900, type: 'tower', team: 'dire', linkedFightIndex: null),  // counts
        new ObjectiveEvent(time: 1200, type: 'tower', team: 'dire', linkedFightIndex: 1),    // linked — skip
        new ObjectiveEvent(time: 1500, type: 'barracks', team: 'dire', linkedFightIndex: null), // counts
        new ObjectiveEvent(time: 1800, type: 'firstblood', team: 'dire', linkedFightIndex: null), // skip firstblood
        new ObjectiveEvent(time: 2000, type: 'tower', team: 'radiant', linkedFightIndex: null), // our team — skip
    ];
    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build(makeObjectiveFlow($events), [], makeMinimalMatch('radiant'));

    expect($summary->postPickoffObjectives)->toBe(2);
});

test('missedObjectivesAfterWin counts our wins with no follow-up', function () {
    $fights = [
        makeFight(600, 650, 'radiant_win'),   // win: check 650–770 for radiant objective
        makeFight(1100, 1160, 'radiant_win'), // win: check 1160–1280 for radiant objective
        makeFight(1800, 1850, 'dire_win'),    // loss: not counted
    ];

    // radiant objective exists inside 2nd fight's window
    $events = [
        new ObjectiveEvent(time: 1200, type: 'tower', team: 'radiant', linkedFightIndex: null),
    ];

    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build(makeObjectiveFlow($events), $fights, makeMinimalMatch('radiant'));

    // First win (600–650): no objective in 650–770 → missed
    // Second win (1100–1160): radiant tower at 1200 is within 1160–1280 → NOT missed
    expect($summary->missedObjectivesAfterWin)->toBe(1);
});

test('forwards critical objective and map control shift from ObjectiveFlow', function () {
    $flow = makeObjectiveFlow(
        events: [],
        momentumShifts: ['Dire gained momentum around 22:10', 'Earlier shift'],
        critical: 'Enemy Roshan at 35:20',
    );

    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build($flow, [], makeMinimalMatch());

    expect($summary->criticalObjective)->toBe('Enemy Roshan at 35:20')
        ->and($summary->mapControlShift)->toBe('Dire gained momentum around 22:10');
});

test('toPayload returns compact array without raw event data', function () {
    $flow = makeObjectiveFlow(events: [], momentumShifts: [], critical: null);
    $builder = new ObjectiveFlowSummaryBuilder;
    $summary = $builder->build($flow, [], makeMinimalMatch());
    $payload = $summary->toPayload();

    expect($payload)->toHaveKeys([
        'roshan_control',
        'map_control_shift',
        'post_pickoff_objectives_lost',
        'missed_objectives_after_wins',
        'critical_event',
    ])
        ->and($payload['roshan_control'])->toBe('none')
        ->and($payload['critical_event'])->toBe('none');
});
