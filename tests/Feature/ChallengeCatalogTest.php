<?php

use App\Support\DailyChallenge\ChallengeCatalog;
use App\Support\DailyChallenge\ChallengeCatalogValidator;

// --- Definitions Load Correctly ---

test('catalog definitions load with expected codes', function () {
    $definitions = ChallengeCatalog::definitions();

    $codes = array_column($definitions, 'code');

    expect($codes)->toContain('total_kills');
    expect($codes)->toContain('total_denies');
    expect($codes)->toContain('total_heal');
    expect($codes)->toContain('hero_win');
    expect($codes)->toContain('item_win');
    expect($codes)->toContain('last_hits');
    expect($codes)->toContain('zero_death_win');
    expect($codes)->toContain('fast_win');
});

test('catalog definitions have required keys', function () {
    $definitions = ChallengeCatalog::definitions();

    foreach ($definitions as $definition) {
        expect($definition)->toHaveKey('code');
        expect($definition)->toHaveKey('name');
        expect($definition)->toHaveKey('description');
        expect($definition)->toHaveKey('category');
        expect($definition)->toHaveKey('weight');
        expect($definition)->toHaveKey('base_requirement');
        expect($definition)->toHaveKey('increment_value');
        expect($definition)->toHaveKey('max_requirement');
        expect($definition)->toHaveKey('is_active');
    }
});

test('catalog definitions have valid weights', function () {
    $definitions = ChallengeCatalog::definitions();

    foreach ($definitions as $definition) {
        expect($definition['weight'])->toBeInt()->toBeGreaterThanOrEqual(1);
    }
});

test('catalog definitions have valid requirements', function () {
    $definitions = ChallengeCatalog::definitions();

    foreach ($definitions as $definition) {
        expect($definition['base_requirement'])
            ->toBeInt()
            ->toBeLessThanOrEqual($definition['max_requirement']);
    }
});

test('catalog hero_win has excluded_heroes in configuration', function () {
    $definitions = ChallengeCatalog::definitions();

    $heroWin = collect($definitions)->firstWhere('code', 'hero_win');

    expect($heroWin)->not->toBeNull();
    expect($heroWin['configuration'])->toBeArray();
    expect($heroWin['configuration']['random_hero'])->toBeTrue();
    expect($heroWin['configuration']['excluded_heroes'])->toBe([82]);
});

test('catalog uses suggested weights', function () {
    $definitions = ChallengeCatalog::definitions();

    $weights = collect($definitions)->pluck('weight', 'code');

    expect($weights['hero_win'])->toBe(15);
    expect($weights['total_kills'])->toBe(15);
    expect($weights['item_win'])->toBe(10);
    expect($weights['total_denies'])->toBe(10);
    expect($weights['total_heal'])->toBe(8);
    expect($weights['last_hits'])->toBe(8);
    expect($weights['fast_win'])->toBe(5);
    expect($weights['zero_death_win'])->toBe(2);
});

// --- Validator Passes ---

test('validator passes valid catalog definitions', function () {
    $definitions = [
        [
            'code' => 'test_challenge',
            'name' => 'Test Challenge',
            'description' => 'A test challenge',
            'weight' => 10,
            'base_requirement' => 1,
            'increment_value' => 1,
            'max_requirement' => 3,
        ],
    ];

    // Should not throw
    ChallengeCatalogValidator::validate($definitions);

    expect(true)->toBeTrue();
});

// --- Validator Rejects ---

test('validator rejects missing required key', function () {
    $definitions = [
        [
            'code' => 'test_challenge',
            'name' => 'Test Challenge',
            // 'description' => missing
            'weight' => 10,
            'base_requirement' => 1,
            'increment_value' => 1,
            'max_requirement' => 3,
        ],
    ];

    ChallengeCatalogValidator::validate($definitions);
})->throws(InvalidArgumentException::class, 'missing required key');

test('validator rejects weight less than 1', function () {
    $definitions = [
        [
            'code' => 'test_challenge',
            'name' => 'Test Challenge',
            'description' => 'A test challenge',
            'weight' => 0,
            'base_requirement' => 1,
            'increment_value' => 1,
            'max_requirement' => 3,
        ],
    ];

    ChallengeCatalogValidator::validate($definitions);
})->throws(InvalidArgumentException::class, 'weight must be >= 1');

test('validator rejects base_requirement greater than max_requirement', function () {
    $definitions = [
        [
            'code' => 'test_challenge',
            'name' => 'Test Challenge',
            'description' => 'A test challenge',
            'weight' => 10,
            'base_requirement' => 10,
            'increment_value' => 1,
            'max_requirement' => 5,
        ],
    ];

    ChallengeCatalogValidator::validate($definitions);
})->throws(InvalidArgumentException::class, 'must be <= max_requirement');

