<?php

namespace App\Services\Analysis\Metrics;

use App\DataObjects\Analysis\NormalizedMatch;
use App\DataObjects\Analysis\ScalingSummaryDescriptor;
use App\Enums\ScalingPhase;
use App\Enums\ScalingTrendPattern;

class ScalingSummaryBuilder
{
    private const AHEAD_THRESHOLD = 1500;

    private const BEHIND_THRESHOLD = -1500;

    public function build(NormalizedMatch $match): ScalingSummaryDescriptor
    {
        // goldAdvantage is always radiant-perspective; negate for dire member teams
        $goldAdv = $match->goldAdvantage;

        if ($match->memberTeam === 'dire') {
            $goldAdv = array_map(fn (int $v) => -$v, $goldAdv);
        }

        $earlyAvg = $this->segmentAverage($goldAdv, 0, 14);
        $midAvg = $this->segmentAverage($goldAdv, 15, 29);
        $lateAvg = $this->segmentAverage($goldAdv, 30, PHP_INT_MAX);

        $earlyPhase = $this->classifyPhase($earlyAvg);
        $midPhase = $this->classifyPhase($midAvg);
        $latePhase = $this->classifyPhase($lateAvg);

        $collapseMinute = $this->findCollapseMinute($goldAdv);
        $trendPattern = $this->classifyTrendPattern($earlyPhase, $midPhase, $latePhase);

        return new ScalingSummaryDescriptor(
            earlyPhase: $earlyPhase,
            midPhase: $midPhase,
            latePhase: $latePhase,
            collapseMinute: $collapseMinute,
            trendPattern: $trendPattern,
        );
    }

    private function segmentAverage(array $goldAdv, int $minStart, int $minEnd): float
    {
        $slice = [];

        foreach ($goldAdv as $minute => $value) {
            if ($minute >= $minStart && $minute <= $minEnd) {
                $slice[] = $value;
            }
        }

        return empty($slice) ? 0.0 : array_sum($slice) / count($slice);
    }

    private function classifyPhase(float $avg): ScalingPhase
    {
        if ($avg > self::AHEAD_THRESHOLD) {
            return ScalingPhase::Ahead;
        }

        if ($avg < self::BEHIND_THRESHOLD) {
            return ScalingPhase::Behind;
        }

        return ScalingPhase::Even;
    }

    /**
     * Returns the first minute where gold crosses from positive to negative after having been positive.
     */
    private function findCollapseMinute(array $goldAdv): ?int
    {
        $wasPositive = false;

        foreach ($goldAdv as $minute => $value) {
            if (! $wasPositive && $value > 0) {
                $wasPositive = true;
            }

            if ($wasPositive && $value < 0) {
                return (int) $minute;
            }
        }

        return null;
    }

    private function classifyTrendPattern(ScalingPhase $early, ScalingPhase $mid, ScalingPhase $late): ScalingTrendPattern
    {
        // NeverAhead: started already behind
        if ($early === ScalingPhase::Behind) {
            // Check if comeback happened mid
            if ($mid === ScalingPhase::Ahead && $late === ScalingPhase::Behind) {
                return ScalingTrendPattern::ComebackAttempt;
            }

            return ScalingTrendPattern::NeverAhead;
        }

        // EarlyThrow: had a lead, collapsed
        if ($early === ScalingPhase::Ahead && $late === ScalingPhase::Behind) {
            return ScalingTrendPattern::EarlyThrow;
        }

        // SteadyLead: held lead or stayed even throughout
        if ($late !== ScalingPhase::Behind) {
            return ScalingTrendPattern::SteadyLead;
        }

        // BleedOut: gradual decline (Even → Behind)
        return ScalingTrendPattern::BleedOut;
    }
}
