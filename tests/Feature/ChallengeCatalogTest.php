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
