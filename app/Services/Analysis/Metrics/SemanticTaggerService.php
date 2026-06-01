<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\DerivedMetrics;
use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ObjectiveFlow;
use App\DataObjects\Analysis\SemanticTagSet;

class SemanticTaggerService
{
    public function tag(DerivedMetrics $metrics, ObjectiveFlow $objectiveFlow, NormalizedMatch $match): SemanticTagSet
    {
        $tags = [];

        // Pickoff vulnerability: most member deaths happened outside fights
        if ($metrics->enemyPickoffRate > 0.35) {
            $tags[] = 'high_pickoff_rate';
        }

        // Objective efficiency: not converting fight wins into buildings
        if ($metrics->objectiveConversionRate < 0.30) {
            $tags[] = 'low_conversion_efficiency';
        }

        // Damage distribution: over-reliance on one hero for damage
        if ($metrics->damageConcentrationRatio > 0.50) {
            $tags[] = 'damage_dependency_core';
        }

        // Scaling: positive mid → negative late (outscaled in late game)
        if ($metrics->scalingLateGold < -2000 && $metrics->scalingMidGold > 0) {
            $tags[] = 'late_game_outscaled';
        }

        // Core protection: core died too early
        if ($metrics->protectionIndex < 0.35) {
            $tags[] = 'weak_protection_core';
        }

        // Early lead: member team dominated early
        if ($metrics->scalingEarlyGold > 2000) {
            $tags[] = 'strong_early_game';
        }

        // Fight dominance: won most fights
        if ($metrics->fightControlRatio > 0.65) {
            $tags[] = 'fight_dominant';
        }

        // Roshan curse: Roshan was taken but gold advantage went negative soon after
        if ($this->detectLostAfterRoshan($objectiveFlow, $match)) {
            $tags[] = 'lost_after_roshan';
        }

        return new SemanticTagSet(activeTags: $tags);
    }

    /**
     * Check if a Roshan kill exists and the gold advantage turned negative
     * within 10 minutes after it.
     */
    private function detectLostAfterRoshan(ObjectiveFlow $objectiveFlow, NormalizedMatch $match): bool
    {
        $goldAdv = $match->goldAdvantage;

        if (empty($goldAdv)) {
            return false;
        }

        foreach ($objectiveFlow->events as $event) {
            if ($event->type !== 'roshan') {
                continue;
            }

            // Check if member team took the Roshan (only relevant if we had it)
            if ($event->team !== $match->memberTeam) {
                continue;
            }

            $roshanMinute = (int) floor($event->time / 60);
            $windowEnd = min($roshanMinute + 10, count($goldAdv) - 1);

            // Check if gold advantage was positive at roshan time but negative within 10 minutes
            $goldAtRoshan = $goldAdv[$roshanMinute] ?? 0;
            $memberPerspective = $match->memberTeam === 'radiant' ? 1 : -1;

            if ($goldAtRoshan * $memberPerspective <= 0) {
                continue;
            }

            for ($i = $roshanMinute + 1; $i <= $windowEnd; $i++) {
                $goldAtMinute = ($goldAdv[$i] ?? 0) * $memberPerspective;
                if ($goldAtMinute < -1000) {
                    return true;
                }
            }
        }

        return false;
    }
}
