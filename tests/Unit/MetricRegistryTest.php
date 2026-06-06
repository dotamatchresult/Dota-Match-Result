<?php

use App\Support\DailyChallenge\MetricRegistry;

test('registry returns all metrics', function () {
    $metrics = MetricRegistry::all();

    expect($metrics)->toBeArray();
    expect($metrics)->toHaveCount(11);
    expect($metrics)->toContain('kills');
    expect($metrics)->toContain('deaths');
    expect($metrics)->toContain('assists');
    expect($metrics)->toContain('last_hits');
    expect($metrics)->toContain('denies');
    expect($metrics)->toContain('hero_healing');
    expect($metrics)->toContain('hero_damage');
    expect($metrics)->toContain('tower_damage');
    expect($metrics)->toContain('net_worth');
    expect($metrics)->toContain('gold_per_min');
    expect($metrics)->toContain('xp_per_min');
});

test('exists returns true for valid metrics', function () {
    expect(MetricRegistry::exists('kills'))->toBeTrue();
    expect(MetricRegistry::exists('deaths'))->toBeTrue();
    expect(MetricRegistry::exists('hero_healing'))->toBeTrue();
    expect(MetricRegistry::exists('last_hits'))->toBeTrue();
});

test('exists returns false for invalid metrics', function () {
    expect(MetricRegistry::exists('rampages'))->toBeFalse();
    expect(MetricRegistry::exists('roshan_kills'))->toBeFalse();
    expect(MetricRegistry::exists('wards_placed'))->toBeFalse();
    expect(MetricRegistry::exists(''))->toBeFalse();
});
