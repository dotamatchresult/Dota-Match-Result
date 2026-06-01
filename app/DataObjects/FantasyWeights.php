<?php

namespace App\DataObjects;

/**
 * Centralised Fantasy Score component weights.
 *
 * Scoring Philosophy:
 * - Team-relative shares (65%): kill participation, damage/tower/healing shares
 * - Individual performance (25%): normalised KDA + efficiency score
 * - Benchmark-based (10%): economy (GPM/XPM) for role balance
 *
 * All weights sum to 1.0.
 */
readonly class FantasyWeights
{
    /**
     * @return array{
     *     kill_participation: float,
     *     hero_damage_share: float,
     *     tower_damage_share: float,
     *     healing_impact: float,
     *     kda_normalized: float,
     *     efficiency_score: float,
     *     economy_percentile: float,
     * }
     */
    public static function weights(): array
    {
        return [
            // Team-relative components (65% total weight)
            'kill_participation' => 0.25,    // (K+A) / team_kills
            'hero_damage_share' => 0.20,     // player_damage / team_damage
            'tower_damage_share' => 0.12,    // player_tower / team_tower
            'healing_impact' => 0.08,        // hybrid: team share + benchmark percentile

            // Individual performance (25% total weight)
            'kda_normalized' => 0.15,        // min((K+A)/(D+1), 10) / 10
            'efficiency_score' => 0.10,      // (damage + healing) / net_worth

            // Benchmark-based (10% total weight)
            'economy_percentile' => 0.10,    // average of GPM and XPM percentiles
        ];
    }

    public static function labels(): array
    {
        return [
            'kill_participation' => 'Kill Participation',
            'hero_damage_share' => 'Hero Dmg. Share',
            'tower_damage_share' => 'Tower Dmg. Share',
            'healing_impact' => 'Healing Impact',
            'kda_normalized' => 'KDA Normalized',
            'efficiency_score' => 'Efficiency Score',
            'economy_percentile' => 'Economy Percentile',
        ];
    }
}
