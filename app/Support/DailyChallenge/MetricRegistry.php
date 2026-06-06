<?php

namespace App\Support\DailyChallenge;

final class MetricRegistry
{
    /**
     * The authoritative list of all available metrics for challenge evaluation.
     *
     * These correspond to player-level fields available in the unparsed
     * OpenDota match_data payload.
     *
     * @var list<string>
     */
    public const METRICS = [
        'kills',
        'deaths',
        'assists',
        'last_hits',
        'denies',
        'hero_healing',
        'hero_damage',
        'tower_damage',
        'net_worth',
        'gold_per_min',
        'xp_per_min',
    ];

    /**
     * Return the complete list of available metrics.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::METRICS;
    }

    /**
     * Check whether a given metric name exists in the registry.
     */
    public static function exists(string $metric): bool
    {
        return in_array($metric, self::METRICS, true);
    }
}
