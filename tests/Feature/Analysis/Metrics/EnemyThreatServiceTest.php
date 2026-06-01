<?php

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\NormalizedPlayer;
use App\Enums\ImpactOnUs;
use App\Enums\ThreatType;
use App\Services\Analysis\Metrics\EnemyThreatService;

function etmMatch(array $enemyPlayers): NormalizedMatch
{
    return new NormalizedMatch(
        matchId: 'test',
        memberTeam: 'radiant',
        memberWon: false,
        duration: 2400,
        gameMode: 'Turbo',
        radiantPlayers: [],
        direPlayers: $enemyPlayers,
        teamfights: [],
        objectives: [],
        goldAdvantage: [],
        xpAdvantage: [],
    );
}

function etmEnemy(
    string $heroName,
    int $kills = 0,
    int $deaths = 5,
    int $assists = 0,
    int $heroDamage = 10000,
    int $towerDamage = 500,
): NormalizedPlayer {
    return new NormalizedPlayer(
        heroId: 1,
        heroName: $heroName,
        team: 'dire',
        kills: $kills,
        deaths: $deaths,
        assists: $assists,
        heroDamage: $heroDamage,
        towerDamage: $towerDamage,
        goldPerMin: 400,
        xpPerMin: 500,
        netWorth: 12000,
    );
}

test('classifies PickoffHunter when KDA > 3 and high kill contribution', function () {
    // KDA = (12+8)/3 = 6.67, killContrib = (12+8)/(25*2) = 0.40 exactly
    // Use kills = 13 to push over 0.40
    $hunter = etmEnemy('Anti-Mage', kills: 13, deaths: 3, assists: 8, heroDamage: 8000);
    $filler = etmEnemy('Witch Doctor', kills: 3, deaths: 5, assists: 10, heroDamage: 12000);
    $filler2 = etmEnemy('Mars', kills: 4, deaths: 5, assists: 6, heroDamage: 8000);

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([$hunter, $filler, $filler2]));

    $entry = collect($matrix->entries)->firstWhere('heroName', 'Anti-Mage');
    expect($entry->threatType)->toBe(ThreatType::PickoffHunter)
        ->and($entry->impactOnUs)->toContain(ImpactOnUs::PickoffPressure);
});

test('classifies TeamfightCarry when damage share exceeds 35%', function () {
    // Lina: low KDA + low kill contrib but dominant damage → TeamfightCarry
    // KDA = (5+2)/8 = 0.875, killContrib = 7/(11*2) = 0.318 — both miss PickoffHunter
    // damageShare = 40000/80000 = 50% → TeamfightCarry
    $carry = etmEnemy('Lina', kills: 5, deaths: 8, assists: 2, heroDamage: 40000);
    $support = etmEnemy('Crystal Maiden', kills: 1, deaths: 6, assists: 12, heroDamage: 8000);
    $mid = etmEnemy('Shadow Fiend', kills: 5, deaths: 5, assists: 8, heroDamage: 32000);

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([$carry, $support, $mid]));

    $entry = collect($matrix->entries)->firstWhere('heroName', 'Lina');
    expect($entry->threatType)->toBe(ThreatType::TeamfightCarry)
        ->and($entry->impactOnUs)->toContain(ImpactOnUs::TeamfightDominance);
});

test('classifies InitiationThreat when high kill contrib but low damage', function () {
    // Earthshaker: KDA = (4+8)/6 = 2.0 (<3), killContrib = 12/(16*2) = 0.375 (>0.35), damageShare = 5000/25000 = 0.20 (<0.25)
    $initiator = etmEnemy('Earthshaker', kills: 4, deaths: 6, assists: 8, heroDamage: 5000);
    $carry = etmEnemy('Phantom Assassin', kills: 12, deaths: 3, assists: 4, heroDamage: 20000);

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([$initiator, $carry]));

    $entry = collect($matrix->entries)->firstWhere('heroName', 'Earthshaker');
    expect($entry->threatType)->toBe(ThreatType::InitiationThreat)
        ->and($entry->impactOnUs)->toContain(ImpactOnUs::TeamfightDominance);
});

test('classifies SplitPusher when tower damage is double the average', function () {
    // avg tower = (8000+1000+1000)/3 = 3333; split pusher = 8000 > 6666
    $pusher = etmEnemy('Nature Prophet', kills: 4, deaths: 6, assists: 3, heroDamage: 10000, towerDamage: 8000);
    $support = etmEnemy('Lion', kills: 2, deaths: 7, assists: 9, heroDamage: 14000, towerDamage: 1000);
    $support2 = etmEnemy('Jakiro', kills: 3, deaths: 7, assists: 7, heroDamage: 14000, towerDamage: 1000);

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([$pusher, $support, $support2]));

    $entry = collect($matrix->entries)->firstWhere('heroName', 'Nature Prophet');
    expect($entry->threatType)->toBe(ThreatType::SplitPusher)
        ->and($entry->impactOnUs)->toContain(ImpactOnUs::MapPressure);
});

test('classifies SustainDamage as fallback for low-profile heroes', function () {
    // 3 even heroes: each ~33% damage share (<35%), low KDA, low kill contrib → all fall through to SustainDamage
    $filler1 = etmEnemy('Warlock', kills: 2, deaths: 7, assists: 6, heroDamage: 9000, towerDamage: 300);
    $filler2 = etmEnemy('Jakiro', kills: 2, deaths: 8, assists: 5, heroDamage: 9000, towerDamage: 300);
    $filler3 = etmEnemy('Dazzle', kills: 2, deaths: 7, assists: 6, heroDamage: 9000, towerDamage: 300);

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([$filler1, $filler2, $filler3]));

    $entry = collect($matrix->entries)->firstWhere('heroName', 'Warlock');
    expect($entry->threatType)->toBe(ThreatType::SustainDamage);
});

test('matrix is sorted by damage share descending and capped at 5 entries', function () {
    $players = [];
    // 6 players with descending damage: 50k, 40k, 30k, 20k, 10k, 5k
    foreach ([50000, 40000, 30000, 20000, 10000, 5000] as $dmg) {
        $players[] = etmEnemy('Hero_'.$dmg, kills: 2, deaths: 5, assists: 3, heroDamage: $dmg);
    }

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch($players));

    expect($matrix->entries)->toHaveCount(5)
        ->and($matrix->entries[0]->heroName)->toBe('Hero_50000')
        ->and($matrix->primaryThreat())->toBe('Hero_50000');
});

test('returns empty matrix when no enemy players', function () {
    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([]));

    expect($matrix->entries)->toBeEmpty()
        ->and($matrix->primaryThreat())->toBe('unknown')
        ->and($matrix->toPayload())->toBeEmpty();
});

test('classifies ObjectiveThreat impact when tower damage exceeds 2000', function () {
    $pusher = etmEnemy('Dragon Knight', kills: 4, deaths: 5, assists: 5, heroDamage: 15000, towerDamage: 3500);

    $service = new EnemyThreatService;
    $matrix = $service->build(etmMatch([$pusher]));

    expect($matrix->entries[0]->impactOnUs)->toContain(ImpactOnUs::ObjectiveThreat);
});