test('validator rejects duplicate codes', function () {
    $definitions = [
        [
            'code' => 'duplicate_code',
            'name' => 'First',
            'description' => 'First entry',
            'weight' => 10,
            'base_requirement' => 1,
            'increment_value' => 1,
            'max_requirement' => 3,
        ],
        [
            'code' => 'duplicate_code',
            'name' => 'Second',
            'description' => 'Second entry',
            'weight' => 5,
            'base_requirement' => 1,
            'increment_value' => 1,
            'max_requirement' => 2,
        ],
    ];

    ChallengeCatalogValidator::validate($definitions);
})->throws(InvalidArgumentException::class, 'duplicate code');

// --- Metric Validation ---

test('validator accepts valid metric in configuration', function () {
    $definitions = [
        [
            'code' => 'test_metric_challenge',
            'name' => 'Test Metric Challenge',
            'description' => 'A test challenge with metric',
            'weight' => 10,
            'base_requirement' => 30,
            'increment_value' => 5,
            'max_requirement' => 60,
            'configuration' => ['metric' => 'kills'],
        ],
    ];

    // Should not throw
    ChallengeCatalogValidator::validate($definitions);

    expect(true)->toBeTrue();
});

test('validator rejects invalid metric in configuration', function () {
    $definitions = [
        [
            'code' => 'test_bad_metric',
            'name' => 'Bad Metric Challenge',
            'description' => 'A test challenge with invalid metric',
            'weight' => 10,
            'base_requirement' => 1,
            'increment_value' => 1,
            'max_requirement' => 3,
            'configuration' => ['metric' => 'rampages'],
        ],
    ];

    ChallengeCatalogValidator::validate($definitions);
})->throws(InvalidArgumentException::class, 'is not a valid metric');

// --- Catalog Metric Configurations ---

test('metric-based challenges have metric in configuration', function () {
    $definitions = ChallengeCatalog::definitions();

    $metricCodes = ['total_kills', 'total_denies', 'total_heal', 'last_hits'];

    foreach ($metricCodes as $code) {
        $entry = collect($definitions)->firstWhere('code', $code);
        expect($entry)->not->toBeNull();
        expect($entry['configuration'])->toBeArray();
        expect($entry['configuration']['metric'])->toBeString();
    }
});

test('total_kills uses kills metric', function () {
    $definitions = ChallengeCatalog::definitions();
    $entry = collect($definitions)->firstWhere('code', 'total_kills');

    expect($entry['configuration']['metric'])->toBe('kills');
});

test('total_denies uses denies metric', function () {
    $definitions = ChallengeCatalog::definitions();
    $entry = collect($definitions)->firstWhere('code', 'total_denies');

    expect($entry['configuration']['metric'])->toBe('denies');
});

test('total_heal uses hero_healing metric', function () {
    $definitions = ChallengeCatalog::definitions();
    $entry = collect($definitions)->firstWhere('code', 'total_heal');

    expect($entry['configuration']['metric'])->toBe('hero_healing');
});

test('last_hits uses last_hits metric', function () {
    $definitions = ChallengeCatalog::definitions();
    $entry = collect($definitions)->firstWhere('code', 'last_hits');

    expect($entry['configuration']['metric'])->toBe('last_hits');
});

// --- Catalog Count & Group Validation (Step 10) ---

test('catalog contains 27 challenge entries', function () {
    $definitions = ChallengeCatalog::definitions();

    expect($definitions)->toHaveCount(27);
});

test('all catalog entries have a non-null group', function () {
    $definitions = ChallengeCatalog::definitions();

    foreach ($definitions as $definition) {
        expect($definition)->toHaveKey('group');
        expect($definition['group'])->not->toBeNull();
        expect($definition['group'])->toBeString();
        expect($definition['group'])->not->toBe('');
    }
});

test('catalog entries have correct group assignments', function () {
    $definitions = ChallengeCatalog::definitions();
    $groups = collect($definitions)->pluck('group', 'code');

    // Existing groups
    expect($groups['total_kills'])->toBe('kills');
    expect($groups['total_denies'])->toBe('denies');
    expect($groups['total_heal'])->toBe('healing');
    expect($groups['hero_win'])->toBe('hero_win');
    expect($groups['item_win'])->toBe('item_win');
    expect($groups['last_hits'])->toBe('last_hits');
    expect($groups['zero_death_win'])->toBe('survival');
    expect($groups['fast_win'])->toBe('speed');

    // New accumulative team
    expect($groups['total_assists'])->toBe('assists');
    expect($groups['total_hero_damage'])->toBe('hero_damage');
    expect($groups['total_tower_damage'])->toBe('tower_damage');
    expect($groups['total_last_hits'])->toBe('last_hits');
    expect($groups['total_net_worth'])->toBe('economy');

    // New single match team
    expect($groups['team_assists_match'])->toBe('assists');
    expect($groups['team_kills_match'])->toBe('kills');
    expect($groups['team_last_hits_match'])->toBe('last_hits');
    expect($groups['team_denies_match'])->toBe('denies');
    expect($groups['team_hero_damage_match'])->toBe('hero_damage');
    expect($groups['team_tower_damage_match'])->toBe('tower_damage');

    // New single match individual
    expect($groups['player_kills_match'])->toBe('kills');
    expect($groups['player_assists_match'])->toBe('assists');
    expect($groups['player_last_hits_match'])->toBe('last_hits');
    expect($groups['player_hero_damage_match'])->toBe('hero_damage');
    expect($groups['player_tower_damage_match'])->toBe('tower_damage');
    expect($groups['player_net_worth_match'])->toBe('economy');
    expect($groups['player_gpm_match'])->toBe('economy');
    expect($groups['player_xpm_match'])->toBe('economy');
});

