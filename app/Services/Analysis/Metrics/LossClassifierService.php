<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\DerivedMetrics;
use App\DataObjects\Analysis\LossClassification;
use App\DataObjects\Analysis\SemanticTagSet;
use App\Enums\LossType;

class LossClassifierService
{
    /**
     * Classify the dominant reason for a match loss using a deterministic
     * priority-ordered rule chain. Returns exactly one LossType.
     */
    public function classify(DerivedMetrics $metrics, SemanticTagSet $tags): LossClassification
    {
        // 1. Protection failure: core dying early with high damage dependency
        if ($metrics->protectionIndex < 0.30 && $metrics->damageConcentrationRatio > 0.50) {
            return new LossClassification(
                lossType: LossType::ProtectionFailure,
                rationale: 'Core player died in early fights while carrying most team damage',
                confidence: round(
                    $this->signalStrength(1.0 - $metrics->protectionIndex, 0.70) * 0.6
                    + $this->signalStrength($metrics->damageConcentrationRatio, 0.50) * 0.4,
                    3
                ),
            );
        }

        // 2. Pickoff collapse: most member deaths happened outside fights
        if ($metrics->enemyPickoffRate > 0.40) {
            return new LossClassification(
                lossType: LossType::PickoffCollapse,
                rationale: 'Members were repeatedly killed outside of teamfight windows',
                confidence: $this->signalStrength($metrics->enemyPickoffRate, 0.40),
            );
        }

        // 3. Outscaled: member team had mid-game lead that reversed late
        if ($metrics->scalingMidGold > 0 && $metrics->scalingLateGold < -2000) {
            return new LossClassification(
                lossType: LossType::Outscaled,
                rationale: 'Team held a mid-game gold lead but was outscaled in the late game',
                confidence: round(min(1.0, abs($metrics->scalingLateGold) / 6000), 3),
            );
        }

        // 4. Poor conversion: winning fights but not taking objectives
        if ($metrics->fightControlRatio > 0.50 && $metrics->objectiveConversionRate < 0.30) {
            return new LossClassification(
                lossType: LossType::PoorConversion,
                rationale: 'Team won most fights but failed to convert wins into objectives',
                confidence: round(
                    $this->signalStrength($metrics->fightControlRatio, 0.50) * 0.5
                    + $this->signalStrength(1.0 - $metrics->objectiveConversionRate, 0.70) * 0.5,
                    3
                ),
            );
        }

        // 5. Execution loss: consistently losing teamfights
        if ($metrics->fightControlRatio < 0.40) {
            return new LossClassification(
                lossType: LossType::ExecutionLoss,
                rationale: 'Team consistently lost teamfight engagements',
                confidence: $this->signalStrength(1.0 - $metrics->fightControlRatio, 0.60),
            );
        }

        // 6. Outdrafted: default fallback — no dominant signal detected
        return new LossClassification(
            lossType: LossType::Outdrafted,
            rationale: 'No dominant signal detected — likely draft or macro disadvantage',
            confidence: 0.5,
        );
    }

    /**
     * Measure how strongly a value exceeds a threshold, mapped to [0..1].
     * A value exactly at threshold returns 0.5; fully above or below saturates at 1.0 or 0.0.
     */
    private function signalStrength(float $value, float $threshold): float
    {
        $deviation = $value - $threshold;

        return round(min(1.0, max(0.0, 0.5 + $deviation * 2.0)), 3);
    }
}