test('new accumulative team challenges have correct metrics', function () {
    $definitions = ChallengeCatalog::definitions();
    $entries = collect($definitions)->keyBy('code');

    expect($entries['total_assists']['configuration']['metric'])->toBe('assists');
    expect($entries['total_hero_damage']['configuration']['metric'])->toBe('hero_damage');
    expect($entries['total_tower_damage']['configuration']['metric'])->toBe('tower_damage');
    expect($entries['total_last_hits']['configuration']['metric'])->toBe('last_hits');
    expect($entries['total_net_worth']['configuration']['metric'])->toBe('net_worth');
});

test('new single match team challenges have correct metrics and are snapshots', function () {
    $definitions = ChallengeCatalog::definitions();
    $entries = collect($definitions)->keyBy('code');

    $teamSnapshotCodes = [
        'team_assists_match', 'team_kills_match', 'team_last_hits_match',
        'team_denies_match', 'team_hero_damage_match', 'team_tower_damage_match',
    ];

    foreach ($teamSnapshotCodes as $code) {
        expect($entries[$code]['category'])->toBe('snapshot');
        expect($entries[$code]['increment_value'])->toBe(0);
        expect($entries[$code]['base_requirement'])->toBe($entries[$code]['max_requirement']);
    }
});

test('new single match individual challenges have correct metrics and are snapshots', function () {
    $definitions = ChallengeCatalog::definitions();
    $entries = collect($definitions)->keyBy('code');

    $individualCodes = [
        'player_kills_match', 'player_assists_match', 'player_last_hits_match',
        'player_hero_damage_match', 'player_tower_damage_match',
        'player_net_worth_match', 'player_gpm_match', 'player_xpm_match',
    ];

    foreach ($individualCodes as $code) {
        expect($entries[$code]['category'])->toBe('snapshot');
        expect($entries[$code]['increment_value'])->toBe(0);
        expect($entries[$code]['configuration']['metric'])->toBeString();
    }
});

test('all metric-based challenges reference valid metrics', function () {
    $definitions = ChallengeCatalog::definitions();

    foreach ($definitions as $definition) {
        if (isset($definition['configuration']['metric'])) {
            expect(\App\Support\DailyChallenge\MetricRegistry::exists($definition['configuration']['metric']))
                ->toBeTrue("Metric '{$definition['configuration']['metric']}' for {$definition['code']} is invalid");
        }
    }
});

test('catalog weights are consistent with step 10 guidelines', function () {
    $definitions = ChallengeCatalog::definitions();
    $weights = collect($definitions)->pluck('weight', 'code');

    // Common (15): kills, assists, hero_win
    expect($weights['total_kills'])->toBe(15);
    expect($weights['total_assists'])->toBe(15);
    expect($weights['hero_win'])->toBe(15);
    expect($weights['team_kills_match'])->toBe(15);
    expect($weights['team_assists_match'])->toBe(15);
    expect($weights['player_kills_match'])->toBe(15);
    expect($weights['player_assists_match'])->toBe(15);

    // Medium-high (12): last_hits group
    expect($weights['total_last_hits'])->toBe(12);
    expect($weights['team_last_hits_match'])->toBe(12);
    expect($weights['player_last_hits_match'])->toBe(12);

    // Medium (10): denies, item_win, hero_damage
    expect($weights['total_denies'])->toBe(10);
    expect($weights['item_win'])->toBe(10);
    expect($weights['total_hero_damage'])->toBe(10);
    expect($weights['team_hero_damage_match'])->toBe(10);
    expect($weights['player_hero_damage_match'])->toBe(10);

    // Medium-low (8): healing, last_hits (individual), tower_damage, denies team, economy, net_worth
    expect($weights['total_heal'])->toBe(8);
    expect($weights['last_hits'])->toBe(8);
    expect($weights['total_tower_damage'])->toBe(8);
    expect($weights['team_tower_damage_match'])->toBe(8);
    expect($weights['player_tower_damage_match'])->toBe(8);
    expect($weights['team_denies_match'])->toBe(8);
    expect($weights['total_net_worth'])->toBe(8);
    expect($weights['player_net_worth_match'])->toBe(8);

    // Rare (5 or less): fast_win, zero_death_win, extreme GPM/XPM
    expect($weights['fast_win'])->toBe(5);
    expect($weights['zero_death_win'])->toBe(2);
    expect($weights['player_gpm_match'])->toBe(3);
    expect($weights['player_xpm_match'])->toBe(3);
});
